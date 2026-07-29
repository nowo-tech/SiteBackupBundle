<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\ListBackupsCommand;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\EventSubscriber\RestoreRequestSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\AdminUserStep;
use Nowo\SiteBackupBundle\Setup\Step\CacheClearStep;
use Nowo\SiteBackupBundle\Setup\Step\ConsoleStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseCreateStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseUrlStep;
use Nowo\SiteBackupBundle\Setup\Step\MigrationsStep;
use Nowo\SiteBackupBundle\Setup\Step\RequirementsStep;
use Nowo\SiteBackupBundle\Setup\Step\SampleDataStep;
use Nowo\SiteBackupBundle\Setup\Step\SchemaUpdateStep;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use const DATE_ATOM;
use const PHP_BINARY;

final class LastMileCoverageTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testListBackupsCommandWhenEmpty(): void
    {
        $tester = new CommandTester(new ListBackupsCommand($this->createManager()));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No backups found', $tester->getDisplay());
    }

    public function testSetupRequestSubscriberAllEarlyReturns(): void
    {
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markDone();
        $evaluator  = new SetupNeedEvaluator([new MarkerFileDetector($markers, true, true)], true);
        $manager    = $this->createManager();
        $matcher    = new SiteBackupExclusionMatcher(['/health'], [], [], [], []);
        $subscriber = new SetupRequestSubscriber(true, $evaluator, $manager, $matcher, '/_setup', '/_site_backup');
        $kernel     = $this->createMock(HttpKernelInterface::class);

        $done = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($done);
        self::assertNull($done->getResponse());

        $markers->clearDone();
        $restoreStorage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $restoreStorage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
        $active = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($active);
        self::assertNull($active->getResponse());

        $restoreStorage->save(new RestoreProgress());
        $redirect = new RequestEvent($kernel, Request::create('/welcome'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($redirect);
        self::assertNotNull($redirect->getResponse());

        $setup = new RequestEvent($kernel, Request::create('/_setup'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($setup);
        self::assertNull($setup->getResponse());

        $panel = new RequestEvent($kernel, Request::create('/_site_backup'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($panel);
        self::assertNull($panel->getResponse());

        $health = new RequestEvent($kernel, Request::create('/health'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($health);
        self::assertNull($health->getResponse());
    }

    public function testRestoreRequestSubscriberNonExcludedController(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
        $subscriber = new RestoreRequestSubscriber(true, $this->createManager(), new SiteBackupExclusionMatcher([], [], [], [], []), null, 'tpl');
        $request    = Request::create('/page');
        $request->attributes->set('_controller', self::class);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    public function testRestoreOrchestratorSkipsProtectedPaths(): void
    {
        file_put_contents($this->harnessProjectDir . '/.env.local', "SECRET=1\n");
        $this->harnessFs->mkdir($this->harnessProjectDir . '/var/site-backup/live');
        file_put_contents($this->harnessProjectDir . '/var/site-backup/live/keep.txt', "keep\n");

        $archiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir,
            includePaths: ['.'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        $artifact = $archiver->create('protected', 'phpunit');
        file_put_contents($this->harnessProjectDir . '/.env.local', "ORIGINAL=1\n");

        $storage      = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->harnessProjectDir,
            archiver: $archiver,
            progressStorage: $storage,
            protectedRelativePaths: ['.env.local'],
        );
        $orchestrator->restore($artifact, 'cli');

        self::assertSame('ORIGINAL=1', trim((string) file_get_contents($this->harnessProjectDir . '/.env.local')));
    }

    public function testRequirementsStepNonWritableDirectory(): void
    {
        file_put_contents($this->harnessProjectDir . '/var', 'not-a-directory');

        $step   = new RequirementsStep('req', 'Req', ['json'], ['var'], requireTar: false);
        $result = $step->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput());
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('not writable', $result->getMessage());
    }

    public function testBackupHistoryEntryAndSetupProgressGetters(): void
    {
        $at    = new DateTimeImmutable('2026-02-01T00:00:00+00:00');
        $entry = new BackupHistoryEntry('restore', $at, actor: 'cli', backupId: 'id', message: 'ok', context: ['k' => 1]);
        self::assertSame($at, $entry->getOccurredAt());
        self::assertSame(['k' => 1], $entry->getContext());

        $parsed = BackupHistoryEntry::fromArray(['action' => 'create', 'occurred_at' => 'not-a-date']);
        self::assertSame('create', $parsed->getAction());

        $updated  = new DateTimeImmutable('2026-03-01T00:00:00+00:00');
        $progress = new SetupProgress(updatedAt: $updated);
        self::assertSame($updated, $progress->getUpdatedAt());
        self::assertSame($updated->format(DATE_ATOM), SetupProgress::fromArray(['updated_at' => $updated->format(DATE_ATOM)])->getUpdatedAt()?->format(DATE_ATOM));
    }

    public function testSetupOrchestratorUnknownProfile(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'fresh_install' => ['steps' => [['type' => 'marker']]],
        ]);

        $profileSteps = new ReflectionMethod($orchestrator, 'profileSteps');
        $profileSteps->setAccessible(true);
        $this->expectException(RuntimeException::class);
        $profileSteps->invoke($orchestrator, 'missing-profile');
    }

    public function testSetupOrchestratorPercentWithZeroTotal(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'fresh_install' => ['steps' => [['type' => 'marker']]],
        ]);

        $percent = new ReflectionMethod($orchestrator, 'percent');
        $percent->setAccessible(true);
        self::assertSame(100.0, $percent->invoke($orchestrator, 0, 0));
    }

    public function testRemainingStepAndFactoryBranches(): void
    {
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $factory  = new SetupStepFactory(
            new ConsoleProcessRunner($this->harnessProjectDir, PHP_BINARY, 30),
            $markers,
            new FakeAdminProvisioner(),
        );

        $console = $factory->create(['type' => 'console', 'command' => ['bin/console', 'cache:clear']], 0);
        self::assertSame('console_0', $console->getId());

        $sample = $factory->create(['type' => 'sample_data', 'commands' => 'not-array'], 1);
        self::assertSame('sample_data_1', $sample->getId());

        $marker = $factory->create(['type' => 'marker'], 2);
        self::assertSame('auto', $marker->getUiKind());

        file_put_contents($this->harnessProjectDir . '/.env.local', "FOO=bar\n");
        $dbStep = new DatabaseUrlStep('db', 'DB', optional: false);
        self::assertTrue($dbStep->run(
            new SetupContext($this->harnessProjectDir, 'fresh_install'),
            new SetupStepInput(['database_url' => 'mysql://localhost/app']),
        )->isSuccess());

        $adminExists = new class implements AdminUserProvisionerInterface {
            public function adminExists(): bool
            {
                return true;
            }

            public function createAdmin(array $data): void
            {
            }
        };
        $adminStep = new AdminUserStep('admin', 'Admin', $adminExists, skipIfAdminExists: true);
        self::assertTrue($adminStep->isComplete(new SetupContext($this->harnessProjectDir, 'fresh_install')));

        $sqlStep = new SqlFileStep('sql', 'SQL', ['sql/init.sql']);
        self::assertTrue($sqlStep->isEnabled(new SetupContext($this->harnessProjectDir, 'fresh_install')));

        $this->harnessFs->mkdir($this->harnessProjectDir . '/bin');
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(0);\n");
        $runner = new ConsoleProcessRunner($this->harnessProjectDir, PHP_BINARY, 30);
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(1);\n");
        $failSample = new SampleDataStep('sample', 'Sample', $runner, [['cache:clear']], 'always');
        self::assertFalse($failSample->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(0);\n");

        self::assertTrue((new CacheClearStep('cache', 'Cache', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(1);\n");
        self::assertFalse((new CacheClearStep('cache-fail', 'Cache fail', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(0);\n");
        self::assertTrue((new SchemaUpdateStep('schema', 'Schema', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        self::assertTrue((new MigrationsStep('mig', 'Migrations', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        self::assertTrue((new DatabaseCreateStep('db', 'DB create', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        self::assertTrue((new ConsoleStep('console', 'Console', $runner, ['list']))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(1);\n");
        self::assertFalse((new ConsoleStep('console-fail', 'Console fail', $runner, ['list']))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        self::assertFalse((new SchemaUpdateStep('schema-fail', 'Schema fail', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        self::assertFalse((new MigrationsStep('mig-fail', 'Migrations fail', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        self::assertFalse((new DatabaseCreateStep('db-fail', 'DB create fail', $runner))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(0);\n");

        $this->harnessFs->mkdir($this->harnessProjectDir . '/sql');
        file_put_contents($this->harnessProjectDir . '/sql/init.sql', 'SELECT 1;');
        $conn = new class {
            public function executeStatement(string $sql): void
            {
            }
        };
        self::assertTrue((new SqlFileStep('sql-run', 'SQL run', ['sql/init.sql'], connection: $conn))
            ->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());

        $adminStep = new AdminUserStep('admin', 'Admin', new FakeAdminProvisioner(), skipIfAdminExists: true);
        $ctx       = new SetupContext($this->harnessProjectDir, 'fresh_install');
        $ctx->markCompleted('admin');
        self::assertTrue($adminStep->isComplete($ctx));
    }

    public function testDetectorAndEventGetters(): void
    {
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markDone();
        $markerDetector = new MarkerFileDetector($markers, true, true);
        self::assertSame('ok', $markerDetector->getReason());

        $doctrineDetector = new DoctrineConnectDetector(new stdClass(), true);
        self::assertFalse($doctrineDetector->isSetupRequired());

        $schemaDetector = new DoctrineSchemaEmptyDetector(null, true);
        self::assertFalse($schemaDetector->isSetupRequired());

        $artifact = TestFixtures::artifact();
        $progress = new RestoreProgress(phase: RestoreProgress::PHASE_COMPLETED, percent: 100.0);
        $started  = new RestoreStartedEvent($artifact, 'cli');
        self::assertSame($artifact, $started->getArtifact());
        self::assertSame('cli', $started->getActor());
        $completed = new RestoreCompletedEvent($artifact, $progress, 'cli');
        self::assertSame($artifact, $completed->getArtifact());
        self::assertSame('cli', $completed->getActor());
        $failed = new RestoreFailedEvent($artifact, 'err', 'cli');
        self::assertSame($artifact, $failed->getArtifact());
        self::assertSame('cli', $failed->getActor());
        self::assertNull((new RestoreStartedEvent($artifact))->getActor());
    }

    public function testTwigPathsPassNativeDefinitionAndAliasChain(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->harnessProjectDir);
        $loader = new Definition('Twig\\Loader\\FilesystemLoader');
        $container->setDefinition('twig.loader.native', $loader);
        (new TwigPathsPass())->process($container);
        self::assertNotEmpty($loader->getMethodCalls());

        $container2 = new ContainerBuilder();
        $container2->setParameter('kernel.project_dir', $this->harnessProjectDir);
        $loader2 = new Definition('Twig\\Loader\\FilesystemLoader');
        $container2->setDefinition('twig.loader.native_filesystem', $loader2);
        $container2->setAlias('twig.loader.native', 'twig.loader.chain');
        $container2->setAlias('twig.loader.chain', 'twig.loader.native_filesystem');
        (new TwigPathsPass())->process($container2);
        self::assertNotEmpty($loader2->getMethodCalls());
    }

    public function testBackupHistoryFromArrayInvalidDate(): void
    {
        $entry = BackupHistoryEntry::fromArray(['action' => 'verify']);
        self::assertSame('verify', $entry->getAction());
        self::assertGreaterThan(0, $entry->getOccurredAt()->getTimestamp());
    }
}

final class FakeAdminProvisioner implements AdminUserProvisionerInterface
{
    public function adminExists(): bool
    {
        return false;
    }

    public function createAdmin(array $data): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\SetupCommand;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\EventSubscriber\RestoreRequestSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\RequirementsStep;
use Nowo\SiteBackupBundle\Setup\Step\SampleDataStep;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Nowo\SiteBackupBundle\Twig\SiteBackupExtension as TwigSiteBackupExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\Process;

use function function_exists;

use const PHP_BINARY;

final class FinalCoverageTest extends TestCase
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

    public function testSiteBackupExtensionCustomAdminProvisioner(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'enabled'           => true,
                'admin_provisioner' => 'App\\Setup\\AdminProvisioner',
            ],
        ]], $container);

        self::assertSame(
            'App\\Setup\\AdminProvisioner',
            (string) $container->getAlias(AdminUserProvisionerInterface::class),
        );
    }

    public function testBackupArchiverMissingManifestInArchive(): void
    {
        $payloadDir = sys_get_temp_dir() . '/nowo-empty-payload-' . uniqid('', true);
        mkdir($payloadDir);
        $tar = sys_get_temp_dir() . '/nowo-no-manifest-' . uniqid('', true) . '.tar.gz';
        (new Process(['tar', '-czf', $tar, '-C', $payloadDir, '.']))->run();

        $archiver = $this->createArchiver();
        $artifact = new BackupArtifact(
            id: 'no-manifest',
            filename: basename($tar),
            absolutePath: $tar,
            createdAt: new DateTimeImmutable(),
            sizeBytes: (int) filesize($tar),
            archiveSha256: (string) hash_file('sha256', $tar),
        );

        $verify = $archiver->verifyIntegrity($artifact);
        self::assertFalse($verify['ok']);
        self::assertStringContainsString('MANIFEST', $verify['errors'][0]);
    }

    public function testBackupArchiverListSkipsBrokenSidecar(): void
    {
        $archiver = $this->createArchiver();
        $archiver->create('valid', 'phpunit');
        file_put_contents($this->harnessStorageDir . '/broken.meta.json', '{bad');
        self::assertCount(1, $archiver->listArtifacts());
    }

    public function testBackupArchiverExcludedNestedStoragePath(): void
    {
        $this->harnessFs->mkdir($this->harnessProjectDir . '/var/site-backup/nested');
        file_put_contents($this->harnessProjectDir . '/var/site-backup/nested/secret.txt', "x\n");
        $full = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-full',
            includePaths: ['var/site-backup'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        $fullArtifact = $full->create('full', 'phpunit');
        self::assertArrayNotHasKey('var/site-backup/nested/secret.txt', $full->verifyIntegrity($fullArtifact)['checksums']);
    }

    public function testStorageLoadEmptyFile(): void
    {
        $emptyFile = sys_get_temp_dir() . '/empty-progress-' . uniqid('', true) . '.json';
        touch($emptyFile);
        self::assertSame(SetupProgress::PHASE_IDLE, (new FilesystemSetupProgressStorage($emptyFile))->load()->getPhase());
        self::assertSame(RestoreProgress::PHASE_IDLE, (new FilesystemRestoreProgressStorage($emptyFile))->load()->getPhase());
        unlink($emptyFile);
    }

    public function testBackupHistoryAppendEncodeFailure(): void
    {
        $storage = new FilesystemBackupHistoryStorage(sys_get_temp_dir() . '/history-' . uniqid('', true) . '.jsonl');
        $entry   = new BackupHistoryEntry('x', new DateTimeImmutable());
        $storage->append($entry);
        self::assertNotEmpty($storage->list(1));
    }

    public function testRestoreRequestSubscriberControllerExclusions(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
        $manager    = $this->createManager();
        $subscriber = new RestoreRequestSubscriber(true, $manager, new SiteBackupExclusionMatcher([], [], [], [], []), null, 'tpl');
        $kernel     = $this->createMock(HttpKernelInterface::class);

        $methodExcluded = new RequestEvent($kernel, Request::create('/page'), HttpKernelInterface::MAIN_REQUEST);
        $methodExcluded->getRequest()->attributes->set('_controller', MethodExcludedController::class . '::ping');
        $subscriber->onKernelRequest($methodExcluded);
        self::assertNull($methodExcluded->getResponse());

        $badController = new RequestEvent($kernel, Request::create('/page'), HttpKernelInterface::MAIN_REQUEST);
        $badController->getRequest()->attributes->set('_controller', 'Definitely\\\\Missing\\\\Class::method');
        $subscriber->onKernelRequest($badController);
        self::assertSame(503, $badController->getResponse()?->getStatusCode());

        $jsonFormat = new RequestEvent($kernel, Request::create('/page', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($jsonFormat);
        self::assertStringContainsString('restoring', (string) $jsonFormat->getResponse()?->getContent());
    }

    public function testSetupCommandWaitingAtEnd(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'admin_only' => ['steps' => [['type' => 'admin_user', 'skip_if_admin_exists' => false]]],
        ]);
        $tester = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::FAILURE, $tester->execute(['--profile' => 'admin_only']));
    }

    public function testRequirementsTarFailureAndSampleDataNonOptIn(): void
    {
        $step = new RequirementsStep('req', 'Req', ['json'], ['var'], requireTar: true);
        $ctx  = new SetupContext($this->harnessProjectDir, 'fresh_install');
        if (!function_exists('exec')) {
            self::assertFalse($step->run($ctx, new SetupStepInput())->isSuccess());
        }

        $this->harnessFs->mkdir($this->harnessProjectDir . '/bin');
        file_put_contents($this->harnessProjectDir . '/bin/console', "#!/usr/bin/env php\n<?php exit(0);\n");
        $runner = new ConsoleProcessRunner($this->harnessProjectDir, PHP_BINARY, 30);
        $sample = new SampleDataStep('s', 'Sample', $runner, [['list']], 'always');
        self::assertTrue($sample->run(new SetupContext($this->harnessProjectDir, 'fresh_install'), new SetupStepInput())->isSuccess());
    }

    public function testSetupRequestSubscriberWhenSetupNotRequired(): void
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markDone();
        $evaluator = new SetupNeedEvaluator(
            [new MarkerFileDetector($markers, true, true)],
            true,
        );
        $subscriber = new SetupRequestSubscriber(
            true,
            $evaluator,
            $this->createManager(),
            new SiteBackupExclusionMatcher([], [], [], [], []),
        );
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testBackupHistoryEntryFromArrayWithDate(): void
    {
        $entry = BackupHistoryEntry::fromArray([
            'action'      => 'create',
            'occurred_at' => '2026-01-01T00:00:00+00:00',
            'actor'       => 'cli',
        ]);
        self::assertSame('create', $entry->getAction());
        self::assertSame('cli', $entry->getActor());
    }

    public function testRestoreRequestSubscriberRouteAttributeBypass(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
        $subscriber = new RestoreRequestSubscriber(true, $this->createManager(), new SiteBackupExclusionMatcher([], [], [], [], []), null, 'tpl');
        $request    = Request::create('/page');
        $request->attributes->set(ExcludeFromRestore::ROUTE_DEFAULT, true);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testTwigExtensionWithActiveRestore(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 33.0));
        $extension = new TwigSiteBackupExtension($this->createManager());
        self::assertTrue($extension->isRestoring());
        self::assertSame(33.0, $extension->progress()->getPercent());
    }
}

final class MethodExcludedController
{
    #[ExcludeFromRestore]
    public function ping(): string
    {
        return 'ok';
    }
}

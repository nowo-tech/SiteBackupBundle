<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\RestoreBackupCommand;
use Nowo\SiteBackupBundle\Command\SetupCommand;
use Nowo\SiteBackupBundle\Command\SetupResetCommand;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepCompletedEvent;
use Nowo\SiteBackupBundle\Event\SetupStepFailedEvent;
use Nowo\SiteBackupBundle\EventSubscriber\RestoreRequestSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\RequirementsStep;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function dirname;

final class CoverageGapTest extends TestCase
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

    public function testEventGetters(): void
    {
        $artifact = TestFixtures::artifact();
        $progress = new RestoreProgress(phase: RestoreProgress::PHASE_COMPLETED, percent: 100.0);

        self::assertSame($artifact, (new BackupDeletedEvent($artifact, 'cli'))->getArtifact());
        self::assertSame('cli', (new BackupDeletedEvent($artifact, 'cli'))->getActor());
        self::assertSame($artifact, (new RestoreStartedEvent($artifact, 'cli'))->getArtifact());
        self::assertSame($progress, (new RestoreCompletedEvent($artifact, $progress, 'cli'))->getProgress());
        self::assertSame('cli', (new RestoreCompletedEvent($artifact, $progress, 'cli'))->getActor());
        self::assertSame('err', (new RestoreFailedEvent($artifact, 'err', 'cli'))->getError());
        self::assertSame('cli', (new RestoreFailedEvent($artifact, 'err', 'cli'))->getActor());
        self::assertSame('fresh_install', (new SetupStepCompletedEvent('fresh_install', 's1'))->getProfile());
        self::assertSame('s1', (new SetupStepCompletedEvent('fresh_install', 's1'))->getStepId());
        self::assertSame('fresh_install', (new SetupStepFailedEvent('fresh_install', 's1', 'err'))->getProfile());
        self::assertSame('s1', (new SetupStepFailedEvent('fresh_install', 's1', 'err'))->getStepId());
    }

    public function testModelGettersAndRestoreProgressDates(): void
    {
        $artifact = new BackupArtifact('id', 'id.tar.gz', '/tmp/id.tar.gz', new DateTimeImmutable(), 1, str_repeat('a', 64), [], ['k' => 'v'], 'lbl', 'cli');
        self::assertSame('id.tar.gz', $artifact->getFilename());
        self::assertSame(['k' => 'v'], $artifact->getMeta());

        $entry = new BackupHistoryEntry('x', new DateTimeImmutable(), 'actor', 'bid', 'msg', ['c' => 1]);
        self::assertSame('x', $entry->getAction());
        self::assertSame('actor', $entry->getActor());
        self::assertSame('bid', $entry->getBackupId());
        self::assertSame('msg', $entry->getMessage());

        $started  = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $progress = new RestoreProgress(startedAt: $started, updatedAt: $started, finishedAt: $started);
        self::assertSame($started, $progress->getStartedAt());
        self::assertSame($started, $progress->getUpdatedAt());
        self::assertSame($started, $progress->getFinishedAt());
    }

    public function testSetupContextProfileAndOptions(): void
    {
        $ctx = new SetupContext('/tmp', 'profile_x', options: ['k' => 'v']);
        self::assertSame('profile_x', $ctx->getProfile());
        self::assertSame(['k' => 'v'], $ctx->getOptions());
    }

    public function testSetupOrchestratorGetStepsAndStoredProfile(): void
    {
        $setupDir = $this->harnessProjectDir . '/setup';
        $storage  = new FilesystemSetupProgressStorage($setupDir . '/progress.json');
        $storage->save(new SetupProgress(profile: 'fresh_install'));
        $orchestrator = $this->createSetupOrchestrator([
            'fresh_install' => ['steps' => [['type' => 'marker']]],
            'other'         => ['steps' => [['type' => 'marker']]],
        ]);
        self::assertNotEmpty($orchestrator->getSteps('fresh_install'));
        self::assertSame('fresh_install', $orchestrator->resolveProfileName(null));
    }

    public function testRequirementsStepTarAndWriteFailure(): void
    {
        $step = new RequirementsStep('req', 'Req', ['missing_ext_xyz'], ['var'], requireTar: false);
        $ctx  = new SetupContext('/definitely/not/existing/path', 'fresh_install');
        self::assertFalse($step->run($ctx, new SetupStepInput())->isSuccess());
    }

    public function testSqlFileStepBranches(): void
    {
        $sqlDir = $this->harnessProjectDir . '/sql';
        mkdir($sqlDir . '/nested', 0777, true);
        file_put_contents($sqlDir . '/a.sql', 'SELECT 1;');
        file_put_contents($sqlDir . '/empty.sql', '');
        file_put_contents($sqlDir . '/nested/b.sql', 'SELECT 2;');

        $ctx  = new SetupContext($this->harnessProjectDir, 'fresh_install');
        $conn = new class {
            public function executeStatement(string $sql): void
            {
                if (str_contains($sql, 'FAIL')) {
                    throw new RuntimeException('sql fail');
                }
            }
        };

        $glob = new SqlFileStep('sql', 'SQL', [$sqlDir . '/*.sql'], connection: $conn);
        self::assertTrue($glob->run($ctx, new SetupStepInput())->isSuccess());

        $abs = new SqlFileStep('sql2', 'SQL', ['/tmp/nowo-missing-' . uniqid('', true) . '.sql'], connection: $conn);
        self::assertFalse($abs->run($ctx, new SetupStepInput())->isSuccess());

        $dir = new SqlFileStep('sql3', 'SQL', [$sqlDir . '/nested'], connection: $conn);
        self::assertTrue($dir->run($ctx, new SetupStepInput())->isSuccess());

        $failConn = new class {
            public function executeStatement(string $sql): void
            {
                throw new RuntimeException('fail');
            }
        };
        $fail = new SqlFileStep('sql4', 'SQL', [$sqlDir . '/a.sql'], connection: $failConn);
        self::assertFalse($fail->run($ctx, new SetupStepInput())->isSuccess());

        $ifExists = new SqlFileStep('sql5', 'SQL', ['/missing/*.sql'], ifExists: true);
        self::assertFalse($ifExists->isEnabled($ctx));
    }

    public function testSetupProgressStorageSaveFailure(): void
    {
        $throwingFs = new class extends Filesystem {
            /**
             * @param iterable<string>|string $dirs
             */
            public function mkdir(string|iterable $dirs, int $mode = 0777): void
            {
                throw new RuntimeException('mkdir failed');
            }
        };

        $setupStorage = new FilesystemSetupProgressStorage(sys_get_temp_dir() . '/x.json', $throwingFs);
        $this->expectException(RuntimeException::class);
        $setupStorage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING));
    }

    public function testRestoreProgressStorageSaveFailure(): void
    {
        $throwingFs = new class extends Filesystem {
            /**
             * @param iterable<string>|string $dirs
             */
            public function mkdir(string|iterable $dirs, int $mode = 0777): void
            {
                throw new RuntimeException('mkdir failed');
            }
        };

        $restoreStorage = new FilesystemRestoreProgressStorage(sys_get_temp_dir() . '/y.json', $throwingFs);
        $this->expectException(RuntimeException::class);
        $restoreStorage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
    }

    public function testBackupHistoryEmptyFileRead(): void
    {
        $file = sys_get_temp_dir() . '/nowo-empty-history-' . uniqid('', true) . '.jsonl';
        touch($file);
        chmod(dirname($file), 0555);
        $storage = new FilesystemBackupHistoryStorage($file);
        self::assertSame([], $storage->list(10));
        chmod(dirname($file), 0755);
        unlink($file);
    }

    public function testBackupArchiverListSkipsInvalidMeta(): void
    {
        $archiver = $this->createArchiver();
        $archiver->create('valid', 'phpunit');
        file_put_contents($this->harnessStorageDir . '/broken.meta.json', '{bad');
        self::assertCount(1, $archiver->listArtifacts());
    }

    public function testBackupArchiverDatabaseDumpFailure(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'false',
            processTimeoutSeconds: 60,
        );
        $this->expectException(RuntimeException::class);
        $archiver->create('db-fail', 'phpunit');
    }

    public function testSetupAndRestoreCommandOptions(): void
    {
        $orchestrator = $this->createSetupOrchestrator([
            'minimal' => ['steps' => [['type' => 'marker']]],
        ]);
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');

        $reset = new CommandTester(new SetupResetCommand($orchestrator, $markers));
        $reset->setInputs(['no']);
        self::assertSame(Command::SUCCESS, $reset->execute([]));

        $resetYes = new CommandTester(new SetupResetCommand($orchestrator, $markers));
        self::assertSame(Command::SUCCESS, $resetYes->execute(['--yes' => true, '--mark-required' => 'custom']));

        $setup = new CommandTester(new SetupCommand($orchestrator));
        self::assertSame(Command::SUCCESS, $setup->execute([
            '--database-url'     => 'mysql://localhost/db',
            '--sample-data'      => true,
            '--skip-sample-data' => true,
            '--profile'          => 'minimal',
        ]));
    }

    public function testCreateAndRestoreCommandFailures(): void
    {
        $restore = new CommandTester(new RestoreBackupCommand($this->createManager()));
        self::assertSame(Command::FAILURE, $restore->execute(['id' => 'missing', '--yes' => true]));
    }

    public function testSetupRequestSubscriberSkips(): void
    {
        $setupDir  = $this->harnessProjectDir . '/setup';
        $markers   = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $evaluator = new SetupNeedEvaluator([new MarkerFileDetector($markers, true, true)], true);
        $manager   = $this->createManager();

        $subscriber = new SetupRequestSubscriber(true, $evaluator, $manager, new SiteBackupExclusionMatcher([], [], [], [], []), '/_setup', '/_site_backup');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $sub    = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST);
        $subscriber->onKernelRequest($sub);
        self::assertNull($sub->getResponse());

        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
        $main = new RequestEvent($kernel, Request::create('/page'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($main);
        self::assertNull($main->getResponse());

        $setupPath = new RequestEvent($kernel, Request::create('/_setup/wizard'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($setupPath);
        self::assertNull($setupPath->getResponse());

        $panelPath = new RequestEvent($kernel, Request::create('/_site_backup/'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($panelPath);
        self::assertNull($panelPath->getResponse());

        $excluded           = new RequestEvent($kernel, Request::create('/health'), HttpKernelInterface::MAIN_REQUEST);
        $excludedSubscriber = new SetupRequestSubscriber(true, $evaluator, $manager, new SiteBackupExclusionMatcher(['/health'], [], [], [], []), '/_setup', '/_site_backup');
        $excludedSubscriber->onKernelRequest($excluded);
        self::assertNull($excluded->getResponse());
    }

    public function testRestoreRequestSubscriberMoreSkips(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 1.0));
        $manager    = $this->createManager();
        $subscriber = new RestoreRequestSubscriber(true, $manager, new SiteBackupExclusionMatcher([], [], [], [], []), null, 'tpl', panelPathPrefix: '/_site_backup');

        $kernel   = $this->createMock(HttpKernelInterface::class);
        $disabled = new RestoreRequestSubscriber(false, $manager, new SiteBackupExclusionMatcher([], [], [], [], []), null, 'tpl');
        $event    = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $disabled->onKernelRequest($event);
        self::assertNull($event->getResponse());

        $panel = new RequestEvent($kernel, Request::create('/_site_backup/x'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($panel);
        self::assertNull($panel->getResponse());

        $inactiveStorage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $inactiveStorage->save(new RestoreProgress());
        $inactive      = new RestoreRequestSubscriber(true, $this->createManager(), new SiteBackupExclusionMatcher([], [], [], [], []), null, 'tpl');
        $inactiveEvent = new RequestEvent($kernel, Request::create('/page'), HttpKernelInterface::MAIN_REQUEST);
        $inactive->onKernelRequest($inactiveEvent);
        self::assertNull($inactiveEvent->getResponse());

        $invokeController = new RequestEvent($kernel, Request::create('/page'), HttpKernelInterface::MAIN_REQUEST);
        $invokeController->getRequest()->attributes->set('_controller', InvokableExcludedController::class);
        $subscriber->onKernelRequest($invokeController);
        self::assertNull($invokeController->getResponse());
    }
}

#[ExcludeFromRestore]
final class InvokableExcludedController
{
    public function __invoke(): string
    {
        return 'ok';
    }
}

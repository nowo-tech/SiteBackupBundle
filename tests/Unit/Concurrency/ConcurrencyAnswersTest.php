<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Concurrency;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\OperationLockUnavailableException;
use Nowo\SiteBackupBundle\Exception\RestoreStillRunningException;
use Nowo\SiteBackupBundle\Exception\SetupAlreadyRunningException;
use Nowo\SiteBackupBundle\Http\ConflictResponse;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\Controller\CreatesFormFactory;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\Lock\FailingStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function file_put_contents;
use function json_decode;

use const PHP_BINARY;

/**
 * REQ-RUNTIME-003 rule 6 on the bundle's own routes, the setup lock and the "only one" races of the storages.
 */
final class ConcurrencyAnswersTest extends TestCase
{
    use CreatesFormFactory;
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testTheConflictAnswerCarriesTheRetrySafeCode(): void
    {
        $response = ConflictResponse::apply(new Response('x', 422), 409, 0);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        self::assertSame(503, ConflictResponse::apply(new Response(), 503, 30)->getStatusCode());
        // Anything else is the platform default.
        self::assertSame(409, ConflictResponse::apply(new Response(), 500, 30)->getStatusCode());
    }

    public function testThePanelAnswers409WhenAnotherOperationHoldsTheLock(): void
    {
        $manager = $this->createMock(SiteBackupManagerInterface::class);
        $manager->method('createBackup')->willThrowException(new BackupAlreadyRunningException(120));
        $manager->method('restore')->willThrowException(new BackupAlreadyRunningException(90));
        $manager->method('listBackups')->willReturn([]);
        $manager->method('getRestoreProgress')->willReturn(new RestoreProgress());
        $messages = [];

        // Create form: the form comes back with the message, as a 409.
        $create = $this->panel($manager, $messages)->index($this->post(['action' => 'create', '_csrf_token' => 'token']));
        self::assertSame(409, $create->getStatusCode());
        self::assertSame('120', $create->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $create->headers->get('X-Conflict-Code'));
        self::assertStringContainsString('Nothing was changed', (string) ($messages[0]['error'][0] ?? ''));

        // Index actions (restore, delete, clear status).
        $restore = $this->panel($manager, $messages)->index($this->post(['action' => 'restore', 'backup_id' => 'b1', '_csrf_token' => 'token']));
        self::assertSame(409, $restore->getStatusCode());
        self::assertSame('90', $restore->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $restore->headers->get('X-Conflict-Code'));

        // Hosts that keep retrying on 503.
        $legacy = $this->panel($manager, $messages, 503)->index($this->post(['action' => 'restore', 'backup_id' => 'b1', '_csrf_token' => 'token']));
        self::assertSame(503, $legacy->getStatusCode());
        self::assertSame('CONFLICT_001', $legacy->headers->get('X-Conflict-Code'));

        // A failing lock store is not a conflict (audit UT-1): 503, no conflict code.
        $down = $this->createMock(SiteBackupManagerInterface::class);
        $down->method('createBackup')->willThrowException(new OperationLockUnavailableException());
        $down->method('deleteBackup')->willThrowException(new OperationLockUnavailableException());
        $down->method('clearRestoreStatus')->willThrowException(new RestoreStillRunningException());
        $down->method('listBackups')->willReturn([]);
        $down->method('getRestoreProgress')->willReturn(new RestoreProgress());
        foreach (['create', 'delete'] as $action) {
            $unavailable = $this->panel($down, $messages)->index($this->post(['action' => $action, 'backup_id' => 'b1', '_csrf_token' => 'token']));
            self::assertSame(503, $unavailable->getStatusCode(), $action);
            self::assertSame('60', $unavailable->headers->get('Retry-After'));
            self::assertFalse($unavailable->headers->has('X-Conflict-Code'));
        }
        $messages = [];
        $still    = $this->panel($down, $messages)->index($this->post(['action' => 'clear_restore', '_csrf_token' => 'token']));
        self::assertSame(200, $still->getStatusCode());
        self::assertStringContainsString('still running', (string) ($messages[0]['error'][0] ?? ''));

        // Another error is not a conflict.
        $other = $this->createMock(SiteBackupManagerInterface::class);
        $other->method('deleteBackup')->willThrowException(new RuntimeException('Backup "x" not found.'));
        $other->method('listBackups')->willReturn([]);
        $other->method('getRestoreProgress')->willReturn(new RestoreProgress());
        $failed = $this->panel($other, $messages)->index($this->post(['action' => 'delete', 'backup_id' => 'x', '_csrf_token' => 'token']));
        self::assertSame(200, $failed->getStatusCode());
        self::assertFalse($failed->headers->has('X-Conflict-Code'));
    }

    public function testTheWizardAnswers409WhileAnotherSetupRunHoldsTheLock(): void
    {
        $lock         = new BackupOperationLock($this->harnessStorageDir, fileName: '.setup.lock', retryAfterSeconds: 30);
        $orchestrator = $this->orchestrator($lock);
        $controller   = $this->wizard($orchestrator);

        $held = $lock->acquire();

        try {
            $page = $controller->index(Request::create('/?profile=fresh_install&token=tok', 'POST', ['_csrf_token' => 'token']));
            self::assertSame(409, $page->getStatusCode());
            self::assertSame('30', $page->headers->get('Retry-After'));
            self::assertSame('CONFLICT_001', $page->headers->get('X-Conflict-Code'));

            $api = $controller->advanceApi(Request::create('/api/advance', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Setup-Token' => 'tok'], '{}'));
            self::assertSame(409, $api->getStatusCode());
            self::assertSame('CONFLICT_001', $api->headers->get('X-Conflict-Code'));
            self::assertSame(['error' => 'setup_running', 'code' => 'CONFLICT_001'], json_decode((string) $api->getContent(), true));

            // Nothing ran: the progress is untouched.
            self::assertSame(SetupProgress::PHASE_IDLE, $orchestrator->getProgress()->getPhase());

            try {
                $orchestrator->resetProgress();
                self::fail('Expected SetupAlreadyRunningException');
            } catch (SetupAlreadyRunningException $e) {
                self::assertSame(30, $e->getRetryAfterSeconds());
                self::assertInstanceOf(BackupAlreadyRunningException::class, $e->getPrevious());
            }
        } finally {
            $held->release();
        }

        // Once free, a "reset and run" goes through under one lock.
        $done = $controller->index(Request::create('/?profile=fresh_install&token=tok', 'POST', ['_csrf_token' => 'token', 'reset' => '1']));
        self::assertSame(302, $done->getStatusCode());
        self::assertSame(SetupProgress::PHASE_COMPLETED, $orchestrator->getProgress()->getPhase());
        $orchestrator->resetProgress();
        self::assertSame(SetupProgress::PHASE_IDLE, $orchestrator->getProgress()->getPhase());
        $again = $orchestrator->advance('fresh_install', null, true, true);
        self::assertTrue($again->isFinished());
    }

    public function testTheWizardAnswers503WhenTheLockStoreFailsOnAConfiguredInstance(): void
    {
        $lock         = new BackupOperationLock($this->harnessStorageDir, new LockFactory(new FailingStore(save: true)), 'k.setup', 60.0, fileName: '.setup.lock');
        $orchestrator = $this->orchestrator($lock);
        $controller   = $this->wizard($orchestrator);

        $page = $controller->index(Request::create('/?profile=fresh_install&token=tok', 'POST', ['_csrf_token' => 'token']));
        self::assertSame(503, $page->getStatusCode());
        self::assertSame('30', $page->headers->get('Retry-After'));
        self::assertFalse($page->headers->has('X-Conflict-Code'));

        $api = $controller->advanceApi(Request::create('/api/advance', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Setup-Token' => 'tok'], '{}'));
        self::assertSame(503, $api->getStatusCode());
        self::assertSame(['error' => 'lock_unavailable'], json_decode((string) $api->getContent(), true));
        self::assertSame(SetupProgress::PHASE_IDLE, $orchestrator->getProgress()->getPhase(), 'nothing ran');
    }

    public function testTheSqlStepHoldsTheOperationLockForEveryFile(): void
    {
        $project = $this->harnessProjectDir;
        (new Filesystem())->mkdir($project . '/var/site-backup');
        file_put_contents($project . '/var/site-backup/a.sql', "SELECT 1;\n");
        file_put_contents($project . '/var/site-backup/b.sql', "SELECT 2;\n");
        $paths  = ['var/site-backup/a.sql', 'var/site-backup/b.sql'];
        $ctx    = new SetupContext($project, 'post_restore');
        $lock   = new BackupOperationLock($this->harnessStorageDir);
        $marker = $this->harnessStorageDir . '/marker.json';

        $connection = new class {
            public int $calls = 0;

            public function executeStatement(string $sql): int
            {
                ++$this->calls;

                return 0;
            }
        };
        $pdo = new SqlFileStep('sql', 'SQL', $paths, false, $connection, SqlFileStep::IMPORTER_PDO, null, $marker, $lock);

        // A backup holds the lock: nothing is imported, the marker is not written, the step can be retried.
        $held   = $lock->acquire();
        $result = $pdo->run($ctx, new SetupStepInput());
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('SQL import not started', $result->getMessage());
        self::assertSame(0, $connection->calls);
        self::assertFileDoesNotExist($marker);
        $held->release();

        self::assertTrue($pdo->run($ctx, new SetupStepInput())->isSuccess());
        self::assertSame(2, $connection->calls);

        // Client importer: both files under the step's lock (the importer does not take it again).
        $received = $this->harnessStorageDir . '/received.sql';
        $client   = EngineFixtures::client($this->harnessStorageDir, 'fake-mysql', 'cat >> "' . $received . '"');
        $step     = new SqlFileStep('sql', 'SQL', $paths, false, null, SqlFileStep::IMPORTER_CLIENT, new DumpImporter('mysql://u:p@h/d', $client, operationLock: $lock), $marker, $lock);
        self::assertTrue($step->run($ctx, new SetupStepInput())->isSuccess(), $step->run($ctx, new SetupStepInput())->getMessage());
        self::assertStringContainsString('SELECT 2;', (string) @file_get_contents($received));
        $lock->acquire()->release();
    }

    public function testAHeldLockImportStillRefusesAMissingFile(): void
    {
        $lock = new BackupOperationLock($this->harnessStorageDir);
        $held = $lock->acquire();

        try {
            $this->expectException(DumpFailedException::class);
            (new DumpImporter('mysql://u:p@h/d', 'mysql'))->import($this->harnessStorageDir . '/missing.sql', null, $held);
        } finally {
            $held->release();
        }
    }

    public function testClearingTheRestoreStatusNeverDropsARunningRestore(): void
    {
        $storage      = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $orchestrator = new RestoreOrchestrator($this->harnessProjectDir, $this->createArchiver(), $storage, []);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 50.0, updatedAt: new DateTimeImmutable()));

        self::assertFalse($orchestrator->clearFailedOrCompleted());
        self::assertTrue($orchestrator->isRestoreActive(), 'a running restore keeps its progress');

        // Through the manager (audit UT-4): the caller is told, never "cleared".
        $lock    = new BackupOperationLock($this->harnessStorageDir);
        $manager = new SiteBackupManager($this->createArchiver(), $orchestrator, new FilesystemBackupHistoryStorage($this->harnessHistoryFile), operationLock: $lock);

        try {
            $manager->clearRestoreStatus();
            self::fail('Expected RestoreStillRunningException');
        } catch (RestoreStillRunningException) {
        }
        $lock->acquire()->release();

        $storage->save(new RestoreProgress(active: false, phase: RestoreProgress::PHASE_FAILED, error: 'boom'));
        self::assertTrue($orchestrator->clearFailedOrCompleted());
        self::assertSame(RestoreProgress::PHASE_IDLE, $storage->load()->getPhase());

        // The operation lock is taken first.
        $held = $lock->acquire();

        try {
            $manager->clearRestoreStatus();
            self::fail('Expected BackupAlreadyRunningException');
        } catch (BackupAlreadyRunningException) {
        } finally {
            $held->release();
        }
        $manager->clearRestoreStatus();
        $lock->acquire()->release();
    }

    public function testTheProgressRowInsertRaceBecomesAnUpdate(): void
    {
        $connection = new RacingConnection('nowo_site_backup_setup_progress');
        $storage    = new DoctrineDbalSetupProgressStorage($connection, stepRowsEnabled: false);

        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'p'));
        self::assertSame(1, $connection->failedInserts);
        self::assertSame(1, $connection->updates, 'the row another process inserted is updated');

        // A failing insert that left no row is an error.
        $broken = new RacingConnection('nowo_site_backup_setup_progress', insertRow: false);
        $this->expectException(RuntimeException::class);
        (new DoctrineDbalSetupProgressStorage($broken, stepRowsEnabled: false))->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'p'));
    }

    public function testTheStepRowInsertRaceBecomesAnUpdate(): void
    {
        $connection = new RacingConnection('nowo_site_backup_setup_step');
        $journal    = new DoctrineDbalSetupStepJournal($connection, 'nowo_site_backup_setup_step');
        $journal->sync(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'p', currentStepId: 'migrate'));
        self::assertSame(1, $connection->failedInserts);
        self::assertSame(1, $connection->updates);

        $broken = new RacingConnection('nowo_site_backup_setup_step', insertRow: false);
        $this->expectException(RuntimeException::class);
        (new DoctrineDbalSetupStepJournal($broken, 'nowo_site_backup_setup_step'))->sync(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'p', currentStepId: 'migrate'));
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function panel(SiteBackupManagerInterface $manager, array &$messages, int $status = 409): SiteBackupPanelController
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $context) use (&$messages): string {
            $messages[] = $context['messages'] ?? [];

            return 'html';
        });

        return new SiteBackupPanelController($manager, new PasswordSiteBackupAccessGate(null, false), $this->createFormFactory($this->csrf()), $twig, ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'], '/_site_backup', $this->csrf(), conflictStatus: $status);
    }

    private function orchestrator(BackupOperationLock $lock): SetupOrchestrator
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markRequired('fresh_install');

        return new SetupOrchestrator(
            projectDir: $this->harnessProjectDir,
            stepFactory: new SetupStepFactory(new ConsoleProcessRunner($this->harnessProjectDir, PHP_BINARY, 30), $markers, new NullAdminUserProvisioner()),
            progressStorage: new FilesystemSetupProgressStorage($setupDir . '/progress.json'),
            markers: $markers,
            profiles: ['fresh_install' => ['steps' => [['type' => 'marker', 'write_done' => true]]]],
            setupLock: $lock,
        );
    }

    private function wizard(SetupOrchestrator $orchestrator): SetupWizardController
    {
        $setupDir = $this->harnessProjectDir . '/_setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $twig     = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');

        return new SetupWizardController(
            $orchestrator,
            new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true),
            $this->createFormFactory($this->csrf()),
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            'tok',
            $this->csrf(),
            allowWithoutToken: false,
        );
    }

    private function csrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        return $csrf;
    }

    /**
     * @param array<string, string> $data
     */
    private function post(array $data): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}

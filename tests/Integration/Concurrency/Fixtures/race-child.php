<?php

declare(strict_types=1);

/*
 * One racer of {@see Nowo\SiteBackupBundle\Tests\Integration\Concurrency\OperationLockRaceTest}, run as its own PHP
 * process. It waits for the barrier file, runs one operation through the real services and writes a JSON result:
 * {"outcome": "won"|"locked"|"setup_locked"|"error", "start": float, "end": float, "id": ?string, "error": ?string}.
 *
 * Argument: the path of a JSON job file written by the test.
 */

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\SetupAlreadyRunningException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupLockFallbackPolicy;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Nowo\SiteBackupBundle\Setup\Step\AbstractSetupStep;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Integration\Concurrency\OperationLockRaceTest;
use Nowo\SiteBackupBundle\Tests\Unit\Lock\FailingStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\PdoStore;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** @var array{store: string, key: string, delay: float, lock_dir: string, ttl: float, op: string, project_dir: string, storage_dir: string, history: string, progress: string, barrier: string, result: string, dump_seconds: int, fallback?: bool, backup_id?: string, pdo_dsn?: string, pdo_user?: string, pdo_password?: string, setup_dir?: string, setup_log?: string} $job */
$job = json_decode((string) file_get_contents($argv[1]), true, 512, \JSON_THROW_ON_ERROR);

$factory = match ($job['store']) {
    'flock'       => null,
    'flock_store' => new LockFactory(new FlockStore($job['lock_dir'])),
    // A shared store that is down (cold first install: the database is not there yet).
    'failing' => new LockFactory(new FailingStore(save: true)),
    'pdo'     => new LockFactory(new PdoStore(OperationLockRaceTest::pdo($job), ['db_table' => OperationLockRaceTest::PDO_TABLE])),
    default   => throw new InvalidArgumentException('Unknown store ' . $job['store']),
};
$lock = new BackupOperationLock($job['lock_dir'], $factory, $job['key'], $job['ttl']);

$archiver = new BackupArchiver(
    projectDir: $job['project_dir'],
    storageDir: $job['storage_dir'],
    includePaths: ['config'],
    excludePatterns: [],
    // A slow dump keeps the winner inside the lock while the other racer tries.
    databaseDumpCommand: sprintf("sleep %d; echo '-- race dump'", $job['dump_seconds']),
    processTimeoutSeconds: 60,
);
$manager = new SiteBackupManager(
    $archiver,
    new RestoreOrchestrator(
        projectDir: $job['project_dir'],
        archiver: $archiver,
        progressStorage: new FilesystemRestoreProgressStorage($job['progress']),
        protectedRelativePaths: ['.env.local'],
    ),
    new FilesystemBackupHistoryStorage($job['history']),
    // A restore is fast with these fixtures: the listener keeps the winner inside the lock for dump_seconds.
    new class($job['dump_seconds']) implements EventDispatcherInterface {
        public function __construct(private readonly int $seconds)
        {
        }

        public function dispatch(object $event): object
        {
            if ($event instanceof RestoreStartedEvent) {
                sleep($this->seconds);
            }

            return $event;
        }
    },
    operationLock: $lock,
);

$deadline = microtime(true) + 20;
while (!is_file($job['barrier'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('barrier timeout');
    }
    usleep(5_000);
}
if ($job['delay'] > 0) {
    usleep((int) ($job['delay'] * 1_000_000));
}

$result = ['outcome' => 'won', 'start' => microtime(true), 'end' => null, 'id' => null, 'error' => null];

try {
    switch ($job['op']) {
        case 'create':
            $result['id'] = $manager->createBackup('race')->getId();
            break;
        case 'restore':
            $manager->restore((string) ($job['backup_id'] ?? ''), 'race');
            $result['id'] = $job['backup_id'] ?? null;
            break;
        case 'clear_restore':
            $manager->clearRestoreStatus();
            break;
        case 'setup':
            $setupDir = (string) ($job['setup_dir'] ?? '');
            $logFile  = (string) ($job['setup_log'] ?? '');
            $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
            $slow     = new class('slow', 'Slow step', $logFile) extends AbstractSetupStep {
                public function __construct(string $id, string $label, private readonly string $log)
                {
                    parent::__construct($id, $label);
                }

                public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
                {
                    file_put_contents($this->log, getmypid() . " start\n", \FILE_APPEND | \LOCK_EX);
                    sleep(2);
                    file_put_contents($this->log, getmypid() . " end\n", \FILE_APPEND | \LOCK_EX);

                    return SetupStepResult::ok('slow done');
                }
            };
            $orchestrator = new SetupOrchestrator(
                projectDir: $job['project_dir'],
                stepFactory: new SetupStepFactory(new ConsoleProcessRunner($job['project_dir'], \PHP_BINARY, 30), $markers, new NullAdminUserProvisioner(), customSteps: ['race_slow' => $slow]),
                progressStorage: new FilesystemSetupProgressStorage($setupDir . '/progress.json'),
                markers: $markers,
                profiles: ['race' => ['steps' => [['type' => 'race_slow']]]],
                defaultProfile: 'race',
                setupLock: new BackupOperationLock($job['lock_dir'], $factory, $job['key'] . '.setup', $job['ttl'], fileName: '.setup.lock', retryAfterSeconds: 30, localFallback: ($job['fallback'] ?? false) ? new SetupLockFallbackPolicy($markers, null, SetupLockFallbackPolicy::ALWAYS) : null),
            );
            $orchestrator->advance('race', null, true);
            break;
        case 'hold':
            // Holds the lock until killed (crash test).
            $held = $lock->acquire();
            file_put_contents($job['result'] . '.held', '1');
            sleep(30);
            $held->release();
            break;
        default:
            throw new InvalidArgumentException('Unknown op ' . $job['op']);
    }
} catch (BackupAlreadyRunningException $e) {
    $result['outcome'] = 'locked';
    $result['error']   = (string) $e->getRetryAfterSeconds();
} catch (SetupAlreadyRunningException $e) {
    $result['outcome'] = 'setup_locked';
    $result['error']   = (string) $e->getRetryAfterSeconds();
} catch (Throwable $e) {
    $result['outcome'] = 'error';
    $result['error']   = $e::class . ': ' . $e->getMessage();
}
$result['end'] = microtime(true);

file_put_contents($job['result'], json_encode($result, \JSON_THROW_ON_ERROR));

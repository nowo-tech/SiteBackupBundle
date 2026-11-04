<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\CleanupCommand;
use Nowo\SiteBackupBundle\Command\CreateBackupCommand;
use Nowo\SiteBackupBundle\Command\DatabaseDumpCommand;
use Nowo\SiteBackupBundle\Command\DatabaseImportCommand;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function mkdir;

final class EngineCommandsTest extends TestCase
{
    private string $root;
    private string $project;
    private string $storage;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-cmd-');
        $this->project = $this->root . '/project';
        $this->storage = $this->root . '/storage';
        (new Filesystem())->mkdir([$this->project . '/config', $this->project . '/public']);
        file_put_contents($this->project . '/config/app.yaml', "a: b\n");
        file_put_contents($this->project . '/public/index.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testCreateWithOptionsAndJson(): void
    {
        $tester = new CommandTester(new CreateBackupCommand($this->manager()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'zip', '--level' => '1', '--path' => ['public'], '--label' => 'x', '--json' => true]));
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('zip', $result['format']);
        self::assertSame('files', $result['content']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('created', $tester->getDisplay());
    }

    public function testCreateRefusals(): void
    {
        $tester = new CommandTester(new CreateBackupCommand($this->manager()));

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'rar']));
        self::assertSame(Command::INVALID, $tester->execute(['--level' => 'max']));
        self::assertSame(Command::INVALID, $tester->execute(['--no-files' => true, '--no-database' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--skip-table' => ['cache']]), 'table rules need the builtin dumper');
        self::assertSame(Command::INVALID, $tester->execute(['--structure-only' => ['x y']]));
        self::assertSame(Command::FAILURE, (new CommandTester(new CreateBackupCommand($this->manager(dump: 'exit 9'))))->execute([]));
    }

    public function testCreateExits75WhileLocked(): void
    {
        $lock    = new BackupOperationLock($this->storage);
        $tester  = new CommandTester(new CreateBackupCommand($this->manager(lock: $lock)));
        $holding = $lock->acquire();

        try {
            self::assertSame(CreateBackupCommand::EXIT_LOCKED, $tester->execute([]));
        } finally {
            $holding->release();
        }
    }

    public function testCreatePrintsWarnings(): void
    {
        file_put_contents($this->project . '/config/server.key', 'CANARY');
        $tester = new CommandTester(new CreateBackupCommand($this->manager()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--no-database' => true]));
        self::assertStringContainsString('secret path(s) skipped', $tester->getDisplay());
    }

    public function testImportCommand(): void
    {
        $client = EngineFixtures::client($this->root, 'fake-mysql', 'cat > "' . $this->root . '/received.sql"');
        file_put_contents($this->root . '/in.sql', "SELECT 1;\n");
        $tester = new CommandTester(new DatabaseImportCommand(new DumpImporter('mysql://u:p@h/d', $client)));

        self::assertSame(Command::INVALID, $tester->execute(['file' => $this->root . '/in.sql']));
        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->root . '/in.sql', '--force' => true]));
        self::assertSame("SELECT 1;\n", file_get_contents($this->root . '/received.sql'));
        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->root . '/missing.sql', '--force' => true]));
    }

    public function testCleanupCommand(): void
    {
        $work     = $this->root . '/work';
        $archiver = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, workDir: $work);
        mkdir($work);
        mkdir($work . '/nowo-site-backup-leftover');
        $tester = new CommandTester(new CleanupCommand($archiver));

        self::assertSame(Command::INVALID, $tester->execute(['--older-than' => 'soon']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '0']));
        self::assertStringContainsString('1 leftover staging directory removed', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '0']));
        self::assertStringContainsString('0 leftover staging directories removed', $tester->getDisplay());
    }

    public function testDumpCommandTableRules(): void
    {
        $dump   = EngineFixtures::fakeMysqldump($this->root);
        $client = EngineFixtures::script($this->root, 'fake-mysql', <<<'SH'
            case "$*" in
              *TABLE_TYPE*) printf 'user\tBASE TABLE\nevent_log\tBASE TABLE\ncache\tBASE TABLE\n';;
              *REFERENCED_TABLE_NAME*) printf 'event_log\tuser\n';;
            esac
            SH);
        $command = new DatabaseDumpCommand('mysql://app:pw@db/beacon', 30, [], 'doctrine_migration_versions', $client);
        $tester  = new CommandTester($command);

        $code = $tester->execute(['--binary' => $dump, '--structure-only' => ['event_log'], '--ignore-table' => ['cache']], ['capture_stderr_separately' => true]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('--ignore-table=beacon.cache --ignore-table=beacon.event_log -- beacon', $tester->getDisplay());
        self::assertStringContainsString('--no-data -- beacon event_log', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['--binary' => $dump, '--ignore-table' => ['user']], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('foreign keys of event_log', $tester->getErrorOutput());
        self::assertSame(Command::SUCCESS, $tester->execute(['--binary' => $dump, '--ignore-table' => ['user'], '--allow-unsafe-table-rules' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('warning:', $tester->getErrorOutput());
        self::assertSame(Command::INVALID, $tester->execute(['--binary' => $dump, '--ignore-table' => ['a;b']], ['capture_stderr_separately' => true]));

        $failing = EngineFixtures::fakeMysqldump($this->root . '', exit: 5);
        self::assertSame(5, $tester->execute(['--binary' => $failing, '--structure-only' => ['event_log']], ['capture_stderr_separately' => true]));
    }

    public function testSqlFileStepClientImporter(): void
    {
        $client = EngineFixtures::client($this->root, 'fake-mysql', 'cat > "' . $this->root . '/received.sql"');
        (new Filesystem())->mkdir($this->project . '/var/site-backup');
        file_put_contents($this->project . '/' . SqlFileStep::RESTORE_DUMP, "SELECT 1;\n");
        $ctx = new SetupContext($this->project, 'post_restore');

        $step   = new SqlFileStep('sql', 'SQL', [SqlFileStep::RESTORE_DUMP], true, null, SqlFileStep::IMPORTER_CLIENT, new DumpImporter('mysql://u:p@h/d', $client));
        $result = $step->run($ctx, new SetupStepInput());
        self::assertTrue($result->isSuccess());
        self::assertFileDoesNotExist($this->project . '/' . SqlFileStep::RESTORE_DUMP, 'the plaintext restore dump is removed after import');
        self::assertSame("SELECT 1;\n", file_get_contents($this->root . '/received.sql'));

        file_put_contents($this->project . '/' . SqlFileStep::RESTORE_DUMP, "x\n");
        $noImporter = new SqlFileStep('sql', 'SQL', [SqlFileStep::RESTORE_DUMP], true, null, SqlFileStep::IMPORTER_CLIENT);
        self::assertFalse($noImporter->run($ctx, new SetupStepInput())->isSuccess());

        $failingClient = EngineFixtures::client($this->root, 'failing-mysql', 'cat >/dev/null; echo "ERROR 1146 (42S02) at line 1: CANARY" >&2; exit 1');
        $failing       = new SqlFileStep('sql', 'SQL', [SqlFileStep::RESTORE_DUMP], true, null, SqlFileStep::IMPORTER_CLIENT, new DumpImporter('mysql://u:p@h/d', $failingClient));
        $result        = $failing->run($ctx, new SetupStepInput());
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('ERROR 1146 (42S02) at line 1', $result->getMessage());
        self::assertStringNotContainsString('CANARY', $result->getMessage());
    }

    public function testRestoreNeverWritesSecretPathsFromOldArchives(): void
    {
        // A 1.27-style archive that still contains .env and a vault key.
        $payload = $this->root . '/payload';
        (new Filesystem())->mkdir([$payload . '/config/secrets/prod', $payload . '/config']);
        file_put_contents($payload . '/.env', 'CANARY-env');
        file_put_contents($payload . '/config/secrets/prod/key.php', 'CANARY-key');
        file_put_contents($payload . '/config/new.yaml', 'n: 1');
        $checksums = [];
        foreach (['.env', 'config/secrets/prod/key.php', 'config/new.yaml'] as $file) {
            $checksums[$file] = (string) hash_file('sha256', $payload . '/' . $file);
        }
        file_put_contents($payload . '/MANIFEST.json', (string) json_encode(['version' => 1, 'id' => 'old', 'checksums' => $checksums, 'meta' => []]));
        (new Filesystem())->mkdir($this->storage);
        $archive = $this->storage . '/old.tar.gz';
        (new Process(['tar', '-czf', $archive, '-C', $payload, '.']))->mustRun();
        $artifact = new BackupArtifact('old', 'old.tar.gz', $archive, new DateTimeImmutable(), 1, (string) hash_file('sha256', $archive), $checksums);
        file_put_contents($this->storage . '/old.meta.json', (string) json_encode($artifact->toArray()));

        $this->manager()->restore('old');

        self::assertFileExists($this->project . '/config/new.yaml');
        self::assertFileDoesNotExist($this->project . '/.env');
        self::assertFileDoesNotExist($this->project . '/config/secrets/prod/key.php');
    }

    private function manager(?string $dump = null, ?BackupOperationLock $lock = null): SiteBackupManager
    {
        $archiver = new BackupArchiver($this->project, $this->storage, ['config', 'public'], [], $dump, 60, workDir: $this->root . '/work');

        return new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), [], triggerSetupAfterRestore: false),
            historyStorage: new MemoryHistoryStorage(),
            operationLock: $lock,
        );
    }
}

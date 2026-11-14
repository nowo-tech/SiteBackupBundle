<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\DatabaseImportCommand;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Incremental\RestoreMarker;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryHistoryStorage;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function chmod;
use function file_put_contents;
use function fileperms;
use function implode;
use function mkdir;
use function unlink;

/**
 * Audit PP, delta 3 (SiteBackup 1.36.0): the restore marker for every bundle path that writes the database.
 */
final class PpDelta3FixesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = EngineFixtures::tempDir('sbb-pp-d3-');
    }

    protected function tearDown(): void
    {
        @chmod($this->dir . '/ro', 0o700);
        (new Filesystem())->remove($this->dir);
    }

    public function testL2MaskingWarningReachesTheHistory(): void
    {
        $project = $this->dir . '/project';
        (new Filesystem())->mkdir($project . '/config');
        $dump = EngineFixtures::script($this->dir, 'masking-dump', <<<'SH'
            if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done
            echo "mysqldump: Error: 'SELECT command denied to user 'bk'@'h' for table 'column_masking_policy'' when trying to dump masking policies" >&2
            printf -- "CREATE TABLE t (id INT);\n-- Dump completed\n" > "$out"
            SH);
        $archiver = new BackupArchiver($project, $this->dir . '/storage', ['config'], [], null, 60, builtinDumper: new BuiltinMysqlDumper('mysql://bk@db/app', $dump));
        $history  = new MemoryHistoryStorage();
        $manager  = new SiteBackupManager($archiver, new RestoreOrchestrator($project, $archiver, new MemoryRestoreProgressStorage(), []), $history);

        $artifact = $manager->createBackup(new BackupOptions(includeFiles: false));
        self::assertStringContainsString('column masking policies', implode(' ', $artifact->getWarnings()));
        $context = $history->list(1)[0]->getContext();
        self::assertStringContainsString('column masking policies', implode(' ', (array) ($context['warnings'] ?? [])));
    }

    public function testL1DbImportClosesTheChains(): void
    {
        $client = EngineFixtures::client($this->dir, 'fake-mysql', 'cat > "' . $this->dir . '/received.sql"');
        file_put_contents($this->dir . '/in.sql', "SELECT 1;\n");
        // The storage directory does not exist yet (fresh install): it is created owner-only.
        $marker = $this->dir . '/storage/' . RestoreMarker::FILENAME;
        $tester = new CommandTester(new DatabaseImportCommand(new DumpImporter('mysql://u:p@h/d', $client), $marker));

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->dir . '/in.sql', '--force' => true]));
        self::assertNotNull(RestoreMarker::read($marker));
        self::assertSame(0o700, fileperms($this->dir . '/storage') & 0o777);

        // The marker cannot be written: nothing is imported.
        mkdir($this->dir . '/ro', 0o500);
        @unlink($this->dir . '/received.sql');
        $tester = new CommandTester(new DatabaseImportCommand(new DumpImporter('mysql://u:p@h/d', $client), $this->dir . '/ro/storage/' . RestoreMarker::FILENAME));
        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->dir . '/in.sql', '--force' => true]));
        self::assertStringContainsString('Nothing was imported', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir . '/received.sql');
    }

    public function testL1SqlFileStepsCloseTheChains(): void
    {
        $project = $this->dir . '/project';
        (new Filesystem())->mkdir($project . '/var/site-backup');
        $client = EngineFixtures::client($this->dir, 'fake-mysql', 'cat > "' . $this->dir . '/received.sql"');
        $ctx    = new SetupContext($project, 'post_restore');
        $marker = $this->dir . '/storage/' . RestoreMarker::FILENAME;

        file_put_contents($project . '/' . SqlFileStep::RESTORE_DUMP, "SELECT 1;\n");
        $step = new SqlFileStep('sql', 'SQL', [SqlFileStep::RESTORE_DUMP], true, null, SqlFileStep::IMPORTER_CLIENT, new DumpImporter('mysql://u:p@h/d', $client), $marker);
        self::assertTrue($step->run($ctx, new SetupStepInput())->isSuccess());
        self::assertNotNull(RestoreMarker::read($marker));

        // PDO importer too; an unwritable marker fails the step before anything is imported.
        mkdir($this->dir . '/ro', 0o500);
        file_put_contents($project . '/' . SqlFileStep::RESTORE_DUMP, "SELECT 2;\n");
        $connection = new class {
            public int $calls = 0;

            public function executeStatement(string $sql): int
            {
                ++$this->calls;

                return 0;
            }
        };
        $blocked = new SqlFileStep('sql', 'SQL', [SqlFileStep::RESTORE_DUMP], true, $connection, SqlFileStep::IMPORTER_PDO, null, $this->dir . '/ro/storage/' . RestoreMarker::FILENAME);
        $result  = $blocked->run($ctx, new SetupStepInput());
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('restore marker', $result->getMessage());
        self::assertSame(0, $connection->calls);

        $pdo = new SqlFileStep('sql', 'SQL', [SqlFileStep::RESTORE_DUMP], true, $connection, SqlFileStep::IMPORTER_PDO, null, $marker);
        self::assertTrue($pdo->run($ctx, new SetupStepInput())->isSuccess());
        self::assertSame(1, $connection->calls);
    }
}

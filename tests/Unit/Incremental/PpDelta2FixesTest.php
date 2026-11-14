<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use Nowo\SiteBackupBundle\Command\CreateBackupCommand;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Exception\BinlogFilterException;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Incremental\BinlogSchemaFilter;
use Nowo\SiteBackupBundle\Incremental\BinlogStatementClassifier as C;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function end;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_decode;
use function trim;

/**
 * Audit PP, delta 2 (SiteBackup PR #62 at 20f5f2a): red-first tests.
 */
final class PpDelta2FixesTest extends TestCase
{
    private string $dir;

    private int $n = 0;

    protected function setUp(): void
    {
        $this->dir = EngineFixtures::tempDir('sbb-pp-d2-');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testH1GtidOffServersDumpWithoutTheGtidSet(): void
    {
        $base = ['mysqldump', '--single-transaction', '--', 'app'];
        self::assertContains('--set-gtid-purged=OFF', BuiltinMysqlDumper::planCommands($base, 'app', [], true, true, false)[0]);
        self::assertNotContains('--set-gtid-purged=COMMENTED', BuiltinMysqlDumper::planCommands($base, 'app', [], true, true, false)[0]);

        // mysqldump 9.7 against a GTID-off server with COMMENTED: "Error: Server has GTIDs disabled.", exit 0, no table.
        $dump = EngineFixtures::script($this->dir, 'gtid-dump', <<<'SH'
            if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            echo "$*" >> "$(dirname "$0")/dump-args"
            for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done
            case "$*" in
              *COMMENTED*) echo "Error: Server has GTIDs disabled." >&2; printf -- "-- MySQL dump\n-- Dump completed\n" > "$out";;
              *) printf -- "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;\nCREATE TABLE t (id INT);\n-- Dump completed\n" > "$out";;
            esac
            exit 0
            SH);
        $dumper = new BuiltinMysqlDumper('mysql://bk@db/app', $dump);
        $report = $dumper->dump($this->dir . '/off.sql', [], false, null, true, false, false);
        self::assertSame('binlog.000002', $report->binlogCoordinates['file'] ?? null);
        self::assertStringContainsString('--set-gtid-purged=OFF', (string) file_get_contents($this->dir . '/dump-args'));

        try {
            $dumper->dump($this->dir . '/on.sql', [], false, null, true, false, true);
            self::fail('a dump that printed an error was accepted');
        } catch (DumpFailedException $e) {
            self::assertStringContainsString('GTIDs disabled', $e->getMessage());
        }

        // MySQL 9.x: no SELECT on mysql.column_masking_policy is reported as an error but the dump is whole: a warning.
        $masking = EngineFixtures::script($this->dir, 'masking-dump', <<<'SH'
            if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done
            echo "mysqldump: Error: 'SELECT command denied to user 'bk'@'h' for table 'column_masking_policy'' when trying to dump masking policies" >&2
            printf -- "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;\nCREATE TABLE t (id INT);\n-- Dump completed\n" > "$out"
            SH);
        $report = (new BuiltinMysqlDumper('mysql://bk@db/app', $masking))->dump($this->dir . '/masking.sql', [], false, null, true);
        self::assertStringContainsString('column masking policies', implode(' ', $report->warnings));

        // Exit 0 without an error line but without any table: not a chain's full backup.
        $empty = EngineFixtures::script($this->dir, 'empty-dump', <<<'SH'
            if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done
            printf -- "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;\n-- Dump completed\n" > "$out"
            SH);
        try {
            (new BuiltinMysqlDumper('mysql://bk@db/app', $empty))->dump($this->dir . '/empty.sql', [], false, null, true);
            self::fail('an empty dump started a chain');
        } catch (DumpFailedException $e) {
            self::assertStringContainsString('incomplete', $e->getMessage());
        }
    }

    public function testM1XaAndL1TenantDdl(): void
    {
        self::assertSame(C::UNKNOWN, C::classify("XA START X'7478'", 'other', 'app')[0]);
        self::assertSame(C::UNKNOWN, C::classify("XA PREPARE X'7478'", 'app', 'app')[0]);
        self::assertSame(C::CONTROL, C::classify('BEGIN', 'other', 'app')[0]);

        $event = static fn (int $at, string $content): string => '# at ' . $at . "\n#261101  0:30:00 server id 1  end_log_pos " . ($at + 10) . " CRC32 0x1 \tQuery\tthread_id=9\n" . $content;
        $run   = function (string $content, string $mode = BinlogSchemaFilter::FILTER): array {
            file_put_contents($this->dir . '/in', "DELIMITER /*!*/;\n" . $content . "# End of log file\n");
            $out    = $this->dir . '/out' . ++$this->n;
            $result = BinlogSchemaFilter::run([$this->dir . '/in'], $out, 'app', $mode);

            return [$result, (string) file_get_contents($out)];
        };

        // V9: a tenant's XA fails closed (whatever it holds).
        [$result] = $run($event(100, "use `other`/*!*/;\nXA START X'7478',X'',1\n/*!*/;\n"));
        self::assertStringContainsString('XA transaction', implode(' ', $result->problems));

        // V10: benign tenant DDL with `/*!*/;` in a comment, attributed with certainty: dropped whole.
        [$result, $out] = $run($event(100, "use `other`/*!*/;\nCREATE TABLE t (c INT) COMMENT 'plain /*!*/; use `app`/*!*/; DROP TABLE important'\n/*!*/;\n"));
        self::assertTrue($result->isClean(), implode('; ', $result->problems));
        self::assertSame(1, $result->droppedEvents);
        self::assertStringNotContainsString('important', $out);
        // The same at restore time is still a refusal.
        [$result] = $run($event(100, "use `other`/*!*/;\nCREATE TABLE t (c INT) COMMENT 'plain /*!*/; x'\n/*!*/;\n"), BinlogSchemaFilter::CHECK);
        self::assertFalse($result->isClean());

        // Ambiguous: an ALTER (a later RENAME TO could move the table into app), a CREATE in app, a savepoint.
        foreach ([
            "use `other`/*!*/;\nALTER TABLE t COMMENT='x /*!*/; y', RENAME TO app.t\n/*!*/;\n",
            "use `app`/*!*/;\nCREATE TABLE t (c INT) COMMENT 'x /*!*/; y'\n/*!*/;\n",
            "use `other`/*!*/;\nCREATE TABLE app.t (c INT) COMMENT 'x /*!*/; y'\n/*!*/;\n",
            "SAVEPOINT `s/*!*/;\nuse `app`/*!*/;\nDROP TABLE important`\n/*!*/;\n",
        ] as $i => $content) {
            [$result] = $run($event(100, $content));
            self::assertStringContainsString('several statements in one event', implode(' ', $result->problems), (string) $i);
        }
    }

    public function testMachineReadableReasons(): void
    {
        self::assertSame('filter_mismatch', (new BinlogFilterException(['x'], 1))->getReason());

        $manager = $this->createMock(SiteBackupManagerInterface::class);
        $manager->method('defaultOptions')->willReturn(new BackupOptions());
        $manager->method('createBackup')->willThrowException(new BinlogGapException(BinlogGapException::CLOSED_BY_RESTORE, 'closed'));
        $tester = new CommandTester(new CreateBackupCommand($manager));
        self::assertSame(CreateBackupCommand::EXIT_BINLOG_GAP, $tester->execute([]));
        self::assertStringContainsString('reason: chain_closed_by_restore', $tester->getDisplay());
        self::assertSame(CreateBackupCommand::EXIT_BINLOG_GAP, $tester->execute(['--json' => true]));
        $lines = explode("\n", trim($tester->getDisplay()));
        self::assertSame(['error' => 'needs_full_backup', 'reason' => 'chain_closed_by_restore'], json_decode(end($lines), true));
    }
}

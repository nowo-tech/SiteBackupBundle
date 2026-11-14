<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Incremental\BinlogEventTimes;
use Nowo\SiteBackupBundle\Incremental\DumpCoordinatesParser;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function str_repeat;

final class ParsersTest extends TestCase
{
    private string $dir;

    private int $files = 0;

    protected function setUp(): void
    {
        $this->dir = EngineFixtures::tempDir('sbb-parsers-');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testMysql9DumpHeader(): void
    {
        $dump = $this->write(<<<'SQL'
            -- MySQL dump 10.13  Distrib 9.7.2, for Linux (x86_64)
            /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
            SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
            --
            -- GTID state at the beginning of the backup
            --

            /* SET @@GLOBAL.GTID_PURGED='+36e14768-e00e-11f1-ac49-6e201b677570:1-11';*/

            --
            -- Position to start replication or point-in-time recovery from
            --

            -- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;
            DROP TABLE IF EXISTS `t`;
            CREATE TABLE `t` (id INT);
            -- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.999999', SOURCE_LOG_POS=1;
            SQL);

        self::assertSame(['file' => 'binlog.000002', 'position' => 1480, 'gtid' => '36e14768-e00e-11f1-ac49-6e201b677570:1-11'], DumpCoordinatesParser::parse($dump));
    }

    public function testOldStyleAndMultilineGtid(): void
    {
        $dump = $this->write("SET @@GLOBAL.GTID_PURGED=/*!80000 '+*/ 'aaaa:1-5,\nbbbb:1-3';\n-- CHANGE MASTER TO MASTER_LOG_FILE='mysql-bin.000007', MASTER_LOG_POS=4;\nINSERT INTO t VALUES (1);\n");
        // The first form is not the commented one: only the quoted set after GTID_PURGED=' counts.
        self::assertSame('mysql-bin.000007', DumpCoordinatesParser::parse($dump)['file'] ?? null);

        $dump = $this->write("/* SET @@GLOBAL.GTID_PURGED='+aaaa:1-5,\nbbbb:1-3';*/\n-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000003', SOURCE_LOG_POS=99;\n");
        self::assertSame(['file' => 'binlog.000003', 'position' => 99, 'gtid' => "aaaa:1-5,\nbbbb:1-3"], DumpCoordinatesParser::parse($dump));
    }

    public function testNoCoordinates(): void
    {
        self::assertNull(DumpCoordinatesParser::parse($this->dir . '/missing.sql'));
        self::assertNull(DumpCoordinatesParser::parse($this->write("CREATE TABLE t (id INT);\n-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1;\n")));
        // A huge header without coordinates stops after 4 MiB.
        self::assertNull(DumpCoordinatesParser::parse($this->write(str_repeat(str_repeat('-', 1023) . "\n", 4200) . "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1;\n")));
    }

    public function testEventTimes(): void
    {
        $decoded = $this->write(implode("\n", [
            '# at 4',
            "#251114  9:31:00 server id 1  end_log_pos 126 CRC32 0x1 \tStart: binlog v 4, server v 9.7.2 created 251114  9:31:00",
            "#700101  0:00:00 server id 1  end_log_pos 0 CRC32 0x1 \tRotate to binlog.000003  pos: 4",
            "#251114  9:31:00 server id 1  end_log_pos 197 CRC32 0x1 \tPrevious-GTIDs",
            "#251114  9:31:05 server id 1  end_log_pos 276 CRC32 0x1 \tGTID\tlast_committed=0",
            "#251114  9:31:05 server id 1  end_log_pos 351 CRC32 0x1 \tQuery\tthread_id=8",
            "#251114 10:02:59 server id 1  end_log_pos 460 CRC32 0x1 \tXid = 12",
            "#251114 10:03:00 server id 1  end_log_pos 500 CRC32 0x1 \tStop",
            '# not a header',
            '',
        ]));

        [$first, $last] = BinlogEventTimes::scan($decoded);
        self::assertEquals(new DateTimeImmutable('2025-11-14 09:31:05 UTC'), $first);
        self::assertEquals(new DateTimeImmutable('2025-11-14 10:02:59 UTC'), $last);
        self::assertSame([null, null], BinlogEventTimes::scan($this->dir . '/missing'));
        self::assertSame([null, null], BinlogEventTimes::scan($this->write("nothing\n")));
        self::assertNull(BinlogEventTimes::parse('#251399  9:31:05 server id 1  end_log_pos 1 CRC32 0x1 ' . "\tQuery"));
    }

    private function write(string $content): string
    {
        $path = $this->dir . '/f' . ++$this->files;
        file_put_contents($path, $content);

        return $path;
    }
}

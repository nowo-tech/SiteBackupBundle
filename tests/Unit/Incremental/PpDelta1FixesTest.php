<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use Nowo\SiteBackupBundle\Command\CreateBackupCommand;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Exception\BinlogFilterException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Incremental\BinlogBoundaries;
use Nowo\SiteBackupBundle\Incremental\BinlogFilterResult;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\BinlogSchemaFilter;
use Nowo\SiteBackupBundle\Incremental\BinlogStatementClassifier as C;
use Nowo\SiteBackupBundle\Incremental\DumpObjects;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function implode;
use function touch;

/**
 * Audit PP, delta 1 (SiteBackup PR #62 at 00d01ea): red-first tests.
 */
final class PpDelta1FixesTest extends TestCase
{
    private string $dir;

    private int $n = 0;

    protected function setUp(): void
    {
        $this->dir = EngineFixtures::tempDir('sbb-pp-d1-');
        // binlog.000002: format description, filler, then events at 1480, 1600, 1700 (end 2000).
        FakeBinlog::write($this->dir . '/binlog.000002', [127, 1480, 1600, 1700, 2000]);
        FakeBinlog::write($this->dir . '/binlog.000003', [127, 158]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTheBinaryIndex(): void
    {
        self::assertSame([[4, 127], [127, 1480], [1480, 1600], [1600, 1700], [1700, 2000]], BinlogBoundaries::index($this->dir . '/binlog.000002'));

        foreach ([
            'no magic'  => 'garbage-not-a-binlog',
            'truncated' => "\xfebin" . str_repeat("\0", 10),
            'too long'  => "\xfebin" . pack('VCVVVv', 0, 15, 1, 5000, 5004, 0),
            'bad end'   => "\xfebin" . pack('VCVVVv', 0, 15, 1, 19, 999, 0),
        ] as $case => $bytes) {
            file_put_contents($this->dir . '/bad', $bytes);
            try {
                BinlogBoundaries::index($this->dir . '/bad');
                self::fail($case . ' was indexed');
            } catch (RuntimeException) {
            }
        }
        try {
            BinlogBoundaries::index($this->dir . '/missing');
            self::fail('a missing file was indexed');
        } catch (RuntimeException) {
        }
    }

    public function testH1ARealStreamPassesAndEveryInjectionFailsClosed(): void
    {
        $real = $this->stream([
            FakeBinlog::event(1480, 1600, "Query\tthread_id=1", "use `app`/*!*/;\nBEGIN\n/*!*/;\n"),
            FakeBinlog::event(1600, 1700, 'Table_map: `app`.`pitr` mapped to number 1'),
            FakeBinlog::event(1700, 2000, 'Write_rows: table id 1 flags: STMT_END_F', "\nBINLOG '\nAPPROWS\n'/*!*/;\n"),
            FakeBinlog::start(4),
            FakeBinlog::event(127, 158, 'Xid = 1', "COMMIT/*!*/;\n"),
        ]);
        $result = $this->filter($real);
        self::assertTrue($result->isClean(), implode('; ', $result->problems));
        self::assertStringContainsString('APPROWS', (string) file_get_contents($this->dir . '/out' . $this->n));

        // The recorded sequence of the filtered output validates it again at restore (and nothing else).
        $out      = $this->dir . '/out' . $this->n;
        $sequence = BinlogBoundaries::recordFrom($out);
        self::assertSame([[127, 127], [1480, 1600], [1600, 1700], [1700, 2000], [4, 127], [127, 158]], $sequence);
        $check = BinlogSchemaFilter::run([$out], $this->dir . '/check', 'app', BinlogSchemaFilter::CHECK, null, BinlogBoundaries::recorded([$sequence]));
        self::assertTrue($check->isClean(), implode('; ', $check->problems));
        $tampered = BinlogSchemaFilter::run([$out], $this->dir . '/check2', 'app', BinlogSchemaFilter::CHECK, null, BinlogBoundaries::recorded([[[127, 127], [1480, 1600]]]));
        self::assertFalse($tampered->isClean());

        // X12: a tenant's literal holds fake events. Every shape fails closed, nothing reaches the output.
        $literal = static fn (string $inside): string => FakeBinlog::event(1480, 1600, "Query\tthread_id=9", "use `other`/*!*/;\nCREATE TABLE inj (c INT) COMMENT='x\n" . $inside . "'\n/*!*/;\n");
        foreach ([
            'unknown position'   => "# at 999001\n#261101 10:00:00 server id 1  end_log_pos 1 CRC32 0x0 \tQuery\tthread_id=1\nuse `app`/*!*/;\nDROP TABLE important\n/*!*/;\n",
            'next real position' => "# at 1600\n#261101 10:00:00 server id 1  end_log_pos 1700 CRC32 0x0 \tQuery\tthread_id=1\nuse `app`/*!*/;\nDROP TABLE important\n/*!*/;\n",
            'header only'        => "#261101 10:00:00 server id 1  end_log_pos 1600 CRC32 0x0 \tQuery\tthread_id=1\n",
            'fake end of log'    => "# End of log file\nDROP TABLE important;\n",
            'malformed at'       => "# at x\n",
        ] as $case => $inside) {
            $stream = $this->stream([
                $literal($inside),
                FakeBinlog::event(1600, 1700, "Query\tthread_id=1", "use `app`/*!*/;\nBEGIN\n/*!*/;\n"),
                FakeBinlog::event(1700, 2000, 'Xid = 2', "COMMIT/*!*/;\n"),
                FakeBinlog::start(4),
                FakeBinlog::event(127, 158, 'Xid = 1', "COMMIT/*!*/;\n"),
            ]);
            $result = $this->filter($stream);
            self::assertFalse($result->isClean(), $case);
            self::assertStringContainsString('do not match the binary logs', implode(' ', $result->problems), $case);
            self::assertStringNotContainsString('important', (string) file_get_contents($this->dir . '/out' . $this->n), $case);
        }

        // Without any boundary line: statements smuggled in an identifier or literal of ONE event.
        $smuggled = $this->stream([
            FakeBinlog::event(1480, 1600, "Query\tthread_id=9", "SET TIMESTAMP=1/*!*/;\nSAVEPOINT `a\n/*!*/;\nuse `app`/*!*/;\nDROP TABLE important\n/*!*/;\n`\n/*!*/;\n"),
        ], false);
        $result = BinlogSchemaFilter::run([$smuggled], $this->dir . '/smuggled', 'app', BinlogSchemaFilter::FILTER);
        self::assertStringContainsString('several statements in one event', implode(' ', $result->problems));

        // Structure errors of a real stream: a missing format description, a header-less recorded event, a log that
        // ends early.
        foreach ([
            'no format description' => (string) file_get_contents($this->stream([FakeBinlog::event(1480, 1600, "Query\tthread_id=1", "BEGIN\n/*!*/;\n")], true, false)),
            'ends early'            => (string) file_get_contents($this->stream([FakeBinlog::event(1480, 1600, "Query\tthread_id=1", "BEGIN\n/*!*/;\n")])),
            'no trailer'            => (string) file_get_contents($this->stream([FakeBinlog::event(1480, 1600, "Query\tthread_id=1", "BEGIN\n/*!*/;\n")], false)),
            'second log missing'    => "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 1600, "Query\tthread_id=1", "BEGIN\n/*!*/;\n") . FakeBinlog::event(1600, 1700, 'Table_map: `app`.`pitr` mapped to number 1') . FakeBinlog::event(1700, 2000, 'Xid = 1', "COMMIT/*!*/;\n") . "# End of log file\n",
            'second FDE header'     => "DELIMITER /*!*/;\n# at 127\n#261101  0:30:00 server id 1  end_log_pos 127 CRC32 0x1 \tQuery\n# End of log file\n",
            'at before FDE header'  => "DELIMITER /*!*/;\n# at 127\n# at 1480\n# End of log file\n",
            'not the start'         => "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1600, 1700, 'Xid = 1', "COMMIT/*!*/;\n") . "# End of log file\n",
        ] as $case => $stream) {
            file_put_contents($this->dir . '/case', $stream);
            self::assertFalse($this->filter($this->dir . '/case')->isClean(), $case);
        }
        $recorded = BinlogBoundaries::recorded([[[1480, 1600], [1600, null]]]);
        self::assertNull($recorded->at(1480));
        self::assertNotNull($recorded->at(1600), 'an event without its recorded header');
        $recorded = BinlogBoundaries::recorded([[[1480, 1600]]]);
        self::assertNull($recorded->at(1480));
        self::assertNotNull($recorded->end());
        self::assertNotNull($recorded->header(1, false));
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function statements(): iterable
    {
        yield 'X10 rename into app' => ['ALTER TABLE other.mv RENAME TO app.mv', null, C::UNKNOWN];
        yield 'X10 rename out of app' => ['ALTER TABLE app.out1 RENAME TO other.out1', 'app', C::UNKNOWN];
        yield 'rename among specs' => ['ALTER TABLE app.t ADD c INT, RENAME AS other.t', 'app', C::UNKNOWN];
        yield 'rename in the schema' => ['ALTER TABLE t RENAME TO t2', 'app', C::SCHEMA];
        yield 'rename column' => ['ALTER TABLE t RENAME COLUMN a TO b', 'app', C::SCHEMA];
        yield 'X5 view reads another schema' => ['CREATE VIEW app.v AS SELECT * FROM other.y', 'app', C::UNKNOWN];
        yield 'X5 like another schema' => ['CREATE TABLE app.l LIKE other.y', 'app', C::UNKNOWN];
        yield 'foreign key to another schema' => ['ALTER TABLE t ADD FOREIGN KEY (a) REFERENCES other.p (id)', 'app', C::UNKNOWN];
        yield 'join into another schema' => ['CREATE VIEW v AS SELECT * FROM t JOIN `other`.`y` USING (id)', 'app', C::UNKNOWN];
        yield 'view on the schema' => ['CREATE VIEW v AS SELECT p.id FROM pitr p JOIN app.q ON q.id = p.id', 'app', C::SCHEMA];
        yield 'Beacon trigger, app only' => ['CREATE DEFINER=`beacon_app`@`%` TRIGGER wtr_no_update BEFORE UPDATE ON working_time FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'immutable\'', 'app', C::SCHEMA];
        yield 'trigger writing the schema' => ['CREATE TRIGGER t1 AFTER INSERT ON pitr FOR EACH ROW INSERT INTO app.audit VALUES (NEW.id)', 'app', C::SCHEMA];
        yield 'X4 trigger writing another schema' => ['CREATE TRIGGER t1 AFTER INSERT ON pitr FOR EACH ROW INSERT INTO other.victim VALUES (NEW.id)', 'app', C::UNKNOWN];
        yield 'trigger updating another schema' => ['CREATE TRIGGER t1 AFTER INSERT ON pitr FOR EACH ROW UPDATE other.y SET v = 1', 'app', C::UNKNOWN];
        yield 'drop trigger' => ['DROP TRIGGER IF EXISTS wtr_no_update', 'app', C::SCHEMA];
    }

    #[DataProvider('statements')]
    public function testClassifier(string $statement, ?string $defaultDb, string $expected): void
    {
        self::assertSame($expected, C::classify($statement, $defaultDb, 'app')[0]);
    }

    public function testL2FilterFailuresExitLikeAGap(): void
    {
        $manager = $this->createMock(SiteBackupManagerInterface::class);
        $manager->method('defaultOptions')->willReturn(new BackupOptions());
        $manager->method('createBackup')->willThrowException(new BinlogFilterException(['at 1480: DROP TABLE on another database'], 1));
        $tester = new CommandTester(new CreateBackupCommand($manager));

        self::assertSame(CreateBackupCommand::EXIT_BINLOG_GAP, $tester->execute([]));
        self::assertStringContainsString('Take a full backup', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--incremental-of' => 'x', '--routines' => true]));
    }

    public function testL3RoutinesAndEventsOptIn(): void
    {
        $base     = ['mysqldump', '--single-transaction', '--', 'app'];
        $commands = BuiltinMysqlDumper::planCommands($base, 'app', [], true, true, true, true);
        self::assertContains('--routines', $commands[0]);
        self::assertContains('--events', $commands[0]);
        self::assertNotContains('--routines', BuiltinMysqlDumper::planCommands($base, 'app', [], true, true)[0]);

        try {
            new BackupOptions(dumpRoutines: true);
            self::fail('dumpRoutines without coordinates was accepted');
        } catch (InvalidBackupOptionsException) {
        }
        self::assertTrue((new BackupOptions(recordBinlogCoordinates: true, dumpRoutines: true))->withLabel('x')->dumpRoutines);

        file_put_contents($this->dir . '/dump.sql', implode("\n", [
            'CREATE TABLE `t` (',
            '/*!50003 CREATE*/ /*!50017 DEFINER=`beacon_app`@`%`*/ /*!50003 TRIGGER `wtr_no_update` BEFORE UPDATE ON `t` FOR EACH ROW SIGNAL SQLSTATE \'45000\' */;;',
            'CREATE DEFINER=`root`@`%` PROCEDURE `p1`()',
            'CREATE DEFINER=`root`@`%` FUNCTION `f1`() RETURNS int',
            '/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`%`*/ /*!50106 EVENT `e1` ON SCHEDULE EVERY 1 DAY DO DELETE FROM t */ ;;',
        ]));
        self::assertSame(['TABLE t' => true, 'TRIGGER wtr_no_update' => true, 'PROCEDURE p1' => true, 'FUNCTION f1' => true, 'EVENT e1' => true], DumpObjects::parse($this->dir . '/dump.sql'));
    }

    public function testTriggerCreationNeedsTrustOrSuperOnABinlogTarget(): void
    {
        $tools = $this->dir . '/tools';
        (new Filesystem())->mkdir([$this->dir . '/work', $tools]);
        $replayer = new BinlogReplayer('mysql://rs@127.0.0.1:3306/app', new MysqlBinlogTool(BinlogFakes::mysqlbinlog($tools)), BinlogFakes::mysqlClient($tools), 30, $this->dir . '/work');
        self::assertNull($replayer->storedProgramsRefusal(), 'no log_bin_trust_function_creators variable: no rule');
        touch($tools . '/untrusted');
        self::assertStringContainsString('ERROR 1419', (string) $replayer->storedProgramsRefusal());
        file_put_contents($tools . '/grants', "GRANT USAGE ON *.* TO `rs`@`%`\nGRANT ALL PRIVILEGES ON `app`.* TO `rs`@`%`\n");
        self::assertNotNull($replayer->storedProgramsRefusal(), 'ALL on the schema is not SUPER');
        file_put_contents($tools . '/grants', "GRANT ALL PRIVILEGES ON *.* TO `root`@`%`\n");
        self::assertNull($replayer->storedProgramsRefusal());
        file_put_contents($tools . '/grants', "GRANT SELECT, SUPER, RELOAD ON *.* TO `rs`@`%`\n");
        self::assertNull($replayer->storedProgramsRefusal());
    }

    /**
     * A decoded stream of binlog.000002 (from 1480) and binlog.000003.
     *
     * @param list<string> $events
     */
    private function stream(array $events, bool $end = true, bool $start = true): string
    {
        $path = $this->dir . '/in' . ++$this->n;
        file_put_contents($path, "DELIMITER /*!*/;\n" . ($start ? FakeBinlog::start(127) : '') . implode('', $events) . ($end ? "# End of log file\n" : ''));

        return $path;
    }

    private function filter(string $input): BinlogFilterResult
    {
        ++$this->n;

        return BinlogSchemaFilter::run([$input], $this->dir . '/out' . $this->n, 'app', BinlogSchemaFilter::FILTER, null, BinlogBoundaries::fromRawFiles([$this->dir . '/binlog.000002', $this->dir . '/binlog.000003'], 1480));
    }
}

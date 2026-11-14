<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Database\MysqlOptionFile;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;
use Nowo\SiteBackupBundle\Incremental\BinlogCapture;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisites;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\BinlogServerInterface;
use Nowo\SiteBackupBundle\Incremental\Check;
use Nowo\SiteBackupBundle\Incremental\IncrementalArtifact;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Incremental\MysqlClientBinlogServer;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function count;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function glob;
use function hash;
use function mkdir;
use function str_repeat;
use function trim;

/**
 * Binary log tooling against stand-in binaries: argv never holds the password, the option file is private and removed,
 * gaps are typed, captures are complete or nothing.
 */
final class BinlogToolsTest extends TestCase
{
    private string $dir;

    private string $work;

    private string $url;

    private string $password;

    protected function setUp(): void
    {
        $this->dir  = EngineFixtures::tempDir('sbb-binlog-');
        $this->work = $this->dir . '/work';
        mkdir($this->work);
        mkdir($this->dir . '/server');
        // Built at runtime: never a literal credential in the repository.
        $this->password = 'pass-REDACTED-TEST"\\#';
        $this->url      = 'mysql://bk:' . rawurlencode($this->password) . '@127.0.0.1:3306/app';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testOptionFileQuotingAndPrivacy(): void
    {
        $contents = MysqlOptionFile::contents(['database' => 'app', 'host' => 'db', 'port' => 3307, 'socket' => null, 'user' => 'u"x', 'pass' => 'a\\b"c']);
        self::assertSame("[client]\nuser=\"u\\\"x\"\npassword=\"a\\\\b\\\"c\"\nhost=\"db\"\nport=3307\n", $contents);
        self::assertSame("[client]\nuser=\"u\"\nsocket=\"/run/mysqld.sock\"\n", MysqlOptionFile::contents(['database' => 'app', 'host' => null, 'port' => 3306, 'socket' => '/run/mysqld.sock', 'user' => 'u', 'pass' => '']));

        $path = MysqlOptionFile::write($this->url, $this->work);
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertStringContainsString('REDACTED-TEST', (string) file_get_contents($path));

        try {
            MysqlOptionFile::write($this->url, $this->work);
            self::fail('an existing option file was reused');
        } catch (RuntimeException) {
        }

        $this->expectException(InvalidArgumentException::class);
        MysqlOptionFile::contents(['database' => 'app', 'host' => 'db', 'port' => 1, 'socket' => null, 'user' => 'u', 'pass' => "a\nb"]);
    }

    public function testClientServerStatementsKeepThePasswordOutOfArgv(): void
    {
        $server = new MysqlClientBinlogServer($this->url, BinlogFakes::mysqlClient($this->dir), 30, $this->work);
        file_put_contents($this->dir . '/logs', "binlog.000001\t2000\tNo\nbinlog.000002\t500\tNo\n");

        self::assertSame('9.7.2', $server->version());
        self::assertSame(ChainFixtures::UUID, $server->serverUuid());
        self::assertSame(['log_bin' => 'ON', 'binlog_format' => 'ROW', 'binlog_row_image' => 'FULL'], $server->variables(['log_bin', 'binlog_format', 'binlog_row_image']));
        self::assertSame([], $server->variables([]));
        $server->flushBinaryLogs();
        self::assertFileExists($this->dir . '/flushed');
        self::assertSame([['name' => 'binlog.000001', 'size' => 2000], ['name' => 'binlog.000002', 'size' => 500]], $server->binaryLogs());
        self::assertSame(['file' => 'binlog.000004', 'position' => 198, 'gtid_executed' => 'uuid-a:1-13,uuid-b:1-2'], $server->status());
        self::assertSame(['GRANT RELOAD, REPLICATION SLAVE ON *.* TO `bk`@`%`'], $server->grants());

        $args = (string) file_get_contents($this->dir . '/args');
        self::assertStringNotContainsString('REDACTED-TEST', $args);
        self::assertStringContainsString('--defaults-file=', $args);
        self::assertStringContainsString('password="pa', (string) file_get_contents($this->dir . '/cnf'));
        self::assertFileDoesNotExist(trim((string) file_get_contents($this->dir . '/cnf-path')), 'the option file outlived the call');
        self::assertSame([], glob($this->work . '/*') ?: [], 'a private directory was left behind');

        self::assertSame('SHOW BINARY LOG STATUS', MysqlClientBinlogServer::statusStatement('8.4.6-log'));
        self::assertSame('SHOW MASTER STATUS', MysqlClientBinlogServer::statusStatement('8.0.36'));
        self::assertSame('SHOW MASTER STATUS', MysqlClientBinlogServer::statusStatement('weird'));
    }

    public function testClientServerFailuresAreRedacted(): void
    {
        $server = new MysqlClientBinlogServer($this->url, BinlogFakes::mysqlClient($this->dir), 30, $this->work);
        file_put_contents($this->dir . '/logs', "binlog.000001\tnot-a-size\n");
        try {
            $server->binaryLogs();
            self::fail('a bad row was accepted');
        } catch (BinlogUnavailableException) {
        }

        file_put_contents($this->dir . '/fail', '1');
        try {
            $server->version();
            self::fail('a failing client was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringNotContainsString('REDACTED-TEST', $e->getMessage());
            self::assertStringContainsString('Access denied', $e->getMessage());
        }

        try {
            (new MysqlClientBinlogServer('not a url', BinlogFakes::mysqlClient($this->dir), 30, $this->work))->version();
            self::fail('a bad URL was accepted');
        } catch (BinlogUnavailableException) {
        }

        $empty = new MysqlClientBinlogServer($this->url, EngineFixtures::script($this->dir, 'silent', 'exit 0'), 30, $this->work);
        try {
            $empty->serverUuid();
            self::fail('an empty answer was accepted');
        } catch (BinlogUnavailableException) {
        }
        try {
            $empty->status();
            self::fail('an empty status was accepted');
        } catch (BinlogUnavailableException) {
        }

        $slow = new MysqlClientBinlogServer($this->url, EngineFixtures::script($this->dir, 'slow', 'sleep 5'), 1, $this->work);
        try {
            $slow->version();
            self::fail('a hanging client was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $server->variables(['log_bin; DROP']);
    }

    public function testCaptureCopiesClosedLogsAndReadsEventTimes(): void
    {
        $this->serverFile('binlog.000002', 2000);
        $this->serverFile('binlog.000003', 900);
        file_put_contents($this->dir . '/decoded', "#261101 10:00:01 server id 1  end_log_pos 2 CRC32 0x1 \tQuery\n#261101 10:30:00 server id 1  end_log_pos 3 CRC32 0x1 \tXid = 3\n");
        $server  = BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000], ['name' => 'binlog.000003', 'size' => 900]]);
        $capture = $this->capture($server);
        $ticks   = 0;

        $artifact = $capture->capture(new BinlogCoordinates('binlog.000002', 1480, ChainFixtures::UUID), 'app', static function () use (&$ticks): void {
            ++$ticks;
        });

        self::assertSame(['binlog.000002', 'binlog.000003'], $artifact->fileNames());
        self::assertSame(hash('sha256', (string) file_get_contents($this->dir . '/server/binlog.000003')), $artifact->files[1]->sha256);
        self::assertTrue($artifact->to->equals(new BinlogCoordinates('binlog.000003', 900, ChainFixtures::UUID)));
        self::assertEquals(new DateTimeImmutable('2026-11-01 10:00:01 UTC'), $artifact->firstEventAt);
        self::assertEquals(new DateTimeImmutable('2026-11-01 10:30:00 UTC'), $artifact->lastEventAt);
        self::assertSame('app', $artifact->database);
        self::assertGreaterThan(0, $ticks);
        self::assertSame(1, $server->flushes);

        $args = (string) file_get_contents($this->dir . '/binlog-args');
        self::assertStringNotContainsString('REDACTED-TEST', $args);
        self::assertStringContainsString('--read-from-remote-server --raw', $args);
        self::assertStringContainsString('--start-position=1480', $args);
        self::assertStringContainsString('TZ=UTC', (string) file_get_contents($this->dir . '/binlog-env'));
        self::assertDirectoryDoesNotExist($artifact->directory . '/credentials');
        self::assertFileDoesNotExist($artifact->directory . '/events.txt');

        $capture->discard($artifact);
        self::assertDirectoryDoesNotExist($artifact->directory);
    }

    public function testCaptureStartsAfterAFullyReadFileAndReportsNothingNew(): void
    {
        $this->serverFile('binlog.000003', 900);
        $server  = BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000], ['name' => 'binlog.000003', 'size' => 900]]);
        $capture = $this->capture($server);

        $artifact = $capture->capture(new BinlogCoordinates('binlog.000002', 2000, ChainFixtures::UUID), 'app');
        self::assertSame(['binlog.000003'], $artifact->fileNames());
        self::assertStringNotContainsString('--start-position', (string) file_get_contents($this->dir . '/binlog-args'));
        $capture->discard($artifact);

        // Nothing written since the end of the last closed log.
        $quiet    = BinlogFakes::server([['name' => 'binlog.000003', 'size' => 900], ['name' => 'binlog.000004', 'size' => 158]], afterFlush: [['name' => 'binlog.000003', 'size' => 900], ['name' => 'binlog.000004', 'size' => 158], ['name' => 'binlog.000005', 'size' => 158]]);
        $artifact = $this->capture($quiet)->capture(new BinlogCoordinates('binlog.000004', 158, ChainFixtures::UUID), 'app');
        self::assertTrue($artifact->isEmpty());
        self::assertTrue($artifact->to->equals($artifact->from));
        (new Filesystem())->remove($artifact->directory);
    }

    public function testCaptureGaps(): void
    {
        $logs = [['name' => 'binlog.000005', 'size' => 2000], ['name' => 'binlog.000006', 'size' => 900]];

        foreach ([
            [new BinlogCoordinates('binlog.000002', 1480, ChainFixtures::UUID), BinlogGapException::EXPIRED],
            [new BinlogCoordinates('binlog.000005', 1480, ChainFixtures::OTHER), BinlogGapException::SERVER_CHANGED],
            [new BinlogCoordinates('binlog.000005', 9999, ChainFixtures::UUID), BinlogGapException::POSITION_INVALID],
        ] as [$from, $reason]) {
            try {
                $this->capture(BinlogFakes::server($logs))->capture($from, 'app');
                self::fail('no gap for ' . $reason);
            } catch (BinlogGapException $e) {
                self::assertSame($reason, $e->getReason());
            }
        }

        // Purged between the check and FLUSH.
        try {
            $this->capture(BinlogFakes::server($logs, afterFlush: [['name' => 'binlog.000006', 'size' => 900], ['name' => 'binlog.000007', 'size' => 158]]))
                ->capture(new BinlogCoordinates('binlog.000005', 100, ChainFixtures::UUID), 'app');
            self::fail('no gap after a purge');
        } catch (BinlogGapException $e) {
            self::assertSame(BinlogGapException::EXPIRED, $e->getReason());
        }

        // A hole in the server's index.
        try {
            BinlogCapture::closedFrom(new BinlogCoordinates('binlog.000005', 100, ChainFixtures::UUID), [['name' => 'binlog.000005', 'size' => 2000], ['name' => 'binlog.000007', 'size' => 1], ['name' => 'binlog.000008', 'size' => 1]]);
            self::fail('a hole was accepted');
        } catch (BinlogGapException $e) {
            self::assertSame(BinlogGapException::EXPIRED, $e->getReason());
        }

        // Purged while mysqlbinlog reads.
        $this->serverFile('binlog.000005', 2000);
        file_put_contents($this->dir . '/raw-error', "ERROR: Got error reading packet from server: Could not find first log file name in binary log index file (1236)\n");
        try {
            $this->capture(BinlogFakes::server($logs))->capture(new BinlogCoordinates('binlog.000005', 100, ChainFixtures::UUID), 'app');
            self::fail('no gap during the read');
        } catch (BinlogGapException $e) {
            self::assertSame(BinlogGapException::EXPIRED, $e->getReason());
        }
        self::assertSame([], glob($this->work . '/*') ?: [], 'a failed capture left files behind');
    }

    public function testCaptureRefusals(): void
    {
        $logs = [['name' => 'binlog.000005', 'size' => 2000], ['name' => 'binlog.000006', 'size' => 900]];
        $from = new BinlogCoordinates('binlog.000005', 100, ChainFixtures::UUID);

        foreach ([
            'mariadb server' => fn (): IncrementalArtifact => $this->capture(BinlogFakes::server($logs, version: '11.4.2-MariaDB'))->capture($from, 'app'),
            'mariadb tool'   => fn (): IncrementalArtifact => (new BinlogCapture(BinlogFakes::server($logs), $this->url, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->dir, 'mysqlbinlog Ver 3.5 Distrib 11.8.2-MariaDB')), $this->work))->capture($from, 'app'),
            'missing tool'   => fn (): IncrementalArtifact => (new BinlogCapture(BinlogFakes::server($logs), $this->url, new MysqlBinlogTool($this->dir . '/none'), $this->work))->capture($from, 'app'),
            'still active'   => static fn (): array => BinlogCapture::closedFrom(new BinlogCoordinates('binlog.000006', 100, ChainFixtures::UUID), $logs),
            'bad url'        => fn (): IncrementalArtifact => (new BinlogCapture(BinlogFakes::server($logs), 'nope', new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->dir)), $this->work))->capture($from, 'app'),
            'other failure'  => function () use ($logs, $from): void {
                $this->serverFile('binlog.000005', 2000);
                file_put_contents($this->dir . '/raw-error', "Access denied\n");
                $this->capture(BinlogFakes::server($logs))->capture($from, 'app');
            },
        ] as $case => $run) {
            try {
                $run();
                self::fail($case . ' was accepted');
            } catch (BinlogUnavailableException) {
            }
        }
        (new Filesystem())->remove($this->dir . '/raw-error');

        // A short copy is refused.
        file_put_contents($this->dir . '/short', '1');
        try {
            $this->capture(BinlogFakes::server($logs))->capture($from, 'app');
            self::fail('a short copy was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('incomplete', $e->getMessage());
        }
        self::assertSame([], glob($this->work . '/*') ?: []);

        $this->expectException(InvalidArgumentException::class);
        $this->capture(BinlogFakes::server($logs))->capture($from, 'app; DROP');
    }

    public function testToolTimeoutsAndDecodeOptions(): void
    {
        $slow = new MysqlBinlogTool(EngineFixtures::script($this->dir, 'slow-binlog', 'if [ "$1" = "--version" ]; then sleep 40; fi; sleep 5'), 1);
        try {
            $slow->decode(['/x'], $this->dir . '/out', null, null, null, true);
            self::fail('a hanging mysqlbinlog was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }

        $tool = new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->dir));
        self::assertSame('9.7.2', $tool->assertUsable());
        $tool->decode(['/a', '/b'], $this->dir . '/out', 1480, 'app', new DateTimeImmutable('2026-11-01 12:00:00+02:00'), false);
        $args = (string) file_get_contents($this->dir . '/binlog-args');
        self::assertStringContainsString('--skip-gtids --database=app --start-position=1480 --stop-datetime=2026-11-01 10:00:00', $args);
        self::assertStringNotContainsString('DECODE-ROWS', $args);

        file_put_contents($this->dir . '/decode-error', '1');
        $this->expectException(BinlogUnavailableException::class);
        $tool->decode(['/a'], $this->dir . '/out', null, null, null, true);
    }

    public function testReplayerDecodesAndAppliesThroughTheClient(): void
    {
        $client   = BinlogFakes::mysqlClient($this->dir);
        $replayer = new BinlogReplayer($this->url, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->dir)), $client, 30, $this->work);
        $decoded  = $this->dir . '/replay.sql';

        self::assertSame('app', $replayer->databaseName());
        $replayer->decode([$this->dir . '/binlog.000002', $this->dir . '/binlog.000003'], new BinlogCoordinates('binlog.000002', 1480, ChainFixtures::UUID), new DateTimeImmutable('2026-11-01 10:00:00 UTC'), $decoded);
        self::assertStringNotContainsString('--database', self::lastLine($this->dir . '/binlog-args'), 'PP-M1: replays are never filtered by the default database');
        self::assertStringContainsString('--start-position=1480 --stop-datetime=2026-11-01 10:00:01', (string) file_get_contents($this->dir . '/binlog-args'));

        $replayer->decode([$this->dir . '/binlog.000003'], new BinlogCoordinates('binlog.000002', 1480, ChainFixtures::UUID), null, $decoded);
        self::assertStringNotContainsString('--start-position', $this->lastLine($this->dir . '/binlog-args'));

        file_put_contents($decoded, "BINLOG 'abc'/*!*/;\n" . str_repeat('x', 10));
        $replayer->apply($decoded);
        self::assertSame("BINLOG 'abc'/*!*/;\n" . str_repeat('x', 10), file_get_contents($this->dir . '/replayed.sql'));
        $args = $this->lastLine($this->dir . '/args');
        self::assertStringContainsString('--binary-mode --batch -- app', $args);
        self::assertStringNotContainsString('REDACTED-TEST', $args);
        self::assertSame([], glob($this->work . '/*') ?: []);

        try {
            $replayer->apply($this->dir . '/missing.sql');
            self::fail('a missing file was accepted');
        } catch (BinlogUnavailableException) {
        }
        file_put_contents($this->dir . '/fail', '1');
        try {
            $replayer->apply($decoded);
            self::fail('a failed replay was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('ERROR 1227 (42000) at line 1', $e->getMessage());
            self::assertStringNotContainsString('REDACTED-TEST', $e->getMessage());
        }

        $slow = new BinlogReplayer($this->url, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->dir)), EngineFixtures::script($this->dir, 'slow-client', 'sleep 5'), 1, $this->work);
        try {
            $slow->apply($decoded);
            self::fail('a hanging replay was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }

        foreach (['databaseName', 'apply'] as $method) {
            try {
                $broken = new BinlogReplayer('nope', new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->dir)), $client, 30, $this->work);
                $method === 'apply' ? $broken->apply($decoded) : $broken->databaseName();
                self::fail('a bad URL was accepted');
            } catch (BinlogUnavailableException) {
            }
        }
    }

    public function testPrerequisites(): void
    {
        $client = BinlogFakes::mysqlClient($this->dir);
        $binlog = BinlogFakes::mysqlbinlog($this->dir);
        $dump   = EngineFixtures::fakeMysqldump($this->dir);
        $vars   = ['log_bin' => 'ON', 'binlog_format' => 'ROW', 'binlog_row_image' => 'FULL', 'binlog_expire_logs_seconds' => '2592000', 'gtid_mode' => 'ON'];
        $grants = ['GRANT RELOAD, REPLICATION CLIENT, REPLICATION SLAVE ON *.* TO `bk`@`%`', 'GRANT ALL PRIVILEGES ON `app`.* TO `bk`@`%`'];
        $checks = (new BinlogPrerequisites($this->prereqServer('9.7.2', $vars, $grants), ['mysqldump' => $dump, 'mysql' => $client, 'mysqlbinlog' => $binlog]))->check();

        self::assertTrue(Check::allPass($checks));
        self::assertSame(['binaries', 'server', 'log_bin', 'binlog_format', 'binlog_row_image', 'expiry', 'grants', 'schemas', 'gtid_mode'], array_map(static fn (Check $c): string => $c->name, $checks));
        self::assertSame(Check::OK, $checks[5]->status);

        $bad = (new BinlogPrerequisites(
            $this->prereqServer('8.0.20', ['log_bin' => 'OFF', 'binlog_format' => 'STATEMENT', 'binlog_row_image' => 'MINIMAL', 'binlog_expire_logs_seconds' => '600'], ['GRANT REPLICATION CLIENT ON *.* TO `bk`@`%`', 'GRANT ALL ON `app`.* TO x']),
            ['mysqldump' => EngineFixtures::fakeMysqldump($this->dir, 'mysqldump from 11.8.2-MariaDB'), 'mysqlbinlog' => $this->dir . '/missing'],
        ))->check();
        $status = [];
        foreach ($bad as $check) {
            $status[$check->name] = $check->status;
        }
        self::assertSame(['binaries' => 'fail', 'server' => 'fail', 'log_bin' => 'fail', 'binlog_format' => 'fail', 'binlog_row_image' => 'warning', 'expiry' => 'fail', 'grants' => 'fail', 'schemas' => 'warning', 'gtid_mode' => 'ok'], $status);

        $soso   = (new BinlogPrerequisites($this->prereqServer('8.4.6', ['log_bin' => '1', 'binlog_format' => 'MIXED', 'binlog_expire_logs_seconds' => '7200'], ['GRANT ALL PRIVILEGES ON *.* TO `root`@`%`']), []))->check();
        $status = [];
        foreach ($soso as $check) {
            $status[$check->name] = $check->status;
        }
        // PP-L4: MIXED fails; PP-M4: ALL on *.* works but is a warning.
        self::assertSame('fail', $status['binlog_format']);
        self::assertSame('warning', $status['expiry']);
        self::assertSame('warning', $status['binlog_row_image']);
        self::assertSame('warning', $status['grants']);
        self::assertSame('ok', $status['log_bin']);

        foreach ([['0', 'ok'], ['x', 'warning']] as [$expiry, $expected]) {
            $checks = (new BinlogPrerequisites($this->prereqServer('9.7.2', ['binlog_expire_logs_seconds' => $expiry], ['GRANT RELOAD, REPLICATION CLIENT, REPLICATION REPLICA ON *.* TO x']), []))->check();
            self::assertSame($expected, $checks[5]->status);
            self::assertSame('ok', $checks[6]->status);
        }

        // An unreachable server stops after the server check; variables and grants failures are reported.
        $down = (new BinlogPrerequisites($this->prereqServer(null, [], []), []))->check();
        self::assertSame(['binaries', 'server'], array_map(static fn (Check $c): string => $c->name, $down));
        self::assertTrue($down[1]->isFailure());
        $noVars = (new BinlogPrerequisites($this->prereqServer('9.7.2', null, []), []))->check();
        self::assertSame('log_bin', $noVars[2]->name);
        self::assertTrue($noVars[2]->isFailure());
        $noGrants = (new BinlogPrerequisites($this->prereqServer('9.7.2', [], null), []))->check();
        self::assertTrue($noGrants[6]->isFailure());
        self::assertSame(Check::WARNING, $noGrants[7]->status, 'SHOW DATABASES failing is a warning');

        // PP-M3: other application schemas: fine when filtered, a warning with raw captures.
        $shared   = $this->prereqServer('9.7.2', [], [], ['app', 'mysql', 'sys', 'information_schema', 'performance_schema', 'tenant2', 'crm']);
        $filtered = (new BinlogPrerequisites($shared, [], 604800, 30, 'mysql://bk@db/app'))->check();
        self::assertSame(Check::OK, $filtered[7]->status);
        self::assertStringContainsString('2 other schema(s)', $filtered[7]->detail);
        $raw = (new BinlogPrerequisites($shared, [], 604800, 30, 'mysql://bk@db/app', 'raw'))->check();
        self::assertSame(Check::WARNING, $raw[7]->status);
        $alone = (new BinlogPrerequisites($this->prereqServer('9.7.2', [], [], ['app', 'mysql']), [], 604800, 30, 'nope'))->check();
        self::assertStringContainsString('1 other schema', $alone[7]->detail, 'an unparsable URL counts its schema as another one');
        $only = (new BinlogPrerequisites($this->prereqServer('9.7.2', [], [], ['app', 'mysql']), [], 604800, 30, 'mysql://bk@db/app'))->check();
        self::assertStringContainsString('No other', $only[7]->detail);
    }

    /**
     * @param array<string, string>|null $variables null = the statement fails
     * @param list<string>|null $grants null = the statement fails
     * @param list<string>|null $schemas null = the statement fails
     */
    private function prereqServer(?string $version, ?array $variables, ?array $grants, ?array $schemas = null): BinlogServerInterface
    {
        return new class($version, $variables, $grants, $schemas) implements BinlogServerInterface {
            /**
             * @param array<string, string>|null $variables
             * @param list<string>|null $grants
             * @param list<string>|null $schemas
             */
            public function __construct(private readonly ?string $version, private readonly ?array $variables, private readonly ?array $grants, private readonly ?array $schemas)
            {
            }

            public function schemas(): array
            {
                return $this->schemas ?? throw new BinlogUnavailableException('no');
            }

            public function version(): string
            {
                return $this->version ?? throw new BinlogUnavailableException('Connection refused for mysql://u:REDACTED-TEST@db/app');
            }

            public function serverUuid(): string
            {
                return ChainFixtures::UUID;
            }

            public function variables(array $names): array
            {
                return $this->variables ?? throw new BinlogUnavailableException('no');
            }

            public function flushBinaryLogs(): void
            {
            }

            public function binaryLogs(): array
            {
                return [];
            }

            public function status(): array
            {
                return ['file' => 'binlog.000001', 'position' => 4, 'gtid_executed' => ''];
            }

            public function grants(): array
            {
                return $this->grants ?? throw new BinlogUnavailableException('no');
            }
        };
    }

    private function capture(BinlogServerInterface $server, string $mode = IncrementalArtifact::RAW): BinlogCapture
    {
        return new BinlogCapture($server, $this->url, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->dir)), $this->work, new MockClock('2026-11-01 12:00:00 UTC'), mode: $mode);
    }

    private function serverFile(string $name, int $size): void
    {
        file_put_contents($this->dir . '/server/' . $name, str_repeat('b', $size));
    }

    private function lastLine(string $file): string
    {
        $lines = explode("\n", trim((string) file_get_contents($file)));

        return $lines[count($lines) - 1];
    }
}

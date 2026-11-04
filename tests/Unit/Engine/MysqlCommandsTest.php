<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\MysqlClientSchemaInspector;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use Nowo\SiteBackupBundle\Database\SchemaSnapshot;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\UnsafeTableRuleException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function implode;

final class MysqlCommandsTest extends TestCase
{
    private const URL = 'mysql://app:' . EngineFixtures::DB_CANARY . '@db:3307/beacon';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = EngineFixtures::tempDir('sbb-mysql-');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testClientCommandAndWithOptions(): void
    {
        $spec = MysqlDumpCommandFactory::clientFromDatabaseUrl(self::URL, true, 'mariadb', ['--batch', '']);

        self::assertSame(['mariadb', '--skip-ssl-verify-server-cert', '--host=db', '--port=3307', '--user=app', '--batch', '--', 'beacon'], $spec['command']);
        self::assertSame(['MYSQL_PWD' => EngineFixtures::DB_CANARY], $spec['env']);
        self::assertSame('beacon', $spec['database']);
        self::assertSame(['a', 'x', '--', 'db'], MysqlDumpCommandFactory::withOptions(['a', '--', 'db'], ['x']));
        self::assertSame(['a', 'x'], MysqlDumpCommandFactory::withOptions(['a'], ['x']));
        self::assertSame('beacon', MysqlDumpCommandFactory::databaseName(self::URL));

        $socket = MysqlDumpCommandFactory::clientFromDatabaseUrl('mysql://u@localhost/d?unix_socket=/run/mysqld.sock');
        self::assertContains('--socket=/run/mysqld.sock', $socket['command']);

        $this->expectException(InvalidArgumentException::class);
        MysqlDumpCommandFactory::databaseName('mysql://u@h/');
    }

    public function testPlanCommands(): void
    {
        $base     = MysqlDumpCommandFactory::fromDatabaseUrl(self::URL)['command'];
        $commands = BuiltinMysqlDumper::planCommands($base, 'beacon', ['event_log' => TableRule::StructureOnly, 'cache' => TableRule::Skip], true);

        self::assertCount(2, $commands);
        self::assertSame('mysqldump --single-transaction --no-tablespaces --host=db --port=3307 --user=app --set-gtid-purged=OFF --ignore-table=beacon.event_log --ignore-table=beacon.cache -- beacon', implode(' ', $commands[0]));
        self::assertSame('mysqldump --single-transaction --no-tablespaces --host=db --port=3307 --user=app --set-gtid-purged=OFF --no-data -- beacon event_log', implode(' ', $commands[1]));
        self::assertCount(1, BuiltinMysqlDumper::planCommands($base, 'beacon', ['cache' => TableRule::Skip], false));
        self::assertNotContains('--set-gtid-purged=OFF', BuiltinMysqlDumper::planCommands($base, 'beacon', [], false)[0]);
    }

    public function testExtraOptionsAreValidated(): void
    {
        BuiltinMysqlDumper::assertExtraOptions(['--column-statistics=0', '']);

        foreach (['-pX', '--result-file=/tmp/x', '--PASSWORD=x', '--defaults-extra-file=/x', "--x\n", '--ignore-table=a.b'] as $bad) {
            try {
                BuiltinMysqlDumper::assertExtraOptions([$bad]);
                self::fail('Expected refusal of ' . $bad);
            } catch (InvalidBackupOptionsException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBuiltinDumpWithRulesWritesBothPassesOwnerOnly(): void
    {
        $binary    = EngineFixtures::fakeMysqldump($this->dir);
        $inspector = new FixedSchemaInspector(SchemaSnapshot::fromRows(['user', 'event_log', 'cache'], [], [], []));
        $dumper    = new BuiltinMysqlDumper(self::URL, $binary, false, ['--column-statistics=0'], 30, $inspector);
        $target    = $this->dir . '/dump.sql';
        $ticks     = 0;

        $report = $dumper->dump($target, ['event_log' => TableRule::StructureOnly, 'cache' => TableRule::Skip], false, static function () use (&$ticks): void {
            ++$ticks;
        });

        $sql = (string) file_get_contents($target);
        self::assertStringContainsString('--ignore-table=beacon.event_log --ignore-table=beacon.cache', $sql);
        self::assertStringContainsString('--no-data', $sql);
        self::assertStringContainsString('-- beacon event_log', $sql);
        self::assertStringNotContainsString(EngineFixtures::DB_CANARY, $sql, 'the password never reaches argv');
        self::assertSame(EngineFixtures::DB_CANARY . "\n", file_get_contents($this->dir . '/last-pwd'));
        self::assertSame(0o600, fileperms($target) & 0o777);
        self::assertFileDoesNotExist($target . '.structure');
        self::assertSame(2, $report->passes);
        self::assertSame(BuiltinMysqlDumper::FLAVOUR_MYSQL, $report->clientFlavour);
        self::assertTrue($report->gtidPurgedOff);
        self::assertSame('off', $report->toManifest()['gtid_purged']);
        self::assertSame(1, $inspector->calls);
        self::assertGreaterThan(0, $ticks);
    }

    public function testMariaDbClientGetsNoGtidFlagAndNoInspectionWithoutRules(): void
    {
        $binary    = EngineFixtures::fakeMysqldump($this->dir, 'mysqldump from 11.4.3-MariaDB, client 10.19');
        $inspector = new FixedSchemaInspector(SchemaSnapshot::fromRows([], [], [], []));
        $report    = (new BuiltinMysqlDumper(self::URL, $binary, schemaInspector: $inspector))->dump($this->dir . '/d.sql');

        self::assertSame(BuiltinMysqlDumper::FLAVOUR_MARIADB, $report->clientFlavour);
        self::assertFalse($report->gtidPurgedOff);
        self::assertSame('default', $report->toManifest()['gtid_purged']);
        self::assertSame(0, $inspector->calls);
        self::assertStringNotContainsString('gtid', (string) file_get_contents($this->dir . '/d.sql'));
    }

    public function testUnsafeRuleStopsBeforeDumping(): void
    {
        $binary    = EngineFixtures::fakeMysqldump($this->dir);
        $inspector = new FixedSchemaInspector(SchemaSnapshot::fromRows(['user', 'post'], [], [['post', 'user']], []));

        try {
            (new BuiltinMysqlDumper(self::URL, $binary, schemaInspector: $inspector))->dump($this->dir . '/d.sql', ['user' => TableRule::Skip]);
            self::fail('Expected UnsafeTableRuleException');
        } catch (UnsafeTableRuleException) {
        }
        self::assertFileDoesNotExist($this->dir . '/d.sql');

        $report = (new BuiltinMysqlDumper(self::URL, $binary, schemaInspector: $inspector))->dump($this->dir . '/d.sql', ['user' => TableRule::Skip], true);
        self::assertCount(1, $report->warnings);
    }

    public function testDumperFailures(): void
    {
        $failing = EngineFixtures::fakeMysqldump($this->dir, exit: 2);

        try {
            (new BuiltinMysqlDumper(self::URL, $failing))->dump($this->dir . '/d.sql');
            self::fail('Expected DumpFailedException');
        } catch (DumpFailedException $e) {
            self::assertStringStartsWith('Database dump failed: ', $e->getMessage());
            self::assertStringNotContainsString('REDACTED-TEST@', $e->getMessage());
        }

        $cases = [
            static fn () => (new BuiltinMysqlDumper('postgres://u@h/d'))->dump('/nonexistent/x.sql'),
            fn () => (new BuiltinMysqlDumper(self::URL, $this->dir . '/missing-binary'))->dump($this->dir . '/x.sql'),
            fn () => (new BuiltinMysqlDumper(self::URL, EngineFixtures::fakeMysqldump($this->dir)))->dump($this->dir . '/x.sql', ['t' => TableRule::Skip]),
            fn () => (new BuiltinMysqlDumper(self::URL, extraOptions: ['--tab=/tmp']))->dump($this->dir . '/x.sql'),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Expected a failure');
            } catch (DumpFailedException|InvalidBackupOptionsException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDumperTimeouts(): void
    {
        $slow = EngineFixtures::script($this->dir, 'slow-dump', 'if [ "$1" = "--version" ]; then echo mysqldump; exit 0; fi; sleep 30');
        $this->expectException(DumpFailedException::class);
        $this->expectExceptionMessage('timed out after 1 seconds');
        (new BuiltinMysqlDumper(self::URL, $slow, timeoutSeconds: 1))->dump($this->dir . '/x.sql');
    }

    public function testVersionTimeout(): void
    {
        $slow = EngineFixtures::script($this->dir, 'slow-version', 'sleep 40');
        $this->expectException(DumpFailedException::class);
        $this->expectExceptionMessage('--version timed out');
        (new BuiltinMysqlDumper(self::URL, $slow, timeoutSeconds: 1))->probeClient();
    }

    public function testSchemaInspectorParsesTheClientOutput(): void
    {
        $client = EngineFixtures::script($this->dir, 'fake-mysql', <<<'SH'
            case "$*" in
              *TABLE_TYPE*) printf 'user\tBASE TABLE\npost\tBASE TABLE\nactive\tVIEW\n\n';;
              *REFERENCED_TABLE_NAME*) printf 'post\tuser\nbroken-line\n';;
              *VIEW_DEFINITION*) printf 'active\tselect `user`.`id` from `user`\n';;
            esac
            SH);

        $snapshot = (new MysqlClientSchemaInspector(self::URL, $client))->inspect();

        self::assertSame(['user', 'post'], $snapshot->baseTables);
        self::assertSame(['active'], $snapshot->views);
        self::assertSame(['post'], $snapshot->referencingTables('user'));
        self::assertSame(['active'], $snapshot->viewsUsing('user'));
    }

    public function testSchemaInspectorFailures(): void
    {
        $failing = EngineFixtures::script($this->dir, 'failing-mysql', 'echo "ERROR 1045 (28000): Access denied; password=CANARY" >&2; exit 1');
        $slow    = EngineFixtures::script($this->dir, 'slow-mysql', 'sleep 30');

        foreach ([
            [new MysqlClientSchemaInspector(self::URL, $failing), 'Schema inspection failed'],
            [new MysqlClientSchemaInspector(self::URL, $slow, false, 1), 'timed out'],
            [new MysqlClientSchemaInspector(null), 'DATABASE_URL'],
        ] as [$inspector, $message]) {
            try {
                $inspector->inspect();
                self::fail('Expected DumpFailedException');
            } catch (DumpFailedException $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertStringNotContainsString('password=CANARY', $e->getMessage());
            }
        }
    }

    public function testImporterStreamsTheFileThroughTheClient(): void
    {
        $client = EngineFixtures::script($this->dir, 'fake-mysql-import', 'cat > "' . $this->dir . '/received.sql"; echo "$*" > "' . $this->dir . '/argv"; echo "$MYSQL_PWD" > "' . $this->dir . '/pwd"');
        file_put_contents($this->dir . '/in.sql', "INSERT INTO t VALUES (1);\n");

        (new DumpImporter(self::URL, $client))->import($this->dir . '/in.sql');

        self::assertSame("INSERT INTO t VALUES (1);\n", file_get_contents($this->dir . '/received.sql'));
        self::assertSame("--host=db --port=3307 --user=app --batch -- beacon\n", file_get_contents($this->dir . '/argv'));
        self::assertSame(EngineFixtures::DB_CANARY . "\n", file_get_contents($this->dir . '/pwd'));
    }

    public function testImporterFailuresKeepRowDataOut(): void
    {
        file_put_contents($this->dir . '/in.sql', "x\n");
        $failing = EngineFixtures::script($this->dir, 'failing-import', 'cat >/dev/null; echo "ERROR 1064 (42000) at line 3: syntax near \'CANARY-row-data\'" >&2; exit 1');
        $noCode  = EngineFixtures::script($this->dir, 'nocode-import', 'cat >/dev/null; echo "CANARY-row-data" >&2; exit 1');
        $slow    = EngineFixtures::script($this->dir, 'slow-import', 'sleep 30');

        foreach ([
            [new DumpImporter(self::URL, $failing), $this->dir . '/in.sql', 'SQL import failed: ERROR 1064 (42000) at line 3'],
            [new DumpImporter(self::URL, $noCode), $this->dir . '/in.sql', 'bytes of output withheld'],
            [new DumpImporter(self::URL, $slow, false, 1), $this->dir . '/in.sql', 'timed out'],
            [new DumpImporter(self::URL), $this->dir . '/missing.sql', 'missing or not readable'],
            [new DumpImporter('sqlite:///x'), $this->dir . '/in.sql', 'SQL import: DATABASE_URL'],
        ] as [$importer, $file, $message]) {
            try {
                $importer->import($file);
                self::fail('Expected DumpFailedException');
            } catch (DumpFailedException $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertStringNotContainsString('CANARY-row-data', $e->getMessage());
            }
        }
    }
}

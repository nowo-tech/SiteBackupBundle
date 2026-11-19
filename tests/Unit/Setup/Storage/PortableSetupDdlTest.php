<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Storage\CacheDoctrineSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\CacheSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\ChainSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Nowo\SiteBackupBundle\Setup\Storage\SetupTableDdl;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * UT-2: the runtime DDL of the setup tables is portable (MySQL / MariaDB / PostgreSQL / SQLite), and the chain /
 * cache_doctrine storages report a failing database layer once per request instead of swallowing it.
 */
final class PortableSetupDdlTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sbddl-unit-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/progress.json');
        @rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{object, string, string, bool}>
     */
    public static function platforms(): iterable
    {
        yield 'mysql' => [new FakeMySQL80Platform(), 'LONGTEXT', 'DATETIME', true];
        yield 'mariadb (parent class)' => [new FakeMariaDB1010Platform(), 'LONGTEXT', 'DATETIME', true];
        yield 'postgresql' => [new FakePostgreSQLPlatform(), 'TEXT', 'TIMESTAMP(0) WITHOUT TIME ZONE', true];
        yield 'sqlite' => [new FakeSQLitePlatform(), 'CLOB', 'DATETIME', true];
        yield 'sql server' => [new FakeSQLServerPlatform(), 'VARCHAR(MAX)', 'DATETIME2(6)', false];
    }

    #[DataProvider('platforms')]
    public function testProgressTableUsesThePlatformTypes(object $platform, string $text, string $datetime, bool $ifNotExists): void
    {
        $conn    = new PlatformFakeDbalConnection($platform);
        $storage = new DoctrineDbalSetupProgressStorage($conn);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'migrations'));

        $ddl = $conn->ddl[0] ?? '';
        self::assertStringContainsString('payload ' . $text . ' NOT NULL', $ddl);
        self::assertStringContainsString('started_at ' . $datetime . ' DEFAULT NULL', $ddl);
        self::assertStringContainsString('percent DOUBLE PRECISION NOT NULL DEFAULT 0', $ddl);
        self::assertSame($ifNotExists, str_contains($ddl, 'CREATE TABLE IF NOT EXISTS '));
        self::assertSame('migrations', $storage->load()->getCurrentStepId());
    }

    #[DataProvider('platforms')]
    public function testStepTableUsesThePlatformTypes(object $platform, string $text, string $datetime, bool $ifNotExists): void
    {
        $conn    = new PlatformFakeDbalConnection($platform);
        $journal = new DoctrineDbalSetupStepJournal($conn);
        $journal->sync(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, completedStepIds: ['sql_file']));

        $ddl = $conn->ddl[0] ?? '';
        self::assertStringContainsString('message ' . $text . ' DEFAULT NULL', $ddl);
        self::assertStringContainsString('finished_at ' . $datetime . ' DEFAULT NULL', $ddl);
        self::assertStringContainsString('PRIMARY KEY (profile, step_id)', $ddl);
        self::assertSame($ifNotExists, str_contains($ddl, 'CREATE TABLE IF NOT EXISTS '));
        self::assertSame(['sql_file'], $journal->listCompletedStepIds());
    }

    public function testNoPlatformKeepsIfNotExistsWithPortableFallbackTypes(): void
    {
        self::assertSame(['text' => 'TEXT', 'datetime' => 'DATETIME', 'double' => 'DOUBLE PRECISION'], SetupTableDdl::types(null));
        self::assertTrue(SetupTableDdl::supportsIfNotExists(null));

        $conn = new FakeDbalConnection();
        $ddl  = [];
        SetupTableDdl::ensureTable($conn, 't', ['a {text}', 'b {datetime}', 'c {double}'], static function (string $sql) use (&$ddl): void {
            $ddl[] = $sql;
        });
        self::assertSame(["CREATE TABLE IF NOT EXISTS t (\n    a TEXT,\n    b DATETIME,\n    c DOUBLE PRECISION\n)"], $ddl);
        self::assertStringNotContainsString('CLOB', $ddl[0]);
    }

    public function testPlatformMethodsThatFailOrReturnNothingFallBack(): void
    {
        $platform = new class {
            /** @param array<string, mixed> $column */
            public function getClobTypeDeclarationSQL(array $column): string
            {
                throw new RuntimeException('no clob');
            }

            /** @param array<string, mixed> $column */
            public function getDateTimeTypeDeclarationSQL(array $column): string
            {
                return '';
            }
        };

        self::assertSame(['text' => 'TEXT', 'datetime' => 'DATETIME', 'double' => 'DOUBLE PRECISION'], SetupTableDdl::types($platform));
        self::assertFalse(SetupTableDdl::supportsIfNotExists($platform));
    }

    public function testPlatformDetectionIsDuckTyped(): void
    {
        self::assertNull(SetupTableDdl::platform(null));
        self::assertNull(SetupTableDdl::platform(new stdClass()));
        self::assertNull(SetupTableDdl::platform(new class {
            public function getDatabasePlatform(): never
            {
                throw new RuntimeException('server unreachable');
            }
        }));
        self::assertNull(SetupTableDdl::platform(new class {
            public function getDatabasePlatform(): string
            {
                return 'mysql';
            }
        }));

        $platform = new FakePostgreSQLPlatform();
        self::assertSame($platform, SetupTableDdl::platform(new PlatformFakeDbalConnection($platform)));
    }

    public function testCreateFailureIsIgnoredWhenTheTableExists(): void
    {
        // Another process created it at the same moment, or the platform has no IF NOT EXISTS.
        $conn = new PlatformFakeDbalConnection(new FakeSQLServerPlatform());
        $conn->seedRow(['phase' => SetupProgress::PHASE_RUNNING, 'payload' => '{"phase":"running","currentStepId":"seed"}']);
        $conn->failDdl = true;

        $storage = new DoctrineDbalSetupProgressStorage($conn);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'seed'));

        self::assertSame(1, $conn->ddlAttempts);
        self::assertSame('seed', $storage->load()->getCurrentStepId());
    }

    public function testCreateFailureIsRethrownWhenTheTableIsMissing(): void
    {
        $conn          = new PlatformFakeDbalConnection(new FakeMySQL80Platform());
        $conn->failDdl = true;

        $storage = new DoctrineDbalSetupProgressStorage($conn);

        try {
            $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING));
            self::fail('expected the DDL error');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('Unable to prepare setup progress table: ', $e->getMessage());
            self::assertInstanceOf(PDOException::class, $e->getPrevious());
        }

        self::assertFalse(SetupTableDdl::tableExists(new stdClass(), 't'));
    }

    public function testLoadOrFailLetsTheDatabaseErrorThrough(): void
    {
        $conn          = new PlatformFakeDbalConnection(new FakePostgreSQLPlatform());
        $conn->failDdl = true;
        $storage       = new DoctrineDbalSetupProgressStorage($conn);

        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());

        $this->expectException(PDOException::class);
        $storage->loadOrFail();
    }

    public function testLoadOrFailRefusesAnUnusableConnection(): void
    {
        $this->expectException(RuntimeException::class);
        (new DoctrineDbalSetupProgressStorage(null))->loadOrFail();
    }

    public function testChainWarnsOncePerRequestWithoutTheMessage(): void
    {
        $conn          = new PlatformFakeDbalConnection(new FakePostgreSQLPlatform());
        $conn->failDdl = true;
        $logger        = new RecordingLogger();
        $fs            = new FilesystemSetupProgressStorage($this->dir . '/progress.json');
        $chain         = new ChainSetupProgressStorage($fs, new DoctrineDbalSetupProgressStorage($conn), $logger);

        $chain->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'migrations'));
        self::assertSame('migrations', $chain->load()->getCurrentStepId());
        $chain->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'seed'));

        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame('warning', $record['level']);
        self::assertSame(
            ['storage' => 'chain', 'operation' => 'save', 'fallback' => 'filesystem', 'exception_class' => FakeDriverException::class, 'error_code' => '42704'],
            $record['context'],
        );
        self::assertStringNotContainsString('secret-host', $record['message'] . json_encode($record['context']));

        // Next request (kernel.reset): reported again.
        $chain->reset();
        self::assertSame('seed', $chain->load()->getCurrentStepId());
        self::assertCount(2, $logger->records);
        self::assertSame('load', $logger->records[1]['context']['operation']);
    }

    public function testChainWithoutLoggerStillFallsBack(): void
    {
        $conn          = new PlatformFakeDbalConnection(new FakeMySQL80Platform());
        $conn->failDdl = true;
        $chain         = new ChainSetupProgressStorage(new FilesystemSetupProgressStorage($this->dir . '/progress.json'), new DoctrineDbalSetupProgressStorage($conn));

        $chain->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'migrations'));
        self::assertSame('migrations', $chain->load()->getCurrentStepId());
    }

    public function testChainLoadsFromTheDatabaseAfterTheFileIsWiped(): void
    {
        $conn  = new PlatformFakeDbalConnection(new FakeMySQL80Platform());
        $fs    = new FilesystemSetupProgressStorage($this->dir . '/progress.json');
        $chain = new ChainSetupProgressStorage($fs, new DoctrineDbalSetupProgressStorage($conn, stepJournal: new DoctrineDbalSetupStepJournal($conn)), new RecordingLogger());

        $chain->save(new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            currentStepId: 'seed',
            completedStepIds: ['sql_file', 'migrations'],
            startedAt: new DateTimeImmutable('2026-11-19 10:00:00'),
        ));
        unlink($this->dir . '/progress.json');

        self::assertSame(['sql_file', 'migrations'], $chain->load()->getCompletedStepIds());
    }

    public function testCacheDoctrineWarnsOncePerRequest(): void
    {
        $conn          = new PlatformFakeDbalConnection(new FakeMySQL80Platform());
        $conn->failDdl = true;
        $logger        = new RecordingLogger();
        $cache         = new CacheSetupProgressStorage(new ArrayAdapter());
        $storage       = new CacheDoctrineSetupProgressStorage($cache, new DoctrineDbalSetupProgressStorage($conn), null, $logger);

        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'migrations'));
        self::assertSame('migrations', $storage->load()->getCurrentStepId());

        self::assertCount(1, $logger->records);
        self::assertSame(
            ['storage' => 'cache_doctrine', 'operation' => 'load', 'fallback' => 'cache', 'exception_class' => FakeDriverException::class, 'error_code' => '42000'],
            $logger->records[0]['context'],
        );

        $storage->reset();
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'seed'));
        self::assertCount(2, $logger->records);
        self::assertSame('save', $logger->records[1]['context']['operation']);
    }

    public function testCacheDoctrineServesTheDatabaseRowWhenTheCacheRefreshFails(): void
    {
        $conn = new PlatformFakeDbalConnection(new FakePostgreSQLPlatform());
        $db   = new DoctrineDbalSetupProgressStorage($conn);
        $db->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'migrations'));

        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('redis down'));

        $storage = new CacheDoctrineSetupProgressStorage(new CacheSetupProgressStorage($pool), $db);
        self::assertSame('migrations', $storage->load()->getCurrentStepId());
    }

    public function testCacheDoctrineWithAnotherDoctrineStorageUsesLoad(): void
    {
        $other = $this->createStub(SetupProgressStorageInterface::class);
        $other->method('load')->willThrowException(new RuntimeException('down', 7));

        $logger  = new RecordingLogger();
        $storage = new CacheDoctrineSetupProgressStorage(new CacheSetupProgressStorage(new ArrayAdapter()), $other, null, $logger);

        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());
        self::assertSame('7', $logger->records[0]['context']['error_code']);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception_class']);
    }
}

/**
 * {@see FakeDbalConnection} with a DBAL-like platform and DDL recording / failure switches.
 */
final class PlatformFakeDbalConnection extends FakeDbalConnection
{
    /** @var list<string> */
    public array $ddl = [];

    public int $ddlAttempts = 0;

    public bool $failDdl = false;

    public function __construct(private readonly object $platform)
    {
    }

    public function getDatabasePlatform(): object
    {
        return $this->platform;
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        if (str_starts_with($sql, 'CREATE TABLE')) {
            ++$this->ddlAttempts;
            if ($this->failDdl) {
                // The error text a driver gives may name hosts and users: it must never reach the log.
                $sqlState = $this->platform instanceof FakePostgreSQLPlatform ? '42704' : '42000';

                throw new FakeDriverException('SQLSTATE[' . $sqlState . ']: type "clob" does not exist on secret-host', $sqlState);
            }
            $this->ddl[] = $sql;
        }

        return parent::executeStatement($sql, $params);
    }

    public function executeQuery(string $sql, array $params = []): FakeDbalResult
    {
        if (str_starts_with($sql, 'SELECT 1 FROM ')) {
            if (!$this->hasProgressRow()) {
                throw new PDOException('no such table');
            }

            return new FakeDbalResult(null);
        }

        if ($this->failDdl && !$this->hasProgressRow()) {
            throw new PDOException('no such table');
        }

        return parent::executeQuery($sql, $params);
    }

    private function hasProgressRow(): bool
    {
        return parent::executeQuery('SELECT payload FROM progress WHERE id = 1')->fetchAssociative() !== false;
    }
}

/** PDOException codes are SQLSTATE strings. */
final class FakeDriverException extends PDOException
{
    public function __construct(string $message, string $sqlState)
    {
        parent::__construct($message);
        $this->code = $sqlState;
    }
}

class FakeMySQL80Platform
{
    /** @param array<string, mixed> $column */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'LONGTEXT';
    }

    /** @param array<string, mixed> $column */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'DATETIME';
    }

    /** @param array<string, mixed> $column */
    public function getFloatDeclarationSQL(array $column): string
    {
        return 'DOUBLE PRECISION';
    }
}

/** A subclass whose own name does not say "mysql": detection walks the parents. */
final class FakeMariaDB1010Platform extends FakeMySQL80Platform
{
}

final class FakePostgreSQLPlatform
{
    /** @param array<string, mixed> $column */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'TEXT';
    }

    /** @param array<string, mixed> $column */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP(0) WITHOUT TIME ZONE';
    }

    /** @param array<string, mixed> $column */
    public function getFloatDeclarationSQL(array $column): string
    {
        return 'DOUBLE PRECISION';
    }
}

final class FakeSQLitePlatform
{
    /** @param array<string, mixed> $column */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'CLOB';
    }

    /** @param array<string, mixed> $column */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'DATETIME';
    }

    /** @param array<string, mixed> $column */
    public function getFloatDeclarationSQL(array $column): string
    {
        return 'DOUBLE PRECISION';
    }
}

final class FakeSQLServerPlatform
{
    /** @param array<string, mixed> $column */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'VARCHAR(MAX)';
    }

    /** @param array<string, mixed> $column */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'DATETIME2(6)';
    }

    /** @param array<string, mixed> $column */
    public function getFloatDeclarationSQL(array $column): string
    {
        return 'DOUBLE PRECISION';
    }
}

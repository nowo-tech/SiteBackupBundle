<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Memo;

use Nowo\SiteBackupBundle\Setup\ColdStart\MemoizedSchemaExistenceChecker;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineSchemaEmptyDetector;
use Nowo\SiteBackupBundle\Setup\Memo\WorkerTtlMemo;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Contracts\Service\ResetInterface;

final class WorkerMemoTest extends TestCase
{
    private int $now = 1_000;

    private function memo(int $ttl): WorkerTtlMemo
    {
        return new WorkerTtlMemo($ttl, fn (): int => $this->now);
    }

    public function testMemoTtlLifecycle(): void
    {
        $memo = $this->memo(60);
        self::assertTrue($memo->isEnabled());
        self::assertFalse($memo->isFresh());

        $memo->mark();
        self::assertTrue($memo->isFresh());
        $this->now += 59;
        self::assertTrue($memo->isFresh());
        ++$this->now;
        self::assertFalse($memo->isFresh());

        $memo->mark();
        $memo->forget();
        self::assertFalse($memo->isFresh());
    }

    public function testMemoIsNotResettableAndZeroTtlDisables(): void
    {
        self::assertFalse((new ReflectionClass(WorkerTtlMemo::class))->implementsInterface(ResetInterface::class));

        $memo = $this->memo(0);
        self::assertFalse($memo->isEnabled());
        $memo->mark();
        self::assertFalse($memo->isFresh());

        $real = new WorkerTtlMemo(60);
        $real->mark();
        self::assertTrue($real->isFresh());
    }

    public function testSchemaCheckerCachesOnlyPositiveAnswers(): void
    {
        $inner          = new CountingSchemaChecker();
        $inner->answers = [false, false, true, false];
        $checker        = new MemoizedSchemaExistenceChecker($inner, $this->memo(60));

        self::assertFalse($checker->schemaExists());
        self::assertFalse($checker->schemaExists());
        self::assertTrue($checker->schemaExists());
        self::assertSame(3, $inner->calls);

        // Cached for the TTL, even across kernel.reset (memo is not resettable).
        self::assertTrue($checker->schemaExists());
        self::assertTrue($checker->schemaExists());
        self::assertSame(3, $inner->calls);

        $this->now += 61;
        self::assertFalse($checker->schemaExists());
        self::assertSame(4, $inner->calls);
    }

    public function testConnectDetectorMemoizesHealthyAnswer(): void
    {
        $conn     = new CountingConnection();
        $detector = new DoctrineConnectDetector($conn, true, $this->memo(60));

        self::assertFalse($detector->isSetupRequired());
        self::assertFalse($detector->isSetupRequired());
        self::assertSame('ok', $detector->getReason());
        self::assertSame(1, $conn->queries);

        $this->now += 60;
        $conn->fail = true;
        self::assertTrue($detector->isSetupRequired());
        self::assertTrue($detector->isSetupRequired());
        self::assertSame(3, $conn->queries);
    }

    public function testSchemaEmptyDetectorMemoizesNonEmptySchemaOnly(): void
    {
        $conn     = new CountingConnection();
        $detector = new DoctrineSchemaEmptyDetector($conn, true, $this->memo(60));

        $conn->tables = [];
        self::assertTrue($detector->isSetupRequired());
        self::assertTrue($detector->isSetupRequired());
        self::assertSame(2, $conn->listings);

        $conn->tables = ['user'];
        self::assertFalse($detector->isSetupRequired());
        self::assertFalse($detector->isSetupRequired());
        self::assertSame('ok', $detector->getReason());
        self::assertSame(3, $conn->listings);

        $this->now += 60;
        $conn->fail = true;
        // Failure is "unknown": not required, not cached.
        self::assertFalse($detector->isSetupRequired());
        self::assertFalse($detector->isSetupRequired());
        self::assertSame(5, $conn->listings);
    }

    public function testSchemaEmptyDetectorWithoutSchemaManagerIsNotRequired(): void
    {
        $detector = new DoctrineSchemaEmptyDetector(new stdClass(), true, $this->memo(60));
        self::assertFalse($detector->isSetupRequired());
    }
}

final class CountingSchemaChecker implements SchemaExistenceCheckerInterface
{
    /** @var list<bool> */
    public array $answers = [];

    public int $calls = 0;

    public function schemaExists(): bool
    {
        return $this->answers[$this->calls++] ?? false;
    }
}

final class CountingConnection
{
    public bool $fail = false;

    public int $queries = 0;

    public int $listings = 0;

    /** @var list<string> */
    public array $tables = [];

    public function executeQuery(): bool
    {
        ++$this->queries;
        if ($this->fail) {
            throw new RuntimeException('down');
        }

        return true;
    }

    public function createSchemaManager(): object
    {
        $conn = $this;

        return new class($conn) {
            public function __construct(private readonly CountingConnection $conn)
            {
            }

            /** @return list<string> */
            public function listTableNames(): array
            {
                ++$this->conn->listings;
                if ($this->conn->fail) {
                    throw new RuntimeException('down');
                }

                return $this->conn->tables;
            }
        };
    }
}

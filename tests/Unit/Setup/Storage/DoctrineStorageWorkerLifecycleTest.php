<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Same service instances across two "requests" with no kernel.reset in between
 * (FrankenPHP worker mode); the database is dropped between the two requests.
 */
final class DoctrineStorageWorkerLifecycleTest extends TestCase
{
    public function testProgressStorageRecreatesDroppedTableWithoutReset(): void
    {
        $conn    = new DroppableDbalConnection();
        $storage = new DoctrineDbalSetupProgressStorage($conn);

        // Request 1
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'tenant_a', currentStepId: 'a1', percent: 10.0));
        self::assertSame('a1', $storage->load()->getCurrentStepId());

        $conn->dropAll();

        // Request 2 (same instance, no reset())
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_WAITING, profile: 'tenant_b', currentStepId: 'b1', percent: 20.0));
        $loaded = $storage->load();
        self::assertSame('b1', $loaded->getCurrentStepId());
        self::assertSame('tenant_b', $loaded->getProfile());
        self::assertTrue($conn->hasTable(DoctrineDbalSetupProgressStorage::TABLE));
    }

    public function testProgressLoadAfterDropReturnsEmptyAndRecreatesTable(): void
    {
        $conn    = new DroppableDbalConnection();
        $storage = new DoctrineDbalSetupProgressStorage($conn);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'x'));

        $conn->dropAll();

        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());
        self::assertTrue($conn->hasTable(DoctrineDbalSetupProgressStorage::TABLE));
    }

    public function testJournalRecreatesDroppedTableWithoutReset(): void
    {
        $conn    = new DroppableDbalConnection();
        $journal = new DoctrineDbalSetupStepJournal($conn);
        $storage = new DoctrineDbalSetupProgressStorage($conn, DoctrineDbalSetupProgressStorage::TABLE, $journal, true);

        // Request 1
        $storage->save(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'p1',
            currentStepId: 'admin',
            completedStepIds: ['requirements'],
            updatedAt: new DateTimeImmutable('2026-09-23T10:00:00+00:00'),
        ));
        self::assertSame(['requirements'], $journal->listCompletedStepIds('p1'));

        $conn->dropAll();

        // Request 2: reads first (would have thrown on missing table), then writes.
        self::assertSame([], $journal->listCompletedStepIds('p1'));
        $conn->dropAll();
        self::assertNull($journal->latestFinishedStep('p1'));
        $conn->dropAll();

        $storage->save(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'p2',
            currentStepId: 'admin',
            completedStepIds: ['migrations'],
            updatedAt: new DateTimeImmutable('2026-09-23T11:00:00+00:00'),
        ));
        self::assertSame(['migrations'], $journal->listCompletedStepIds('p2'));
        self::assertSame([], $journal->listCompletedStepIds('p1'));
        $latest = $journal->latestFinishedStep('p2');
        self::assertNotNull($latest);
        self::assertSame('migrations', $latest['step_id']);

        $conn->dropAll();
        $journal->clear('p2');
        self::assertTrue($conn->hasTable(DoctrineDbalSetupStepJournal::TABLE));
    }

    public function testRetryRethrowsWhenFailureIsNotAMissingTable(): void
    {
        $conn    = new DroppableDbalConnection();
        $storage = new DoctrineDbalSetupProgressStorage($conn);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING));

        $conn->failWrites = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to persist setup progress to database');
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_WAITING));
    }

    public function testProgressFirstCallFailureIsNotRetried(): void
    {
        $conn             = new DroppableDbalConnection();
        $conn->failWrites = true;
        $storage          = new DoctrineDbalSetupProgressStorage($conn);

        try {
            $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING));
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('write failed', $e->getMessage());
        }
        self::assertSame(1, $conn->ddlCount);
    }

    public function testFirstCallFailureIsNotRetried(): void
    {
        $conn             = new DroppableDbalConnection();
        $conn->failWrites = true;
        $journal          = new DoctrineDbalSetupStepJournal($conn);

        $this->expectException(RuntimeException::class);
        $journal->sync(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'x'));
    }

    public function testResetForcesSchemaCheckOnNextCall(): void
    {
        $conn    = new DroppableDbalConnection();
        $journal = new DoctrineDbalSetupStepJournal($conn);
        $storage = new DoctrineDbalSetupProgressStorage($conn, DoctrineDbalSetupProgressStorage::TABLE, $journal, true);

        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'p', currentStepId: 's'));
        $ddlBefore = $conn->ddlCount;

        $storage->reset();
        $journal->reset();
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'p', currentStepId: 's'));

        self::assertSame($ddlBefore + 2, $conn->ddlCount);
    }
}

/**
 * {@see FakeDbalConnection} that throws "no such table" once tables are dropped.
 */
final class DroppableDbalConnection
{
    public bool $failWrites = false;

    public int $ddlCount = 0;

    private FakeDbalConnection $inner;

    /** @var array<string, true> */
    private array $tables = [];

    public function __construct()
    {
        $this->inner = new FakeDbalConnection();
    }

    public function dropAll(): void
    {
        $this->inner  = new FakeDbalConnection();
        $this->tables = [];
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * @param list<mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): int
    {
        $this->guard($sql);
        if ($this->failWrites && !str_contains(strtolower($sql), 'create table')) {
            throw new RuntimeException('write failed');
        }

        return $this->inner->executeStatement($sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    public function executeQuery(string $sql, array $params = []): FakeDbalResult
    {
        $this->guard($sql);

        return $this->inner->executeQuery($sql, $params);
    }

    private function guard(string $sql): void
    {
        $table = str_contains($sql, DoctrineDbalSetupStepJournal::TABLE)
            ? DoctrineDbalSetupStepJournal::TABLE
            : DoctrineDbalSetupProgressStorage::TABLE;

        if (str_contains(strtolower($sql), 'create table')) {
            $this->tables[$table] = true;
            ++$this->ddlCount;

            return;
        }

        if (!isset($this->tables[$table])) {
            throw new RuntimeException('no such table: ' . $table);
        }
    }
}

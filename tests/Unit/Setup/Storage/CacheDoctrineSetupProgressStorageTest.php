<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Nowo\SiteBackupBundle\Setup\Storage\CacheDoctrineSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\CacheSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CacheDoctrineSetupProgressStorageTest extends TestCase
{
    public function testLoadFallsBackToCacheWhenDoctrineIdle(): void
    {
        $doctrine = $this->createStub(SetupProgressStorageInterface::class);
        $doctrine->method('load')->willReturn(new SetupProgress());

        $cache = new CacheSetupProgressStorage(new ArrayAdapter());
        $cache->save(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            currentStepId: 'database_url',
            startedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ));

        $schema = $this->createStub(SchemaExistenceCheckerInterface::class);
        $schema->method('schemaExists')->willReturn(false);

        $storage = new CacheDoctrineSetupProgressStorage($cache, $doctrine, $schema);
        self::assertSame('database_url', $storage->load()->getCurrentStepId());
    }

    public function testClearsCacheWhenSchemaLostAfterMigrations(): void
    {
        $doctrine = $this->createStub(SetupProgressStorageInterface::class);
        $doctrine->method('load')->willReturn(new SetupProgress());

        $cache = new CacheSetupProgressStorage(new ArrayAdapter());
        $cache->save(new SetupProgress(
            phase: SetupProgress::PHASE_FAILED,
            currentStepId: 'seed_platform',
            completedStepIds: ['migrations_6'],
            startedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ));

        $schema = $this->createStub(SchemaExistenceCheckerInterface::class);
        $schema->method('schemaExists')->willReturn(false);

        $storage = new CacheDoctrineSetupProgressStorage($cache, $doctrine, $schema);
        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());
    }

    public function testSaveSoftFailsDoctrine(): void
    {
        $doctrine = $this->createMock(SetupProgressStorageInterface::class);
        $doctrine->expects(self::once())->method('save')->willThrowException(new RuntimeException('Unknown database'));

        $cache   = new CacheSetupProgressStorage(new ArrayAdapter());
        $storage = new CacheDoctrineSetupProgressStorage($cache, $doctrine);

        $storage->save(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            currentStepId: 'requirements',
            startedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ));

        self::assertSame('requirements', $cache->load()->getCurrentStepId());
    }
}

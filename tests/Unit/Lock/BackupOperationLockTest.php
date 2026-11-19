<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Lock;

use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\OperationLockUnavailableException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Lock\LocalLockFallbackPolicyInterface;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-RUNTIME-003: the operation lock's conflict, store-failure, refresh and release branches.
 */
final class BackupOperationLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-lock-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTheFlockFallbackIsExclusiveAndReportsItsRetryAfter(): void
    {
        $lock = new BackupOperationLock($this->dir, retryAfterSeconds: 42);
        self::assertFalse($lock->usesSharedStore());
        self::assertSame(42, $lock->getRetryAfterSeconds());

        $held = $lock->acquire();
        $held->refresh();

        try {
            $lock->acquire();
            self::fail('Expected BackupAlreadyRunningException');
        } catch (BackupAlreadyRunningException $e) {
            self::assertSame(42, $e->getRetryAfterSeconds());
        }

        // Another lock file name is another lock (the setup lock next to the operation lock).
        (new BackupOperationLock($this->dir, fileName: '.setup.lock'))->acquire()->release();

        $held->release();
        $held->release();
        $lock->acquire()->release();
    }

    public function testAnUnusableLockFileIsNotAConflict(): void
    {
        (new Filesystem())->mkdir($this->dir . '/.operation.lock');

        $this->expectException(OperationLockUnavailableException::class);
        $this->expectExceptionMessage('cannot be opened');
        (new BackupOperationLock($this->dir))->acquire();
    }

    public function testALockDirectoryThatCannotBeCreatedIsNotAConflict(): void
    {
        (new Filesystem())->dumpFile($this->dir . '/file', 'x');

        $this->expectException(OperationLockUnavailableException::class);
        $this->expectExceptionMessage('cannot be created');
        (new BackupOperationLock($this->dir . '/file/locks'))->acquire();
    }

    public function testASharedStoreConflictIsAConflict(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $lock    = new BackupOperationLock($this->dir, $factory, 'k', 60.0);
        self::assertTrue($lock->usesSharedStore());

        $held = $lock->acquire();
        $held->refresh();

        try {
            $lock->acquire();
            self::fail('Expected BackupAlreadyRunningException');
        } catch (BackupAlreadyRunningException $e) {
            self::assertSame(300, $e->getRetryAfterSeconds());
        }
        $held->release();
        $lock->acquire()->release();
    }

    public function testAFailingStoreIsNotAConflictAndNothingStarts(): void
    {
        $lock = new BackupOperationLock($this->dir, new LockFactory(new FailingStore(save: true)), 'k', 60.0);

        $this->expectException(OperationLockUnavailableException::class);
        $lock->acquire();
    }

    public function testTheSetupLockFallsBackToTheLocalFileWhenTheStoreFails(): void
    {
        $logger = new RecordingLogger();
        $lock   = new BackupOperationLock($this->dir, new LockFactory(new FailingStore(save: true)), 'k.setup', 60.0, fileName: '.setup.lock', logger: $logger, localFallback: $this->policy(true));

        $held = $lock->acquire();
        self::assertFileExists($this->dir . '/.setup.lock');
        self::assertStringContainsString('local lock file', $logger->records[0]['message'] ?? '');

        try {
            $lock->acquire();
            self::fail('The local file still serialises this host.');
        } catch (BackupAlreadyRunningException) {
        }
        $held->release();
    }

    public function testThePolicyCanRefuseTheLocalFallback(): void
    {
        // A configured instance (audit UT-1): the store outage is reported, servers keep excluding each other.
        $lock = new BackupOperationLock($this->dir, new LockFactory(new FailingStore(save: true)), 'k.setup', 60.0, fileName: '.setup.lock', localFallback: $this->policy(false));

        $this->expectException(OperationLockUnavailableException::class);
        $lock->acquire();
    }

    public function testAStoreFailureWhileRefreshingIsLoggedAndRetried(): void
    {
        $logger = new RecordingLogger();
        $store  = new FailingStore(refresh: true);
        $held   = (new BackupOperationLock($this->dir, new LockFactory($store), 'k', 60.0, logger: $logger))->acquire();

        $held->refresh();
        self::assertStringContainsString('could not be refreshed', $logger->records[0]['message'] ?? '');
        $held->release();
    }

    public function testALockLostToAnotherHolderStopsTheOperation(): void
    {
        $held = (new BackupOperationLock($this->dir, new LockFactory(new FailingStore(conflictOnRefresh: true)), 'k', 60.0))->acquire();

        $this->expectException(LockConflictedException::class);
        $held->refresh();
    }

    public function testAStoreFailureWhileReleasingIsLoggedNotThrown(): void
    {
        $logger = new RecordingLogger();
        $held   = (new BackupOperationLock($this->dir, new LockFactory(new FailingStore(delete: true)), 'k', 60.0, logger: $logger))->acquire();

        $held->release();
        self::assertStringContainsString('could not be released', $logger->records[0]['message'] ?? '');
    }

    private function policy(bool $allows): LocalLockFallbackPolicyInterface
    {
        return new class($allows) implements LocalLockFallbackPolicyInterface {
            public function __construct(private readonly bool $allows)
            {
            }

            public function allowsLocalFallback(): bool
            {
                return $this->allows;
            }
        };
    }
}

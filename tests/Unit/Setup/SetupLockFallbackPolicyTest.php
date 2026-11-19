<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\SetupLockFallbackPolicy;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;
use function uniqid;

/**
 * Audit UT-1: the setup lock may use its local file only while the instance is genuinely cold.
 */
final class SetupLockFallbackPolicyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-fallback-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testColdOnlyAllowsTheFallbackBeforeTheFirstInstallOnly(): void
    {
        $markers = $this->markers();
        self::assertTrue((new SetupLockFallbackPolicy($markers))->allowsLocalFallback(), 'cold: no marker, no durable store');
        self::assertTrue((new SetupLockFallbackPolicy($markers, new FakeDurableSetupDoneStore(false)))->allowsLocalFallback());
        self::assertFalse((new SetupLockFallbackPolicy($markers, new FakeDurableSetupDoneStore(true)))->allowsLocalFallback(), 'durable store says done');

        // The durable store is usually the unreachable database: the file markers decide.
        $throwing = $this->createMock(DurableSetupDoneStoreInterface::class);
        $throwing->method('isDone')->willThrowException(new RuntimeException('db down'));
        self::assertTrue((new SetupLockFallbackPolicy($markers, $throwing))->allowsLocalFallback());

        $markers->markReopened();
        self::assertFalse((new SetupLockFallbackPolicy($markers))->allowsLocalFallback(), 're-opened instance');

        $done = $this->markers('done');
        $done->markDone();
        self::assertFalse((new SetupLockFallbackPolicy($done, $throwing))->allowsLocalFallback(), 'setup.done');
    }

    public function testAlwaysAndNever(): void
    {
        $done = $this->markers();
        $done->markDone();
        self::assertTrue((new SetupLockFallbackPolicy($done, null, SetupLockFallbackPolicy::ALWAYS))->allowsLocalFallback());
        self::assertFalse((new SetupLockFallbackPolicy($this->markers('cold'), null, SetupLockFallbackPolicy::NEVER))->allowsLocalFallback());
    }

    private function markers(string $name = 'm'): SetupMarkerManager
    {
        return new SetupMarkerManager($this->dir . '/' . $name . '/required', $this->dir . '/' . $name . '/done');
    }
}

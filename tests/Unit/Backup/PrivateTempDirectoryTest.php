<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Backup;

use Nowo\SiteBackupBundle\Backup\PrivateTempDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function sprintf;

/**
 * REQ-SEC-004 CG L1: backup / verify / restore staging directories are 0700 under an unpredictable name.
 */
final class PrivateTempDirectoryTest extends TestCase
{
    public function testCreatesOwnerOnlyDirectoriesWithRandomSuffix(): void
    {
        $a = PrivateTempDirectory::create('nowo-sbb-ptd-');
        $b = PrivateTempDirectory::create('nowo-sbb-ptd-');

        try {
            self::assertNotSame($a, $b);
            foreach ([$a, $b] as $dir) {
                self::assertDirectoryExists($dir);
                self::assertMatchesRegularExpression('#/nowo-sbb-ptd-[0-9a-f]{32}$#', $dir);
                self::assertSame('700', substr(sprintf('%o', fileperms($dir)), -3));
                self::assertSame(rtrim(sys_get_temp_dir(), '/'), dirname($dir));
            }
        } finally {
            (new Filesystem())->remove([$a, $b]);
        }
    }

    public function testThrowsWhenTheBaseDirectoryIsUnusable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create a private temporary directory');

        PrivateTempDirectory::create('x-', sys_get_temp_dir() . '/nowo-sbb-missing-' . bin2hex(random_bytes(6)) . '/nested');
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\SetupAccessTokenStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sprintf;

/**
 * REQ-SEC-004 CG F1: the one-time setup token is stored hashed, owner-only, and verified in constant time.
 */
final class SetupAccessTokenStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-token-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testIssueStoresOnlyAnOwnerOnlyHash(): void
    {
        $store = new SetupAccessTokenStore($this->dir . '/site-backup/setup.token');
        self::assertFalse($store->hasToken());
        self::assertFalse($store->isValid('anything'));

        $token = $store->issue();
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $token);
        self::assertTrue($store->hasToken());
        self::assertSame($this->dir . '/site-backup/setup.token', $store->getTokenFile());

        $stored = (string) file_get_contents($store->getTokenFile());
        self::assertStringNotContainsString($token, $stored);
        self::assertSame(hash('sha256', $token), trim($stored));
        self::assertSame('600', substr(sprintf('%o', fileperms($store->getTokenFile())), -3));

        self::assertTrue($store->isValid($token));
        self::assertFalse($store->isValid(''));
        self::assertFalse($store->isValid($token . 'x'));

        // A new token replaces the previous one.
        $second = $store->issue();
        self::assertNotSame($token, $second);
        self::assertFalse($store->isValid($token));
        self::assertTrue($store->isValid($second));

        $store->clear();
        self::assertFalse($store->hasToken());
        self::assertFalse($store->isValid($second));
    }

    public function testEmptyTokenFileNeverMatches(): void
    {
        (new Filesystem())->dumpFile($this->dir . '/setup.token', "\n");
        $store = new SetupAccessTokenStore($this->dir . '/setup.token');

        self::assertTrue($store->hasToken());
        self::assertFalse($store->isValid(hash('sha256', '')));
        self::assertFalse($store->isValid('x'));
    }
}

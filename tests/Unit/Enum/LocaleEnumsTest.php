<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Enum;

use Nowo\SiteBackupBundle\Enum\LocaleInPathMode;
use Nowo\SiteBackupBundle\Enum\UnlocalizedLocaleMode;
use PHPUnit\Framework\TestCase;

final class LocaleEnumsTest extends TestCase
{
    public function testLocaleInPathModeHelpers(): void
    {
        self::assertFalse(LocaleInPathMode::Never->usesLocalePrefix());
        self::assertTrue(LocaleInPathMode::Always->usesLocalePrefix());
        self::assertTrue(LocaleInPathMode::Both->usesLocalePrefix());

        self::assertFalse(LocaleInPathMode::Never->registersLocalizedRoutes());
        self::assertTrue(LocaleInPathMode::Always->registersLocalizedRoutes());
        self::assertTrue(LocaleInPathMode::Both->registersLocalizedRoutes());
    }

    public function testUnlocalizedLocaleModeValues(): void
    {
        self::assertSame('serve', UnlocalizedLocaleMode::Serve->value);
        self::assertSame('redirect', UnlocalizedLocaleMode::Redirect->value);
    }
}

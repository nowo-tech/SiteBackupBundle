<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Routing;

use Nowo\SiteBackupBundle\Routing\SetupLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SetupLocalesTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function provideLocales(): iterable
    {
        yield 'list' => [['es', 'en'], ['es', 'en']];
        yield 'keyed, padded, duplicated, non-string' => [['a' => ' es ', 'b' => 'en', 'c' => 'es', 'd' => '', 'e' => 3], ['es', 'en']];
        yield 'json string' => ['["pt","en"]', ['pt', 'en']];
        yield 'csv string' => ['it, en', ['it', 'en']];
        yield 'empty string' => ['', []];
        yield 'not a list' => [42, []];
        yield 'null' => [null, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideLocales')]
    public function testNormalize(mixed $locales, array $expected): void
    {
        self::assertSame($expected, SetupLocales::normalize($locales));
    }

    public function testLocalizedPrefixes(): void
    {
        self::assertSame(['/_setup', '/es/_setup', '/en/_setup'], SetupLocales::localizedPrefixes(['/_setup'], 'es,en', '/_setup'));
        self::assertSame(['/_setup'], SetupLocales::localizedPrefixes(['/_setup'], [], '/_setup'));
    }

    public function testWithLocalizedSetupPattern(): void
    {
        $patterns = SetupLocales::withLocalizedSetupPattern(['#^/x#'], ['es', 'en'], '/_setup');
        self::assertSame(['#^/x#', '#^/(es|en)/_setup(/|$|\?)#'], $patterns);
        self::assertSame($patterns, SetupLocales::withLocalizedSetupPattern($patterns, ['es', 'en'], '/_setup'));
        self::assertSame(['#^/x#'], SetupLocales::withLocalizedSetupPattern(['#^/x#'], [], '/_setup'));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;

use function dirname;

/**
 * The page contract with the German catalogue: every label of every page exists in "de" (no raw keys).
 */
final class PageContractGermanTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        return PageContractTest::pageFixtures();
    }

    protected static function locale(): string
    {
        return 'de';
    }

    protected static function templatePaths(): array
    {
        $views = dirname(__DIR__, 3) . '/src/Resources/views';

        return ['NowoSiteBackupBundle' => $views, '!NowoSiteBackupBundle' => $views];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }
}

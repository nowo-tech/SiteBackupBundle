<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Routing;

use Nowo\SiteBackupBundle\Controller\SetupUnlocalizedLocaleRedirectController;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Routing\SetupRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SetupRouteLoaderTest extends TestCase
{
    public function testSupportsType(): void
    {
        $loader = new SetupRouteLoader('/_setup', 'never', 'en', ['en']);
        self::assertTrue($loader->supports('.', 'nowo_site_backup_setup'));
        self::assertFalse($loader->supports('.', 'nowo_auth_kit'));
        self::assertFalse($loader->supports('.'));
    }

    public function testNeverModeRegistersBareRoutesOnly(): void
    {
        $loader     = new SetupRouteLoader('/_setup', 'never', 'en', ['en', 'es']);
        $collection = $loader->load('.');

        $index    = $collection->get('nowo_site_backup_setup');
        $done     = $collection->get('nowo_site_backup_setup_done');
        $progress = $collection->get('nowo_site_backup_setup_progress');
        $advance  = $collection->get('nowo_site_backup_setup_advance');

        self::assertNotNull($index);
        self::assertNotNull($done);
        self::assertNotNull($progress);
        self::assertNotNull($advance);

        self::assertSame('/_setup', $index->getPath());
        self::assertSame('/_setup/done', $done->getPath());
        self::assertSame('/_setup/api/progress', $progress->getPath());
        self::assertSame('/_setup/api/advance', $advance->getPath());

        self::assertNull($collection->get('nowo_site_backup_setup_unlocalized'));
        self::assertSame(SetupWizardController::class . '::index', $index->getDefault('_controller'));
    }

    public function testAlwaysModeRegistersLocalizedRoutesOnly(): void
    {
        $loader     = new SetupRouteLoader('/_setup', 'always', 'en', ['en', 'es']);
        $collection = $loader->load('.');

        $main = $collection->get('nowo_site_backup_setup');
        self::assertNotNull($main);
        self::assertSame('/{_locale}/_setup', $main->getPath());
        self::assertSame('en', $main->getDefault('_locale'));
        self::assertSame('en|es', $main->getRequirement('_locale'));

        $done = $collection->get('nowo_site_backup_setup_done');
        self::assertNotNull($done);
        self::assertSame('/{_locale}/_setup/done', $done->getPath());

        self::assertNull($collection->get('nowo_site_backup_setup_unlocalized'));
    }

    public function testBothRedirectModeRegistersDualRoutes(): void
    {
        $loader     = new SetupRouteLoader('/_setup', 'both', 'en', ['en', 'es'], 'redirect');
        $collection = $loader->load('.');

        $localized = $collection->get('nowo_site_backup_setup');
        self::assertNotNull($localized);
        self::assertSame('/{_locale}/_setup', $localized->getPath());
        self::assertSame('en', $localized->getDefault('_locale'));

        $unlocalized = $collection->get('nowo_site_backup_setup_unlocalized');
        self::assertNotNull($unlocalized);
        self::assertSame('/_setup', $unlocalized->getPath());
        self::assertSame(
            SetupUnlocalizedLocaleRedirectController::class . '::redirect',
            $unlocalized->getDefault('_controller'),
        );
        self::assertSame('nowo_site_backup_setup', $unlocalized->getDefault('_site_backup_canonical_route'));

        $doneLoc   = $collection->get('nowo_site_backup_setup_done');
        $doneUnloc = $collection->get('nowo_site_backup_setup_done_unlocalized');
        self::assertNotNull($doneLoc);
        self::assertNotNull($doneUnloc);
        self::assertSame('/{_locale}/_setup/done', $doneLoc->getPath());
        self::assertSame('/_setup/done', $doneUnloc->getPath());
        self::assertSame('nowo_site_backup_setup_done', $doneUnloc->getDefault('_site_backup_canonical_route'));
    }

    public function testBothServeModeRegistersDualRoutesWithDefaultLocale(): void
    {
        $loader     = new SetupRouteLoader('/_setup', 'both', 'en', ['en', 'es'], 'serve');
        $collection = $loader->load('.');

        $localized = $collection->get('nowo_site_backup_setup');
        self::assertNotNull($localized);
        self::assertSame('/{_locale}/_setup', $localized->getPath());

        $unlocalized = $collection->get('nowo_site_backup_setup_unlocalized');
        self::assertNotNull($unlocalized);
        self::assertSame('/_setup', $unlocalized->getPath());
        self::assertSame('en', $unlocalized->getDefault('_locale'));
        self::assertSame(
            SetupWizardController::class . '::index',
            $unlocalized->getDefault('_controller'),
        );
        self::assertArrayNotHasKey('_site_backup_canonical_route', $unlocalized->getDefaults());
    }

    public function testCannotLoadTwice(): void
    {
        $loader = new SetupRouteLoader('/_setup', 'never', 'en', ['en']);
        $loader->load('.');

        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }

    public function testMethods(): void
    {
        $loader     = new SetupRouteLoader('/_setup', 'never', 'en', ['en']);
        $collection = $loader->load('.');

        $index    = $collection->get('nowo_site_backup_setup');
        $done     = $collection->get('nowo_site_backup_setup_done');
        $progress = $collection->get('nowo_site_backup_setup_progress');
        $advance  = $collection->get('nowo_site_backup_setup_advance');
        self::assertNotNull($index);
        self::assertNotNull($done);
        self::assertNotNull($progress);
        self::assertNotNull($advance);

        self::assertSame(['GET', 'POST'], $index->getMethods());
        self::assertSame(['GET'], $done->getMethods());
        self::assertSame(['GET'], $progress->getMethods());
        self::assertSame(['POST'], $advance->getMethods());
    }

    public function testCustomPrefix(): void
    {
        $loader     = new SetupRouteLoader('/setup', 'always', 'fr', ['fr', 'de']);
        $collection = $loader->load('.');

        $main = $collection->get('nowo_site_backup_setup');
        self::assertNotNull($main);
        self::assertSame('/{_locale}/setup', $main->getPath());
        self::assertSame('fr', $main->getDefault('_locale'));
        self::assertSame('fr|de', $main->getRequirement('_locale'));
    }

    public function testAllRoutesRegisteredInBothMode(): void
    {
        $loader     = new SetupRouteLoader('/_setup', 'both', 'en', ['en', 'es'], 'redirect');
        $collection = $loader->load('.');

        $expected = [
            'nowo_site_backup_setup',
            'nowo_site_backup_setup_unlocalized',
            'nowo_site_backup_setup_done',
            'nowo_site_backup_setup_done_unlocalized',
            'nowo_site_backup_setup_progress',
            'nowo_site_backup_setup_progress_unlocalized',
            'nowo_site_backup_setup_advance',
            'nowo_site_backup_setup_advance_unlocalized',
        ];

        foreach ($expected as $name) {
            self::assertNotNull($collection->get($name), "Route {$name} should exist");
        }

        self::assertCount(8, $collection);
    }

    public function testDisabledReturnsEmptyCollection(): void
    {
        $loader     = new SetupRouteLoader('/_setup', 'never', 'en', ['en'], 'redirect', false);
        $collection = $loader->load('.');

        self::assertCount(0, $collection);
    }
}

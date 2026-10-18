<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Routing;

use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Routing\PanelRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeFileLoader;

use function array_keys;
use function dirname;
use function sort;

final class PanelRouteLoaderTest extends TestCase
{
    public function testSupportsOnlyItsType(): void
    {
        $loader = new PanelRouteLoader('/_site_backup');

        self::assertTrue($loader->supports('.', PanelRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'attribute'));
        self::assertFalse($loader->supports('.'));
    }

    public function testEnabledImportsPanelAttributeRoutesUnderThePrefix(): void
    {
        $collection = $this->loader('/ops/backup/', true)->load('.', PanelRouteLoader::TYPE);

        $names = array_keys($collection->all());
        sort($names);
        self::assertSame(['nowo_site_backup_create', 'nowo_site_backup_delete_confirm', 'nowo_site_backup_history', 'nowo_site_backup_panel', 'nowo_site_backup_progress', 'nowo_site_backup_restore_confirm'], $names);
        self::assertSame('/ops/backup/restore/{id}', $collection->get('nowo_site_backup_restore_confirm')?->getPath());
        // Same as the former YAML import with prefix (trailing_slash_on_root: true).
        self::assertSame('/ops/backup/', $collection->get('nowo_site_backup_panel')?->getPath());
        self::assertSame('/ops/backup/progress.json', $collection->get('nowo_site_backup_progress')?->getPath());
        self::assertSame(SiteBackupPanelController::class . '::history', $collection->get('nowo_site_backup_history')?->getDefault('_controller'));
    }

    public function testDisabledPanelRegistersNoRoutes(): void
    {
        self::assertCount(0, $this->loader('/_site_backup', false)->load('.', PanelRouteLoader::TYPE));
    }

    private function loader(string $prefix, bool $enabled): PanelRouteLoader
    {
        $loader = new PanelRouteLoader($prefix, $enabled);
        $loader->setResolver(new LoaderResolver([
            $loader,
            new AttributeFileLoader(new FileLocator(dirname(__DIR__, 3)), new AttributeRouteControllerLoader()),
        ]));

        return $loader;
    }
}

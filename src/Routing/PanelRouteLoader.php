<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Routing;

use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

use function dirname;
use function rtrim;

/**
 * v1.19+: registers the panel routes (attribute routes of {@see SiteBackupPanelController}) only when
 * panel.enabled is true. With the panel disabled the controller service is removed, so importing its
 * routes would turn every panel URL into a 500 instead of a 404.
 */
final class PanelRouteLoader extends Loader
{
    public const TYPE = 'nowo_site_backup_panel';

    public function __construct(
        private readonly string $pathPrefix,
        private readonly bool $enabled = true,
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if (!$this->enabled) {
            return new RouteCollection();
        }

        $imported   = $this->import(dirname(__DIR__) . '/Controller/SiteBackupPanelController.php', 'attribute');
        $collection = $imported instanceof RouteCollection ? $imported : new RouteCollection();
        $collection->addPrefix(rtrim($this->pathPrefix, '/'));

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}

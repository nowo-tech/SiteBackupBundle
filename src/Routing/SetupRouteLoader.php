<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Routing;

use Nowo\SiteBackupBundle\Controller\SetupUnlocalizedLocaleRedirectController;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Enum\LocaleInPathMode;
use Nowo\SiteBackupBundle\Enum\UnlocalizedLocaleMode;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers setup wizard routes with optional locale-in-path support.
 */
final class SetupRouteLoader extends Loader
{
    public const UNLOCALIZED_ROUTE_SUFFIX = '_unlocalized';

    private bool $loaded = false;

    private readonly LocaleInPathMode $localeInPathMode;

    private readonly UnlocalizedLocaleMode $unlocalizedMode;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly string $pathPrefix,
        string $localeInPath,
        private readonly string $defaultLocale,
        private readonly array $enabledLocales,
        string $unlocalizedMode = 'redirect',
        private readonly bool $enabled = true,
    ) {
        $this->localeInPathMode = LocaleInPathMode::from($localeInPath);
        $this->unlocalizedMode  = UnlocalizedLocaleMode::from($unlocalizedMode);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('SiteBackup setup routes already loaded.');
        }

        $this->loaded = true;
        $collection   = new RouteCollection();

        if (!$this->enabled) {
            return $collection;
        }

        $prefix = rtrim($this->pathPrefix, '/');

        $this->addSetupRoute($collection, 'nowo_site_backup_setup', $prefix, SetupWizardController::class . '::index', ['GET', 'POST']);
        $this->addSetupRoute($collection, 'nowo_site_backup_setup_done', $prefix . '/done', SetupWizardController::class . '::done', ['GET']);
        $this->addSetupRoute($collection, 'nowo_site_backup_setup_progress', $prefix . '/api/progress', SetupWizardController::class . '::progress', ['GET']);
        $this->addSetupRoute($collection, 'nowo_site_backup_setup_advance', $prefix . '/api/advance', SetupWizardController::class . '::advanceApi', ['POST']);

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_site_backup_setup';
    }

    /**
     * @param list<string> $methods
     */
    private function addSetupRoute(
        RouteCollection $collection,
        string $name,
        string $path,
        string $controller,
        array $methods,
    ): void {
        $defaults = ['_controller' => $controller];

        if ($this->localeInPathMode->registersLocalizedRoutes()) {
            $collection->add($name, $this->createLocalizedRoute($path, $defaults, $methods));
        }

        if ($this->localeInPathMode === LocaleInPathMode::Never) {
            $collection->add($name, $this->createBareRoute($path, $defaults, $methods));

            return;
        }

        if ($this->localeInPathMode !== LocaleInPathMode::Both) {
            return;
        }

        $bareName = $name . self::UNLOCALIZED_ROUTE_SUFFIX;

        if ($this->unlocalizedMode === UnlocalizedLocaleMode::Redirect) {
            $collection->add($bareName, $this->createBareRoute(
                $path,
                [
                    '_controller'                  => SetupUnlocalizedLocaleRedirectController::class . '::redirect',
                    '_site_backup_canonical_route' => $name,
                ] + $defaults,
                $methods,
            ));

            return;
        }

        $collection->add($bareName, $this->createBareRoute(
            $path,
            ['_locale' => $this->defaultLocale] + $defaults,
            $methods,
        ));
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     */
    private function createBareRoute(string $path, array $defaults, array $methods): Route
    {
        return new Route($path, $defaults, [], [], '', [], $methods);
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     */
    private function createLocalizedRoute(string $path, array $defaults, array $methods): Route
    {
        return new Route(
            '/{_locale}' . $path,
            ['_locale' => $this->defaultLocale] + $defaults,
            ['_locale' => implode('|', $this->enabledLocales)],
            [],
            '',
            [],
            $methods,
        );
    }
}

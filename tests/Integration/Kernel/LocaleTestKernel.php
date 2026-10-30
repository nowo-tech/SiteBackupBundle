<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal host app whose nowo_site_backup.setup.locale.enabled is a container parameter or an env var (not a literal
 * list), with the setup wizard routes on /{_locale}/_setup.
 */
final class LocaleTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param bool $importBundleRoutes whether the host imports the bundle routes.yaml (panel + setup loaders); the
     *                                 Symfony default `routing.controllers` resource is always imported
     */
    public function __construct(
        private readonly string $baseDir,
        private readonly mixed $enabledLocales,
        private readonly bool $importBundleRoutes = true,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new NowoUiKitBundle();
        yield new NowoSiteBackupBundle();
    }

    public function getProjectDir(): string
    {
        return $this->baseDir;
    }

    public function getCacheDir(): string
    {
        return $this->baseDir . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->baseDir . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->parameters()->set('app.locales', ['es', 'en', 'fr']);
        $container->extension('framework', [
            'secret'               => 'integration-secret',
            'test'                 => true,
            'http_method_override' => false,
            'enabled_locales'      => ['de', 'en'],
            // The setup wizard refuses to compile without form CSRF (CsrfProtectionPass).
            'session'         => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'form'            => ['csrf_protection' => true],
        ]);
        $container->extension('nowo_site_backup', [
            'bump_time_limit' => true,
            'security'        => ['allow_unauthenticated' => true],
            'setup'           => [
                'enabled' => true,
                'locale'  => ['in_path' => 'always', 'default' => 'en', 'enabled' => $this->enabledLocales],
            ],
        ]);
        // Env vars of the host (no process env mutation in tests): read through Symfony's env var loaders.
        $container->services()->set(LocaleEnvVarLoader::class)->tag('container.env_var_loader');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // The Symfony default `controllers: { resource: routing.controllers }` of a Flex host.
        $routes->import('routing.controllers');
        if ($this->importBundleRoutes) {
            $routes->import('@NowoSiteBackupBundle/Resources/config/routes.yaml');
        }
    }
}

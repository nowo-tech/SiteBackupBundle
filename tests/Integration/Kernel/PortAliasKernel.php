<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\SiteBackupBundle\Tests\Support\Ports\DualHostAccessGate;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * 1.35.2: a host kernel with one configurable `security.access_gate`, `security.actor_resolver` and
 * `setup.admin_provisioner` class, used to run `lint:container` (`CheckAliasValidityPass`) on every port combination.
 */
final class PortAliasKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param class-string $gate
     * @param class-string $actorResolver
     * @param class-string $provisioner
     */
    public function __construct(
        private readonly string $baseDir,
        private readonly string $gate,
        private readonly string $actorResolver,
        private readonly string $provisioner,
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
        $container->extension('framework', [
            'secret'               => 'integration-secret',
            'test'                 => true,
            'http_method_override' => false,
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
        ]);

        $services = $container->services();
        $gate     = $services->set($this->gate);
        if ($this->gate !== DualHostAccessGate::class) {
            $gate->args(['gate-test-value']);
        }
        $services->set($this->actorResolver);
        $services->set($this->provisioner);

        $container->extension('nowo_site_backup', [
            'security' => [
                'allow_unauthenticated' => true,
                'access_gate'           => $this->gate,
                'actor_resolver'        => $this->actorResolver,
            ],
            'setup' => [
                'enabled'           => true,
                'admin_provisioner' => $this->provisioner,
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoSiteBackupBundle/Resources/config/routes.yaml');
    }
}

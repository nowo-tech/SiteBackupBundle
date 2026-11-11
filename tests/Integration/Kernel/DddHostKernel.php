<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\SiteBackupBundle\Tests\Support\Ports\HostSeedStep;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\OpsConnection;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostActorResolver;
use Nowo\SiteBackupBundle\Tests\Support\Ports\TypedHostProvisioner;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * REQ-ARCH-001 host kernels. `ddd: true` wires only the framework-free ports (`security.access_gate` →
 * {@see PortHostAccessGate}, `security.actor_resolver` → {@see PortHostActorResolver}, `setup.admin_provisioner` →
 * {@see TypedHostProvisioner}, a `#[AsSetupStep]` host step and a separate `setup.dbal_connection`); `ddd: false` is
 * the standard host on the deprecated gate port ({@see LegacyHostAccessGate}) with every other default.
 */
final class DddHostKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        private readonly string $baseDir,
        private readonly bool $ddd,
        private readonly string $opsSecret,
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
        return $this->baseDir . '/cache/' . ($this->ddd ? 'ddd' : 'standard');
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
        $services->defaults()->autoconfigure();

        if (!$this->ddd) {
            $services->set(LegacyHostAccessGate::class)->args([$this->opsSecret]);
            $container->extension('nowo_site_backup', [
                'security' => ['allow_unauthenticated' => true, 'access_gate' => LegacyHostAccessGate::class],
                'setup'    => ['enabled' => true],
            ]);

            return;
        }

        $services->set(PortHostAccessGate::class)->args([$this->opsSecret]);
        $services->set(PortHostActorResolver::class);
        $services->set(TypedHostProvisioner::class)->public();
        $services->set(HostSeedStep::class);
        $services->set('app.ops_connection', OpsConnection::class)->public();
        $container->extension('nowo_site_backup', [
            'security' => [
                'allow_unauthenticated' => true,
                'access_gate'           => PortHostAccessGate::class,
                'actor_resolver'        => PortHostActorResolver::class,
            ],
            'setup' => [
                'enabled'           => true,
                'admin_provisioner' => TypedHostProvisioner::class,
                'dbal_connection'   => 'app.ops_connection',
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoSiteBackupBundle/Resources/config/routes.yaml');
    }
}

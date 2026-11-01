<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\SecAudit;

use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function array_replace_recursive;
use function md5;
use function serialize;

use const PHP_BINARY;

/**
 * REQ-SEC-004 Appendix CG host: an installed app that imports the bundle routes as INSTALLATION.md
 * says and keeps every nowo_site_backup.setup default. web_ui.enabled=false only because the test vendor tree has no
 * symfony/security-bundle (the panel requires it by default); the setup wizard does not depend on the panel.
 */
final class SetupSecurityKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $siteBackup */
    public function __construct(
        private readonly string $baseDir,
        private readonly array $siteBackup = [],
        bool $debug = false,
    ) {
        parent::__construct('prod', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new NowoSiteBackupBundle();
    }

    public function getProjectDir(): string
    {
        return $this->baseDir;
    }

    public function getCacheDir(): string
    {
        return $this->baseDir . '/var/cache/' . md5(serialize([$this->siteBackup, $this->debug]));
    }

    public function getLogDir(): string
    {
        return $this->baseDir . '/var/log';
    }

    public function home(): Response
    {
        return new Response('HOST HOME PAGE');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'test-secret-not-for-production',
            'test'                 => true,
            'http_method_override' => false,
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
        ]);
        $container->services()->set('logger', NullLogger::class);
        $container->services()->set('test.admin_provisioner', RecordingAdminProvisioner::class)->args([$this->baseDir . '/admins.json'])->public();
        $container->extension('nowo_site_backup', array_replace_recursive([
            'web_ui' => ['enabled' => false],
            'setup'  => ['php_binary' => PHP_BINARY],
        ], $this->siteBackup));
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('host_home', '/')->controller('kernel::home');
        $routes->import('@NowoSiteBackupBundle/Resources/config/routes.yaml');
    }
}

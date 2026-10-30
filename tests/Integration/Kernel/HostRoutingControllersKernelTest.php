<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function is_string;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-SF-004 rule 7: the bundle routes are opt-in. A host whose routing only holds the Symfony default
 * `routing.controllers` resource must not get the panel routes (on Symfony >= 7.3 an autoconfigured #[Route]
 * controller is tagged `routing.controller` and that resource would load it, the panel at `/`).
 */
final class HostRoutingControllersKernelTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sb_routing_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    public function testRoutingControllersResourceDoesNotLoadTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $bundleRoutes = [];
        foreach ($this->routes(false)->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (str_starts_with($name, 'nowo_site_backup') || (is_string($controller) && str_starts_with($controller, 'Nowo\\SiteBackupBundle\\'))) {
                $bundleRoutes[] = $name;
            }
        }

        self::assertSame([], $bundleRoutes, 'The bundle routes must only exist when the host imports routes.yaml.');
    }

    public function testImportedRoutesKeepThePanelPrefix(): void
    {
        self::assertSame('/_site_backup/', $this->routes(true)->get('nowo_site_backup_panel')?->getPath());
    }

    private function routes(bool $importBundleRoutes): RouteCollection
    {
        $kernel = new LocaleTestKernel($this->baseDir . ($importBundleRoutes ? '/imported' : '/controllers-only'), ['en'], $importBundleRoutes);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            return $router->getRouteCollection();
        } finally {
            $kernel->shutdown();
        }
    }
}

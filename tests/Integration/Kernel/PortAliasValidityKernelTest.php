<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Tests\Support\Ports\DualHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\DualHostActorResolver;
use Nowo\SiteBackupBundle\Tests\Support\Ports\DualHostProvisioner;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyActorResolverFixture;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyProvisionerFixture;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostActorResolver;
use Nowo\SiteBackupBundle\Tests\Support\Ports\TypedHostProvisioner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;
use function uniqid;

/**
 * 1.35.2: `lint:container` (which adds Symfony's `CheckAliasValidityPass`) passes for a host service on the 1.35
 * port only, on the deprecated port only, or on both, for the access gate, the actor resolver and the admin
 * provisioner; each port alias exists only when the configured service implements it, and the bundle's own services
 * get the configured service.
 */
final class PortAliasValidityKernelTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sb_port_alias_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    /**
     * @return iterable<string, array{class-string, class-string, class-string, bool, bool}>
     */
    public static function hostPorts(): iterable
    {
        yield 'new port only' => [PortHostAccessGate::class, PortHostActorResolver::class, TypedHostProvisioner::class, false, true];
        yield 'deprecated port only' => [LegacyHostAccessGate::class, LegacyActorResolverFixture::class, LegacyProvisionerFixture::class, true, false];
        yield 'both ports' => [DualHostAccessGate::class, DualHostActorResolver::class, DualHostProvisioner::class, true, true];
    }

    /**
     * @param class-string $gate
     * @param class-string $resolver
     * @param class-string $provisioner
     */
    #[DataProvider('hostPorts')]
    public function testLintContainerPasses(string $gate, string $resolver, string $provisioner, bool $legacy, bool $new): void
    {
        $kernel = new PortAliasKernel($this->baseDir, $gate, $resolver, $provisioner);
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('linted successfully', $tester->getDisplay());

        /** @var ContainerInterface $services */
        $services = $kernel->getContainer()->get('test.service_container');

        // The gate aliases always point at the bundle wrapper, which implements both ports.
        self::assertInstanceOf(ThrottledSiteBackupAccessGate::class, $services->get(SiteBackupAccessGateInterface::class));
        self::assertInstanceOf(ThrottledSiteBackupAccessGate::class, $services->get(PanelAccessGateInterface::class));

        self::assertSame($legacy, $services->has(ActorResolverInterface::class));
        self::assertSame($new, $services->has(PanelActorResolverInterface::class));
        self::assertSame($legacy, $services->has(AdminUserProvisionerInterface::class));
        self::assertSame($new, $services->has(AdminAccountProvisionerInterface::class));

        $controller = $services->get(SiteBackupPanelController::class);
        self::assertInstanceOf($resolver, (new ReflectionProperty($controller, 'actorResolver'))->getValue($controller));
        $factory = $services->get(SetupStepFactory::class);
        self::assertInstanceOf($provisioner, (new ReflectionProperty($factory, 'adminProvisioner'))->getValue($factory));

        $kernel->shutdown();
    }

    /** Audit MJ: an inner gate on the deprecated port only still denies a framework-free context call. */
    public function testLegacyInnerGateFailsClosedOnContext(): void
    {
        $kernel = new PortAliasKernel($this->baseDir, LegacyHostAccessGate::class, LegacyActorResolverFixture::class, LegacyProvisionerFixture::class);
        $kernel->boot();

        /** @var ContainerInterface $services */
        $services = $kernel->getContainer()->get('test.service_container');
        $gate     = $services->get(PanelAccessGateInterface::class);
        self::assertInstanceOf(PanelAccessGateInterface::class, $gate);

        self::assertFalse($gate->isAuthenticated(new PanelRequestContext()));
        self::assertFalse($gate->authenticate(new PanelRequestContext(), 'gate-test-value'));

        $kernel->shutdown();
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Kernel;

use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContextFactory;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Setup\Detector\DoctrineConnectDetector;
use Nowo\SiteBackupBundle\Setup\InitialInstallOnlySteps;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepFactory;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Tests\Support\Ports\TypedHostProvisioner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

use function array_key_last;
use function file_get_contents;
use function glob;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-ARCH-001: a DDD host wired only with the framework-free ports boots, and its panel gate answers exactly like a
 * standard host on the deprecated port (same login sequence, same throttle, same session rotation); the typed
 * provisioner, the `#[AsSetupStep]` host step and the separate DBAL connection are wired.
 */
final class DddHostKernelTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sb_ddd_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    private function secret(): string
    {
        return 'REDACTED-TEST-x';
    }

    public function testPanelGateAnswersLikeTheStandardHost(): void
    {
        $standard = $this->loginSequence(false);
        $ddd      = $this->loginSequence(true);

        self::assertSame($standard, $ddd);
        self::assertContains('429', $ddd, 'The login throttle (default 5 attempts) applies to the DDD gate too.');
    }

    public function testDddHostWiring(): void
    {
        $kernel = new DddHostKernel($this->baseDir, true, $this->secret());
        $kernel->boot();

        try {
            $container = $this->container($kernel);

            $gate = $container->get(PanelAccessGateInterface::class);
            self::assertInstanceOf(ThrottledSiteBackupAccessGate::class, $gate);
            $request = $this->request('10.1.0.1');
            self::assertTrue($gate->authenticate(PanelRequestContextFactory::fromRequest($request), $this->secret()));
            self::assertTrue($gate->isAuthenticated($request), 'Context login is visible to the Request entry point (same session).');

            $controller = $container->get(SiteBackupPanelController::class);
            self::assertInstanceOf(SiteBackupPanelController::class, $controller);
            self::assertSame('ops:anonymous', (new ReflectionMethod($controller, 'actor'))->invoke($controller, $request));

            $factory = $container->get(SetupStepFactory::class);
            self::assertInstanceOf(SetupStepFactory::class, $factory);
            $ctx   = new SetupContext($this->baseDir, 'default');
            $admin = $factory->create(['type' => 'admin_user', 'skip_if_admin_exists' => false], 0);
            self::assertTrue($admin->run($ctx, new SetupStepInput(['email' => 'root@example.test', 'password' => $this->secret()]))->isSuccess());
            $provisioner = $container->get(TypedHostProvisioner::class);
            self::assertInstanceOf(TypedHostProvisioner::class, $provisioner);
            self::assertSame([['root@example.test', ['ROLE_SUPER_ADMIN']]], $provisioner->created);

            $seed = $factory->create(['type' => 'host_seed'], 1);
            self::assertSame('host_seed', $seed->getId());
            self::assertTrue($seed->run($ctx, new SetupStepInput())->isSuccess());
            self::assertTrue(InitialInstallOnlySteps::isInitialInstallOnly($seed), 'A re-opened wizard skips it.');

            $detector = $container->get(DoctrineConnectDetector::class);
            self::assertInstanceOf(DoctrineConnectDetector::class, $detector);
            self::assertSame($container->get('app.ops_connection'), (new ReflectionProperty(DoctrineConnectDetector::class, 'connection'))->getValue($detector));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testHostAdaptersImportNoSymfonyType(): void
    {
        foreach (['PortHostAccessGate', 'PortHostActorResolver', 'TypedHostProvisioner', 'HostSeedStep', 'RecordingPanelSession', 'OpsConnection'] as $adapter) {
            $file = __DIR__ . '/../../Support/Ports/' . $adapter . '.php';
            self::assertDoesNotMatchRegularExpression('/^use (Symfony|Doctrine)\\\\/m', (string) file_get_contents($file), $adapter);
        }
        self::assertNotSame([], glob(__DIR__ . '/../../Support/Ports/*.php'));
    }

    /**
     * Login attempts through the gate the panel controller receives, as one client IP then another.
     *
     * @return list<string>
     */
    private function loginSequence(bool $ddd): array
    {
        $kernel = new DddHostKernel($this->baseDir, $ddd, $this->secret());
        $kernel->boot();
        $outcomes = [];

        try {
            $gate = $this->container($kernel)->get(SiteBackupAccessGateInterface::class);
            self::assertInstanceOf(ThrottledSiteBackupAccessGate::class, $gate);
            $request = $this->request('10.2.0.1');
            foreach (['wrong', '', $this->secret(), 'wrong', 'wrong', 'wrong', 'wrong', 'wrong', $this->secret()] as $password) {
                $before = $request->getSession()->getId();
                try {
                    $ok         = $gate->authenticate($request, $password);
                    $outcomes[] = ($ok ? 'ok' : 'denied') . ($before !== $request->getSession()->getId() ? '+rotated' : '') . ($gate->isAuthenticated($request) ? '+in' : '');
                } catch (TooManyRequestsHttpException) {
                    $outcomes[] = '429';
                }
                if (($outcomes[array_key_last($outcomes)] ?? '') !== '429') {
                    $gate->logout($request);
                }
            }
            $other      = $this->request('10.2.0.2');
            $outcomes[] = $gate->authenticate($other, $this->secret()) ? 'other-ip-ok' : 'other-ip-denied';
            $outcomes[] = $gate->isMisconfigured() ? 'misconfigured' : 'configured';
        } finally {
            $kernel->shutdown();
        }

        return $outcomes;
    }

    private function container(DddHostKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function request(string $ip): Request
    {
        $request = Request::create('/_site_backup', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        return $request;
    }
}

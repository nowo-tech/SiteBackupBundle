<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\SiteBackupBundle\DependencyInjection\Compiler\LoginThrottleStoragePass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

use const PASSWORD_DEFAULT;

final class LoginThrottleStoragePassTest extends TestCase
{
    /**
     * @param array<string, mixed> $throttle
     * @param list<string> $pools
     */
    private function container(array $throttle = [], array $pools = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        foreach ($pools as $pool) {
            $container->register($pool, ArrayAdapter::class)->setPublic(true);
        }

        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true, 'login_throttle' => $throttle],
        ]], $container);

        return $container;
    }

    private function poolOf(ContainerBuilder $container): string
    {
        (new LoginThrottleStoragePass())->process($container);
        $definition = $container->getDefinition(SiteBackupExtension::LOGIN_LIMITER_STORAGE_ID);
        self::assertFalse($definition->hasTag(LoginThrottleStoragePass::TAG));
        $pool = $definition->getArgument('$pool');
        self::assertInstanceOf(Reference::class, $pool);

        return (string) $pool;
    }

    public function testPrefersRateLimiterPoolThenCacheApp(): void
    {
        self::assertSame('cache.rate_limiter', $this->poolOf($this->container([], ['cache.app', 'cache.rate_limiter'])));
        self::assertSame('cache.app', $this->poolOf($this->container([], ['cache.app'])));
    }

    public function testConfiguredPool(): void
    {
        self::assertSame('app.pool', $this->poolOf($this->container(['cache_pool' => 'app.pool'], ['app.pool', 'cache.app'])));
    }

    public function testMissingConfiguredPoolFailsAtCompileTime(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cache_pool "missing.pool"');
        $this->poolOf($this->container(['cache_pool' => 'missing.pool'], ['cache.app']));
    }

    public function testNoPoolFailsAtCompileTimeWithActionableMessage(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('login_throttle.enabled: false');
        $this->poolOf($this->container());
    }

    public function testNoopWithoutTaggedStorage(): void
    {
        $container = $this->container(['enabled' => false]);
        (new LoginThrottleStoragePass())->process($container);

        self::assertFalse($container->hasDefinition(SiteBackupExtension::LOGIN_LIMITER_STORAGE_ID));
    }

    public function testCompiledContainerThrottlesThePanelGate(): void
    {
        $loaded = new ContainerBuilder();
        $loaded->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => [
                'allow_unauthenticated' => true,
                'password_hash'         => password_hash('secret', PASSWORD_DEFAULT),
                'login_throttle'        => ['limit' => 1],
            ],
        ]], $loaded);

        // Compile only the gate graph (the rest of the bundle needs router/twig/... from a full kernel).
        $container = new ContainerBuilder();
        $container->register('cache.app', ArrayAdapter::class);
        foreach ([SiteBackupExtension::ACCESS_GATE_ID, PasswordSiteBackupAccessGate::class, SiteBackupExtension::LOGIN_LIMITER_ID, SiteBackupExtension::LOGIN_LIMITER_STORAGE_ID] as $id) {
            $container->setDefinition($id, $loaded->getDefinition($id));
        }
        $container->setAlias(SiteBackupAccessGateInterface::class, SiteBackupExtension::ACCESS_GATE_ID)->setPublic(true);
        $container->addCompilerPass(new LoginThrottleStoragePass());
        $container->compile(true);

        $gate = $container->get(SiteBackupAccessGateInterface::class);
        self::assertInstanceOf(ThrottledSiteBackupAccessGate::class, $gate);

        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertFalse($gate->authenticate($request, 'wrong'));

        $this->expectException(TooManyRequestsHttpException::class);
        $gate->authenticate($request, 'secret');
    }
}

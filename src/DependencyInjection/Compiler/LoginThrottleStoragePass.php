<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function sprintf;

/**
 * Wires the PSR-6 pool of the built-in panel login limiter storage (security.login_throttle, v1.17+).
 *
 * Order: explicit login_throttle.cache_pool, then cache.rate_limiter (FrameworkBundle, present
 * when symfony/rate-limiter is installed), then cache.app. Fails at compile time with an
 * actionable message instead of a runtime 500 on the login form.
 */
final class LoginThrottleStoragePass implements CompilerPassInterface
{
    public const TAG = 'nowo_site_backup.login_limiter_storage';

    private const FALLBACK_POOLS = ['cache.rate_limiter', 'cache.app'];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $configured = $tags[0]['cache_pool'] ?? null;
            $pool       = is_string($configured) && $configured !== ''
                ? $this->requireConfiguredPool($container, $configured)
                : $this->firstAvailablePool($container);

            $container->getDefinition($id)
                ->setArgument('$pool', new Reference($pool))
                ->clearTag(self::TAG);
        }
    }

    private function requireConfiguredPool(ContainerBuilder $container, string $pool): string
    {
        if (!$container->has($pool)) {
            throw new LogicException(sprintf('nowo_site_backup.security.login_throttle.cache_pool "%s" is not a registered service.', $pool));
        }

        return $pool;
    }

    private function firstAvailablePool(ContainerBuilder $container): string
    {
        foreach (self::FALLBACK_POOLS as $pool) {
            if ($container->has($pool)) {
                return $pool;
            }
        }

        throw new LogicException('nowo_site_backup.security.login_throttle needs a PSR-6 cache pool (none of "cache.rate_limiter" / "cache.app" is registered). Set security.login_throttle.cache_pool, security.login_throttle.limiter (a RateLimiterFactory service id), or security.login_throttle.enabled: false.');
    }
}

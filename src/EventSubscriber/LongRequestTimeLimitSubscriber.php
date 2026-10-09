<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\EventSubscriber;

use Closure;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function function_exists;
use function max;
use function rtrim;
use function set_time_limit;
use function str_starts_with;

/**
 * Raises the PHP time limit for the setup wizard and backup panel (opt-in: `bump_time_limit`).
 *
 * Setup `advance` requests run migrate / seed subprocesses, and the panel dumps / restores,
 * each up to `process_timeout` seconds. A short production `max_execution_time` (or a FrankenPHP
 * worker whose request timer is not reset between requests) would kill the parent request
 * mid-pipe (`MaxExecutionTimeError` in Symfony Process pipes). Every other request keeps the
 * host's normal limit.
 *
 * Stateless: configuration only.
 */
final readonly class LongRequestTimeLimitSubscriber
{
    /** @var list<string> */
    private array $pathPrefixes;

    /** @var Closure(int): mixed */
    private Closure $setTimeLimit;

    /**
     * @param list<string> $pathPrefixes setup / panel prefixes (incl. localized variants)
     * @param (Closure(int): mixed)|null $setTimeLimit test seam; default set_time_limit()
     */
    public function __construct(
        array $pathPrefixes,
        private int $seconds,
        ?Closure $setTimeLimit = null,
    ) {
        $normalized = [];
        foreach ($pathPrefixes as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix !== '') {
                $normalized[] = $prefix;
            }
        }
        $this->pathPrefixes = $normalized;
        $this->setTimeLimit = $setTimeLimit ?? static function (int $seconds): bool {
            return function_exists('set_time_limit') && set_time_limit($seconds);
        };
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->appliesTo($event->getRequest()->getPathInfo())) {
            return;
        }

        ($this->setTimeLimit)(max(0, $this->seconds));
    }

    public function appliesTo(string $path): bool
    {
        foreach ($this->pathPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}

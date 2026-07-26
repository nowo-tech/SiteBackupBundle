<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exclusion;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

use function fnmatch;
use function in_array;
use function is_string;
use function preg_match;
use function str_starts_with;

/**
 * Matches requests that must bypass the restore loading page.
 */
final class SiteBackupExclusionMatcher
{
    /**
     * @param list<string> $paths
     * @param list<string> $pathPrefixes
     * @param list<string> $routes
     * @param list<string> $patterns
     * @param list<string> $ips
     */
    public function __construct(
        private readonly array $paths = [],
        private readonly array $pathPrefixes = [],
        private readonly array $routes = [],
        private readonly array $patterns = [],
        private readonly array $ips = [],
    ) {
    }

    public function matches(Request $request): bool
    {
        $path = $request->getPathInfo();

        if (in_array($path, $this->paths, true)) {
            return true;
        }

        foreach ($this->pathPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        $route = $request->attributes->get('_route');
        if (is_string($route) && $route !== '' && in_array($route, $this->routes, true)) {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (
                (str_starts_with($pattern, '#') || str_starts_with($pattern, '~'))
                && preg_match($pattern, $path) === 1
            ) {
                return true;
            }
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        if ($this->ips !== []) {
            $clientIp = $request->getClientIp();
            if (is_string($clientIp) && $clientIp !== '' && IpUtils::checkIp($clientIp, $this->ips)) {
                return true;
            }
        }

        return false;
    }
}

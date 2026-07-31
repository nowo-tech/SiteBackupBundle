<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Routing;

use Nowo\SiteBackupBundle\Enum\LocaleInPathMode;
use Symfony\Component\HttpFoundation\RequestStack;

use function in_array;
use function rtrim;
use function str_starts_with;

/**
 * Resolves the effective setup path prefix for the current request,
 * accounting for locale-in-path mode.
 */
final class SetupPathPrefixResolver
{
    private readonly LocaleInPathMode $mode;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $basePrefix,
        string $localeInPath,
        private readonly string $defaultLocale,
        private readonly array $enabledLocales,
    ) {
        $this->mode = LocaleInPathMode::from($localeInPath);
    }

    /**
     * Return the effective path prefix for setup URLs in the current request context.
     */
    public function resolve(): string
    {
        $prefix  = rtrim($this->basePrefix, '/');
        $request = $this->requestStack->getCurrentRequest();

        if ($this->mode === LocaleInPathMode::Never) {
            return $prefix;
        }

        if ($request instanceof \Symfony\Component\HttpFoundation\Request) {
            $path = $request->getPathInfo();

            foreach ($this->enabledLocales as $locale) {
                $localizedPrefix = '/' . $locale . $prefix;
                if (str_starts_with($path, $localizedPrefix)) {
                    return $localizedPrefix;
                }
            }

            if (str_starts_with($path, $prefix)) {
                return $prefix;
            }
        }

        if ($this->mode === LocaleInPathMode::Always) {
            $locale = $request?->getLocale() ?? $this->defaultLocale;
            if (!in_array($locale, $this->enabledLocales, true)) {
                $locale = $this->defaultLocale;
            }

            return '/' . $locale . $prefix;
        }

        // Both + serve: bare for default locale, localized otherwise
        $locale = $request?->getLocale() ?? $this->defaultLocale;
        if ($locale === $this->defaultLocale) {
            return $prefix;
        }

        if (in_array($locale, $this->enabledLocales, true)) {
            return '/' . $locale . $prefix;
        }

        return $prefix;
    }
}

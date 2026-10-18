<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Routing;

use function array_map;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_quote;
use function str_starts_with;
use function trim;

/**
 * setup.locale.enabled: a literal list, or the value of a container parameter / env var ("%app.locales%",
 * "%kernel.enabled_locales%", "%env(json:APP_LOCALES)%", "%env(csv:APP_LOCALES)%"), and the setup paths derived from it.
 *
 * Used at build time (literal list or build-time parameter) and as service factories when the list comes from an env
 * var, which is only known at runtime.
 */
final class SetupLocales
{
    /**
     * @return list<string> non-empty locale codes, in order, without duplicates
     */
    public static function normalize(mixed $locales): array
    {
        if (is_string($locales)) {
            $decoded = str_starts_with(ltrim($locales), '[') ? json_decode($locales, true) : null;
            $locales = is_array($decoded) ? $decoded : explode(',', $locales);
        }
        if (!is_array($locales)) {
            return [];
        }

        $list = [];
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                continue;
            }
            $locale = trim($locale);
            if ($locale !== '' && !in_array($locale, $list, true)) {
                $list[] = $locale;
            }
        }

        return $list;
    }

    /**
     * $prefixes plus "/{locale}{setupPrefix}" for each enabled locale.
     *
     * @param list<string> $prefixes
     *
     * @return list<string>
     */
    public static function localizedPrefixes(array $prefixes, mixed $locales, string $setupPrefix): array
    {
        foreach (self::normalize($locales) as $locale) {
            $prefixes[] = '/' . $locale . $setupPrefix;
        }

        return $prefixes;
    }

    /**
     * $patterns plus one regex matching "/{any enabled locale}{setupPrefix}" (none when no locale is enabled).
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    public static function withLocalizedSetupPattern(array $patterns, mixed $locales, string $setupPrefix): array
    {
        $locales = self::normalize($locales);
        if ($locales === []) {
            return $patterns;
        }

        $escapedLocales = array_map(static fn (string $locale): string => preg_quote($locale, '#'), $locales);
        $pattern        = '#^/(' . implode('|', $escapedLocales) . ')' . preg_quote($setupPrefix, '#') . '(/|$|\\?)#';
        if (!in_array($pattern, $patterns, true)) {
            $patterns[] = $pattern;
        }

        return $patterns;
    }
}

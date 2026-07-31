<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Enum;

/**
 * How setup wizard routes expose {_locale} in the URL path.
 */
enum LocaleInPathMode: string
{
    /** Only bare paths: /_setup, /_setup/done, … */
    case Never = 'never';

    /** Only localized paths: /{_locale}/_setup, … */
    case Always = 'always';

    /** Both bare and localized paths. */
    case Both = 'both';

    public function usesLocalePrefix(): bool
    {
        return $this !== self::Never;
    }

    public function registersLocalizedRoutes(): bool
    {
        return $this === self::Always || $this === self::Both;
    }
}

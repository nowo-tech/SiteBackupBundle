<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Retention;

use DateTimeZone;
use InvalidArgumentException;

/**
 * What to keep (1.28+). An item is kept when **any** rule keeps it; with no rule set nothing is deleted.
 *
 * - `keepLast`: the newest N succeeded items (0 = only the one just created);
 * - `maxAgeDays`: items younger than N days;
 * - `keepDaily` / `keepWeekly` / `keepMonthly` (GFS): the newest item of each of the last N calendar days / ISO weeks /
 *   months that have one, in `timezone`.
 */
final readonly class RetentionPolicy
{
    public function __construct(
        public ?int $keepLast = null,
        public ?int $maxAgeDays = null,
        public ?int $keepDaily = null,
        public ?int $keepWeekly = null,
        public ?int $keepMonthly = null,
        public string $timezone = 'UTC',
    ) {
        foreach (['keepLast' => $keepLast, 'maxAgeDays' => $maxAgeDays, 'keepDaily' => $keepDaily, 'keepWeekly' => $keepWeekly, 'keepMonthly' => $keepMonthly] as $name => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException($name . ' must be >= 0.');
            }
        }
        new DateTimeZone($timezone);
    }

    public function isEmpty(): bool
    {
        return $this->keepLast === null && $this->maxAgeDays === null && $this->keepDaily === null
            && $this->keepWeekly === null && $this->keepMonthly === null;
    }
}

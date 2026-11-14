<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;

/**
 * What {@see BinlogSchemaFilter::run()} did (1.36+). `problems` (capped, `problemCount` is the total) never quote SQL.
 */
final readonly class BinlogFilterResult
{
    /**
     * @param list<string> $problems
     */
    public function __construct(
        public int $keptEvents,
        public int $droppedEvents,
        public array $problems,
        public int $problemCount,
        public ?DateTimeImmutable $firstEventAt,
        public ?DateTimeImmutable $lastEventAt,
        public bool $cut,
    ) {
    }

    public function isClean(): bool
    {
        return $this->problemCount === 0;
    }
}

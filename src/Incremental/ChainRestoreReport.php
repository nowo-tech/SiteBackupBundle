<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;

/**
 * What a chain restore applied, step by step (1.36+). `complete` is true only when every planned step was applied;
 * otherwise `error` says why and the steps say exactly what the live site has and has not received.
 */
final readonly class ChainRestoreReport
{
    /**
     * @param list<string> $chain backup ids, full first
     * @param list<ChainRestoreStep> $steps in execution order
     * @param list<string> $notes
     * @param DateTimeImmutable|null $databaseAt the instant the database is restored to (`--until`, or the last
     *                                           replayed event), null without a database part
     * @param DateTimeImmutable|null $filesAsOf the creation time of the backup the files are restored from (the
     *                                          last incremental at or before `--until`), null without files
     */
    public function __construct(
        public array $chain,
        public array $steps,
        public bool $complete,
        public ?DateTimeImmutable $until = null,
        public ?string $error = null,
        public array $notes = [],
        public ?DateTimeImmutable $databaseAt = null,
        public ?DateTimeImmutable $filesAsOf = null,
    ) {
    }

    /**
     * @return list<ChainRestoreStep>
     */
    public function stepsWith(string $status): array
    {
        $steps = [];
        foreach ($this->steps as $step) {
            if ($step->status === $status) {
                $steps[] = $step;
            }
        }

        return $steps;
    }
}

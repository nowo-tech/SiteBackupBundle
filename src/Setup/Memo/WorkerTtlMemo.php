<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Memo;

use Closure;
use Symfony\Contracts\Service\ResetInterface;

use function time;

/**
 * Tiny "is this still fresh?" latch with a TTL, kept for the lifetime of the PHP process.
 *
 * Deliberately NOT a {@see ResetInterface}: in FrankenPHP worker
 * mode `kernel.reset` runs between requests, and the whole point of this memo is to survive
 * it so expensive probes (information_schema, `CREATE TABLE IF NOT EXISTS`) run at most once
 * per worker and TTL window instead of once per request.
 *
 * TTL semantics:
 *  - `> 0`: {@see isFresh()} is true until `ttl` seconds after {@see mark()}.
 *  - `0`: memo disabled — {@see isFresh()} is always false.
 */
final class WorkerTtlMemo
{
    private ?int $freshUntil = null;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock unix timestamp provider (tests)
     */
    public function __construct(
        private readonly int $ttlSeconds,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function isEnabled(): bool
    {
        return $this->ttlSeconds > 0;
    }

    public function isFresh(): bool
    {
        return $this->freshUntil !== null && ($this->clock)() < $this->freshUntil;
    }

    public function mark(): void
    {
        // @igor-ignore - intentional per-worker memo bounded by $ttlSeconds (survives kernel.reset on purpose).
        $this->freshUntil = $this->isEnabled() ? ($this->clock)() + $this->ttlSeconds : null;
    }

    public function forget(): void
    {
        // @igor-ignore - intentional per-worker memo bounded by $ttlSeconds (survives kernel.reset on purpose).
        $this->freshUntil = null;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after code, env or cache were changed on disk (restore, `.env.local`, `cache:clear`).
 *
 * Long-lived PHP workers (FrankenPHP worker mode, RoadRunner, Swoole) keep the old container,
 * environment and loaded classes until they restart. Listen to this event to trigger a restart
 * (e.g. Caddy admin API `POST /frankenphp/workers/restart`).
 */
final class WorkerRestartRequiredEvent extends Event
{
    public function __construct(private readonly string $reason)
    {
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

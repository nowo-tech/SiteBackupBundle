<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /**
     * @param list<class-string> $events
     */
    public function __construct(private array &$events)
    {
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event::class;

        return $event;
    }

    /**
     * @return list<class-string>
     */
    public function collected(): array
    {
        return $this->events;
    }
}

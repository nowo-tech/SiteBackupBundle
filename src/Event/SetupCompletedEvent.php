<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class SetupCompletedEvent extends Event
{
    public function __construct(private readonly string $profile)
    {
    }

    public function getProfile(): string
    {
        return $this->profile;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class SetupStepCompletedEvent extends Event
{
    public function __construct(
        private readonly string $profile,
        private readonly string $stepId,
    ) {
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getStepId(): string
    {
        return $this->stepId;
    }
}

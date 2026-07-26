<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class SetupStepFailedEvent extends Event
{
    public function __construct(
        private readonly string $profile,
        private readonly string $stepId,
        private readonly string $error,
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

    public function getError(): string
    {
        return $this->error;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Default actor resolver: always the same label ("panel" unless configured otherwise).
 */
final readonly class StaticActorResolver implements ActorResolverInterface
{
    public function __construct(
        private ?string $actor = self::PANEL,
    ) {
    }

    public function resolveActor(Request $request): ?string
    {
        return $this->actor;
    }
}

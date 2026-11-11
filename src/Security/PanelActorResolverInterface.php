<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

/**
 * Resolves the actor recorded (history `actor`, event `$actor`, `BackupArtifact` created-by) for panel actions,
 * framework-free (1.35+).
 *
 * Same contract as the deprecated {@see ActorResolverInterface}, with a {@see PanelRequestContext} instead of a Symfony
 * `Request`. Configure it with `security.actor_resolver`.
 */
interface PanelActorResolverInterface
{
    /**
     * Actor that is not a person: the panel when no user is known.
     */
    public const DEFAULT_ACTOR = 'panel';

    /**
     * The actor for this request, or null for none. The panel normalises every value
     * ({@see SecurityTokenActorResolver::normalize()}) and records "panel" when nothing is left.
     */
    public function resolveActor(PanelRequestContext $context): ?string;
}

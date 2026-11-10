<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the actor recorded (history `actor`, event `$actor`, `BackupArtifact` created-by) for panel actions.
 *
 * Default: {@see StaticActorResolver} ("panel", the 1.32 value). {@see SecurityTokenActorResolver} records the
 * logged-in user identifier instead; set `security.actor_resolver` to it or to your own service.
 */
interface ActorResolverInterface
{
    /**
     * Actor that is not a person: the panel when no user is known (1.32 value).
     */
    public const PANEL = 'panel';

    /**
     * The actor for this request, or null for none. Keep it to 1-128 printable characters: the panel passes every
     * value through {@see SecurityTokenActorResolver::normalize()} (controls, tab / newline and format characters
     * dropped, cut at 128) and records "panel" when nothing is left.
     */
    public function resolveActor(Request $request): ?string;
}

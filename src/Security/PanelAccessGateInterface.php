<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use SensitiveParameter;

/**
 * Ops password gate of the backup / restore panel, framework-free (1.35+).
 *
 * Same contract as the deprecated {@see SiteBackupAccessGateInterface}, with a {@see PanelRequestContext} instead of a
 * Symfony `Request`. Configure it with `security.access_gate` (service id); the bundle always wraps it with the login
 * throttle and the session id rotation ({@see ThrottledSiteBackupAccessGate}).
 *
 * Deny by default: without a session, with protection enabled but no credentials ({@see isMisconfigured()}), or on
 * any doubt, return false. Implementations must be stateless (FrankenPHP worker mode): keep the authenticated flag in
 * the session, never in a property.
 */
interface PanelAccessGateInterface
{
    public function isAuthenticated(PanelRequestContext $context): bool;

    public function authenticate(PanelRequestContext $context, #[SensitiveParameter] string $password): bool;

    public function logout(PanelRequestContext $context): void;

    public function isProtectionEnabled(): bool;

    /**
     * When true, protection is enabled but credentials are not configured — deny all access.
     */
    public function isMisconfigured(): bool;
}

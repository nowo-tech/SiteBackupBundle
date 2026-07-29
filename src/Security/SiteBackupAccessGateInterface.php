<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\HttpFoundation\Request;

interface SiteBackupAccessGateInterface
{
    public function isAuthenticated(Request $request): bool;

    public function authenticate(Request $request, string $password): bool;

    public function logout(Request $request): void;

    public function isProtectionEnabled(): bool;

    /**
     * When true, protection is enabled but credentials are not configured — deny all access.
     */
    public function isMisconfigured(): bool;
}

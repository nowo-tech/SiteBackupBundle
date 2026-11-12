<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host gate in the middle of a migration: implements the deprecated and the 1.35 port. Denies everything.
 */
final readonly class DualHostAccessGate implements SiteBackupAccessGateInterface, PanelAccessGateInterface
{
    public function isAuthenticated(Request|PanelRequestContext $request): bool
    {
        return false;
    }

    public function authenticate(Request|PanelRequestContext $request, #[SensitiveParameter] string $password): bool
    {
        return false;
    }

    public function logout(Request|PanelRequestContext $request): void
    {
    }

    public function isProtectionEnabled(): bool
    {
        return true;
    }

    public function isMisconfigured(): bool
    {
        return false;
    }
}

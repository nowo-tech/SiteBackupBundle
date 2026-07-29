<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function is_string;
use function password_verify;

final class PasswordSiteBackupAccessGate implements SiteBackupAccessGateInterface
{
    private const SESSION_KEY = '_nowo_site_backup_auth';

    public function __construct(
        private readonly ?string $passwordHash,
        private readonly bool $enabled = true,
    ) {
    }

    public function isProtectionEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * True when protection is on but no usable password_hash is configured (fail-closed).
     */
    public function isMisconfigured(): bool
    {
        return $this->enabled && (!is_string($this->passwordHash) || $this->passwordHash === '');
    }

    public function isAuthenticated(Request $request): bool
    {
        if (!$this->enabled) {
            return true;
        }

        // Fail closed: enabled without a hash must never grant panel access (REQ-UI-002 / SEC-004).
        if ($this->isMisconfigured()) {
            return false;
        }

        $session = $this->session($request);
        if (!$session instanceof SessionInterface) {
            return false;
        }

        return $session->get(self::SESSION_KEY) === true;
    }

    public function authenticate(Request $request, string $password): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if ($this->isMisconfigured()) {
            return false;
        }

        if ($this->passwordHash === null || !password_verify($password, $this->passwordHash)) {
            return false;
        }

        $session = $this->session($request);
        if (!$session instanceof SessionInterface) {
            return false;
        }

        $session->set(self::SESSION_KEY, true);

        return true;
    }

    public function logout(Request $request): void
    {
        $session = $this->session($request);
        $session?->remove(self::SESSION_KEY);
    }

    private function session(Request $request): ?SessionInterface
    {
        if (!$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}

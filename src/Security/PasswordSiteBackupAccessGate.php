<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;

use function is_string;
use function password_verify;

/**
 * Default ops password gate. Implements the framework-free {@see PanelAccessGateInterface} and, for BC, the deprecated
 * {@see SiteBackupAccessGateInterface}: both entry points share one decision (the `Request` is only read for its
 * session).
 */
final class PasswordSiteBackupAccessGate implements SiteBackupAccessGateInterface, PanelAccessGateInterface
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

    public function isAuthenticated(Request|PanelRequestContext $request): bool
    {
        if (!$this->enabled) {
            return true;
        }

        // Fail closed: enabled without a hash must never grant panel access (REQ-UI-002 / SEC-004).
        if ($this->isMisconfigured()) {
            return false;
        }

        $session = $this->session($request);
        if (!$session instanceof PanelSessionInterface) {
            return false;
        }

        return $session->get(self::SESSION_KEY) === true;
    }

    public function authenticate(Request|PanelRequestContext $request, #[SensitiveParameter] string $password): bool
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
        if (!$session instanceof PanelSessionInterface) {
            return false;
        }

        // @igor-ignore - Security gate reads config/credentials per call; no cross-request cache.
        $session->set(self::SESSION_KEY, true);

        return true;
    }

    public function logout(Request|PanelRequestContext $request): void
    {
        $this->session($request)?->remove(self::SESSION_KEY);
    }

    private function session(Request|PanelRequestContext $request): ?PanelSessionInterface
    {
        if ($request instanceof PanelRequestContext) {
            return $request->session;
        }

        return $request->hasSession() ? new HttpFoundationPanelSession($request->getSession()) : null;
    }
}

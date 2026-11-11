<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;

use function hash_equals;

/**
 * A standard host gate on the deprecated port: one shared ops secret, flag in the session.
 */
final readonly class LegacyHostAccessGate implements SiteBackupAccessGateInterface
{
    private const KEY = 'host_ops_ok';

    public function __construct(
        #[SensitiveParameter]
        private ?string $secret,
        private bool $enabled = true,
    ) {
    }

    public function isAuthenticated(Request $request): bool
    {
        if (!$this->enabled) {
            return true;
        }

        return !$this->isMisconfigured() && $request->hasSession() && $request->getSession()->get(self::KEY) === true;
    }

    public function authenticate(Request $request, #[SensitiveParameter] string $password): bool
    {
        if (!$this->enabled) {
            return true;
        }
        if ($this->isMisconfigured() || !$request->hasSession() || !hash_equals((string) $this->secret, $password)) {
            return false;
        }
        $request->getSession()->set(self::KEY, true);

        return true;
    }

    public function logout(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::KEY);
        }
    }

    public function isProtectionEnabled(): bool
    {
        return $this->enabled;
    }

    public function isMisconfigured(): bool
    {
        return $this->enabled && ($this->secret === null || $this->secret === '');
    }
}

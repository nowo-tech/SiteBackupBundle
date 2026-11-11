<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;
use Nowo\SiteBackupBundle\Security\PanelSessionInterface;
use SensitiveParameter;

use function hash_equals;

/**
 * The same host gate as {@see LegacyHostAccessGate}, on the framework-free port (what a DDD host writes). No Symfony
 * import.
 */
final readonly class PortHostAccessGate implements PanelAccessGateInterface
{
    private const KEY = 'host_ops_ok';

    public function __construct(
        #[SensitiveParameter]
        private ?string $secret,
        private bool $enabled = true,
    ) {
    }

    public function isAuthenticated(PanelRequestContext $context): bool
    {
        if (!$this->enabled) {
            return true;
        }

        return !$this->isMisconfigured() && $context->session?->get(self::KEY) === true;
    }

    public function authenticate(PanelRequestContext $context, #[SensitiveParameter] string $password): bool
    {
        if (!$this->enabled) {
            return true;
        }
        if ($this->isMisconfigured() || !$context->session instanceof PanelSessionInterface || !hash_equals((string) $this->secret, $password)) {
            return false;
        }
        $context->session->set(self::KEY, true);

        return true;
    }

    public function logout(PanelRequestContext $context): void
    {
        $context->session?->remove(self::KEY);
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

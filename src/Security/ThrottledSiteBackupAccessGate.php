<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function max;

/**
 * Hardens any {@see SiteBackupAccessGateInterface} used by the panel login (v1.17+):
 *
 *  - per-client-IP rate limit of password attempts (security.login_throttle), so the panel
 *    password cannot be guessed online without limit; the limiter is reset on success;
 *  - session id rotation after a successful login (session fixation protection).
 *
 * Both only apply while {@see SiteBackupAccessGateInterface::isProtectionEnabled()} is true.
 * When the limit is hit, a {@see TooManyRequestsHttpException} (with Retry-After) is thrown;
 * the panel controller renders it as the login page with HTTP 429.
 *
 * Attempts are logged (REQ-OBS-001: throttled = warning, failed = notice, success = info) without IP or password.
 *
 * Stateless (worker-safe): the limiter state lives in the limiter storage (cache pool).
 */
final readonly class ThrottledSiteBackupAccessGate implements SiteBackupAccessGateInterface
{
    /** REQ-OBS-001: no client IP, session id or password in the records. */
    private const LOG_CONTEXT = ['bundle' => 'site_backup', 'action' => 'panel_login'];

    public function __construct(
        private SiteBackupAccessGateInterface $inner,
        private ?RateLimiterFactoryInterface $limiterFactory = null,
        private ClockInterface $clock = new NativeClock(),
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function isAuthenticated(Request $request): bool
    {
        return $this->inner->isAuthenticated($request);
    }

    public function authenticate(Request $request, string $password): bool
    {
        if (!$this->inner->isProtectionEnabled()) {
            return $this->inner->authenticate($request, $password);
        }

        $limiter = $this->limiterFactory?->create($request->getClientIp() ?? 'unknown');
        if ($limiter instanceof LimiterInterface) {
            $limit = $limiter->consume(1);
            if (!$limit->isAccepted()) {
                $this->logger?->warning('SiteBackup: panel login throttled.', self::LOG_CONTEXT);

                throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()), 'Too many SiteBackup panel login attempts.');
            }
        }

        if (!$this->inner->authenticate($request, $password)) {
            $this->logger?->notice('SiteBackup: panel login failed.', self::LOG_CONTEXT);

            return false;
        }
        $this->logger?->info('SiteBackup: panel login succeeded.', self::LOG_CONTEXT);

        $limiter?->reset();
        if ($request->hasSession()) {
            $request->getSession()->migrate(true);
        }

        return true;
    }

    public function logout(Request $request): void
    {
        $this->inner->logout($request);
    }

    public function isProtectionEnabled(): bool
    {
        return $this->inner->isProtectionEnabled();
    }

    public function isMisconfigured(): bool
    {
        return $this->inner->isMisconfigured();
    }
}

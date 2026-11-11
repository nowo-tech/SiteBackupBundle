<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function max;
use function time;

/**
 * Hardens any {@see PanelAccessGateInterface} (or deprecated {@see SiteBackupAccessGateInterface}) used by the panel
 * login (v1.17+):
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
 * Retry-After is computed against system time on purpose: the Symfony rate limiter itself uses microtime(), not an
 * injectable clock (REQ-DI-001 exception).
 *
 * Both entry points are accepted: a Symfony `Request` (the panel controller) or a framework-free
 * {@see PanelRequestContext}. The inner gate is called through {@see PanelAccessGateInterface} when it implements it;
 * an inner gate on the deprecated port only can only be reached with a `Request`, and a context call then denies
 * (fail closed).
 *
 * Stateless (worker-safe): the limiter state lives in the limiter storage (cache pool).
 */
final readonly class ThrottledSiteBackupAccessGate implements SiteBackupAccessGateInterface, PanelAccessGateInterface
{
    /** REQ-OBS-001: no client IP, session id or password in the records. */
    private const LOG_CONTEXT = ['bundle' => 'site_backup', 'action' => 'panel_login'];

    public function __construct(
        private SiteBackupAccessGateInterface|PanelAccessGateInterface $inner,
        private ?RateLimiterFactoryInterface $limiterFactory = null,
        private ?LoggerInterface $logger = null,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function isAuthenticated(Request|PanelRequestContext $request): bool
    {
        if ($this->inner instanceof PanelAccessGateInterface) {
            return $this->inner->isAuthenticated($this->context($request));
        }

        return $request instanceof Request && $this->inner->isAuthenticated($request);
    }

    public function authenticate(Request|PanelRequestContext $request, #[SensitiveParameter] string $password): bool
    {
        if (!$this->inner->isProtectionEnabled()) {
            return $this->innerAuthenticate($request, $password);
        }

        $clientIp = $request instanceof Request ? $request->getClientIp() : $request->clientIp;
        $limiter  = $this->limiterFactory?->create($clientIp ?? 'unknown');
        if ($limiter instanceof LimiterInterface) {
            $limit = $limiter->consume(1);
            if (!$limit->isAccepted()) {
                $this->logger?->warning('SiteBackup: panel login throttled.', self::LOG_CONTEXT);

                throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many SiteBackup panel login attempts.');
            }
        }

        if (!$this->innerAuthenticate($request, $password)) {
            $this->logger?->notice('SiteBackup: panel login failed.', self::LOG_CONTEXT);

            return false;
        }
        $this->logger?->info('SiteBackup: panel login succeeded.', self::LOG_CONTEXT);

        $limiter?->reset();
        if ($request instanceof PanelRequestContext) {
            $request->session?->regenerateId();
        } elseif ($request->hasSession()) {
            $request->getSession()->migrate(true);
        }

        return true;
    }

    public function logout(Request|PanelRequestContext $request): void
    {
        if ($this->inner instanceof PanelAccessGateInterface) {
            $this->inner->logout($this->context($request));
        } elseif ($request instanceof Request) {
            $this->inner->logout($request);
        }
    }

    public function isProtectionEnabled(): bool
    {
        return $this->inner->isProtectionEnabled();
    }

    public function isMisconfigured(): bool
    {
        return $this->inner->isMisconfigured();
    }

    private function innerAuthenticate(Request|PanelRequestContext $request, #[SensitiveParameter] string $password): bool
    {
        if ($this->inner instanceof PanelAccessGateInterface) {
            return $this->inner->authenticate($this->context($request), $password);
        }

        // Deprecated inner port: reachable with a Request only; a context call is denied (fail closed).
        return $request instanceof Request && $this->inner->authenticate($request, $password);
    }

    private function context(Request|PanelRequestContext $request): PanelRequestContext
    {
        return $request instanceof PanelRequestContext ? $request : PanelRequestContextFactory::fromRequest($request, $this->tokenStorage);
    }
}

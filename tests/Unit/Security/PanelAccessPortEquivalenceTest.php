<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Security;

use Nowo\SiteBackupBundle\Security\HttpFoundationPanelSession;
use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;
use Nowo\SiteBackupBundle\Security\PanelRequestContextFactory;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\LegacyHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\PortHostAccessGate;
use Nowo\SiteBackupBundle\Tests\Support\Ports\RecordingPanelSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function password_hash;
use function sprintf;

use const PASSWORD_BCRYPT;

/**
 * The access gate controls backup and restore: the framework-free {@see PanelAccessGateInterface} entry points must
 * decide exactly like the deprecated {@see SiteBackupAccessGateInterface} ones, deny by default, and keep the login
 * throttle and the session id rotation.
 */
final class PanelAccessPortEquivalenceTest extends TestCase
{
    private function secret(): string
    {
        return 'REDACTED-TEST-x';
    }

    /**
     * Password gate: Request entry point vs context entry point, over every configuration / session / password case.
     */
    public function testPasswordGateRequestAndContextAgree(): void
    {
        $hash  = password_hash($this->secret(), PASSWORD_BCRYPT, ['cost' => 4]);
        $cases = 0;
        foreach ([[$hash, true], [$hash, false], [null, true], ['', true], [null, false]] as [$configuredHash, $enabled]) {
            foreach ([true, false] as $withSession) {
                foreach ([$this->secret(), 'wrong', ''] as $password) {
                    foreach ([false, true] as $preAuthenticated) {
                        $gate = new PasswordSiteBackupAccessGate($configuredHash, $enabled);
                        $old  = $this->scenario($gate, $this->request($withSession, $preAuthenticated), $password);
                        $new  = $this->scenario($gate, $this->context($withSession, $preAuthenticated), $password);
                        self::assertSame($old, $new, sprintf('hash %s, enabled %d, session %d, password %s, pre %d', $configuredHash === null ? 'null' : ($configuredHash === '' ? 'empty' : 'set'), $enabled, $withSession, $password === $this->secret() ? 'right' : $password, $preAuthenticated));
                        ++$cases;
                    }
                }
            }
        }
        self::assertSame(60, $cases);
    }

    public function testPasswordGateDeniesByDefault(): void
    {
        $misconfigured = new PasswordSiteBackupAccessGate(null);
        self::assertTrue($misconfigured->isMisconfigured());
        self::assertFalse($misconfigured->isAuthenticated($this->context(true, true)));
        self::assertFalse($misconfigured->authenticate($this->context(true, false), $this->secret()));

        $gate = new PasswordSiteBackupAccessGate(password_hash($this->secret(), PASSWORD_BCRYPT, ['cost' => 4]));
        self::assertFalse($gate->isAuthenticated(new PanelRequestContext()), 'No session: deny.');
        self::assertFalse($gate->authenticate(new PanelRequestContext(), $this->secret()), 'No session: the flag cannot be kept, deny.');
        self::assertFalse($gate->isAuthenticated($this->context(true, false)));
    }

    /**
     * Through the throttled wrapper (what the panel calls): a host gate on the deprecated port and the same gate on
     * the new port give the same answers, the same 429 and the same session id rotation.
     */
    public function testThrottledWrapperAgreesForLegacyAndPortInnerGates(): void
    {
        foreach ([[$this->secret(), true], [null, true], ['', true], [$this->secret(), false]] as [$secret, $enabled]) {
            $legacy = new ThrottledSiteBackupAccessGate(new LegacyHostAccessGate($secret, $enabled), $this->limiter());
            $port   = new ThrottledSiteBackupAccessGate(new PortHostAccessGate($secret, $enabled), $this->limiter());

            foreach ([['wrong', '10.0.0.1'], ['', '10.0.0.1'], [$this->secret(), '10.0.0.2'], [$this->secret(), '10.0.0.1'], ['wrong', '10.0.0.1']] as [$password, $ip]) {
                $a = $this->request(true, false, $ip);
                $b = $this->request(true, false, $ip);
                self::assertSame($this->throttledOutcome($legacy, $a, $password), $this->throttledOutcome($port, $b, $password), sprintf('%s/%d %s %s', $secret === null ? 'null' : $secret, $enabled, $password, $ip));
            }
            self::assertSame($legacy->isMisconfigured(), $port->isMisconfigured());
            self::assertSame($legacy->isProtectionEnabled(), $port->isProtectionEnabled());
        }
    }

    public function testThrottledWrapperThrottlesAndRotatesThroughTheContext(): void
    {
        $gate    = new ThrottledSiteBackupAccessGate(new PortHostAccessGate($this->secret()), $this->limiter(2));
        $context = $this->context(true, false, '192.0.2.7');

        self::assertFalse($gate->authenticate($context, 'wrong'));
        $session = $this->recordingSession();
        self::assertTrue($gate->authenticate(new PanelRequestContext('192.0.2.7', null, $session), $this->secret()));
        self::assertSame(1, $session->regenerated, 'Session id rotated after a successful login.');
        self::assertTrue($gate->isAuthenticated(new PanelRequestContext('192.0.2.7', null, $session)));

        // Limiter reset on success: two more failures, then 429 for this client IP only.
        self::assertFalse($gate->authenticate($context, 'wrong'));
        self::assertFalse($gate->authenticate($context, 'wrong'));
        try {
            $gate->authenticate($context, $this->secret());
            self::fail('Expected TooManyRequestsHttpException.');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(429, $e->getStatusCode());
        }
        self::assertTrue($gate->authenticate(new PanelRequestContext('192.0.2.8', null, $this->recordingSession()), $this->secret()));

        $gate->logout(new PanelRequestContext('192.0.2.7', null, $session));
        self::assertFalse($gate->isAuthenticated(new PanelRequestContext('192.0.2.7', null, $session)));
    }

    /**
     * A context call can never reach a host gate on the deprecated port (it needs a Request): it is denied.
     */
    public function testContextCallsToALegacyInnerGateAreDenied(): void
    {
        $gate    = new ThrottledSiteBackupAccessGate(new LegacyHostAccessGate($this->secret()), $this->limiter());
        $session = $this->recordingSession();
        $session->set('host_ops_ok', true);
        $context = new PanelRequestContext('10.0.0.1', null, $session);

        self::assertFalse($gate->isAuthenticated($context));
        self::assertFalse($gate->authenticate($context, $this->secret()));
        self::assertSame(0, $session->regenerated);
        $gate->logout($context);
        self::assertTrue($session->get('host_ops_ok'), 'No-op: the legacy gate is not called.');
    }

    public function testInnerGateReceivesTheUserIdentifierOfTheToken(): void
    {
        $inner = new class implements PanelAccessGateInterface {
            public ?string $seenUser = null;

            public function isAuthenticated(PanelRequestContext $context): bool
            {
                $this->seenUser = $context->userIdentifier;

                return $context->userIdentifier === 'ops@example.test';
            }

            public function authenticate(PanelRequestContext $context, string $password): bool
            {
                return false;
            }

            public function logout(PanelRequestContext $context): void
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
        };
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ops@example.test', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));

        self::assertTrue((new ThrottledSiteBackupAccessGate($inner, null, null, $tokens))->isAuthenticated($this->request(true, false)));
        self::assertSame('ops@example.test', $inner->seenUser);
        self::assertFalse((new ThrottledSiteBackupAccessGate($inner))->isAuthenticated($this->request(true, false)), 'No token storage: no user.');
    }

    public function testContextFactoryAndSessionAdapter(): void
    {
        $request = $this->request(true, false, '198.51.100.4');
        $context = PanelRequestContextFactory::fromRequest($request);
        self::assertSame('198.51.100.4', $context->clientIp);
        self::assertNull($context->userIdentifier);
        self::assertInstanceOf(HttpFoundationPanelSession::class, $context->session);

        $context->session->set('k', 'v');
        self::assertSame('v', $request->getSession()->get('k'));
        $before = $request->getSession()->getId();
        $context->session->regenerateId();
        self::assertNotSame($before, $request->getSession()->getId());
        self::assertSame('v', $context->session->get('k'));
        $context->session->remove('k');
        self::assertNull($request->getSession()->get('k'));

        self::assertNull(PanelRequestContextFactory::fromRequest(Request::create('/'))->session);
    }

    /**
     * @return array{bool, bool, bool, bool, bool}
     */
    private function scenario(PasswordSiteBackupAccessGate $gate, Request|PanelRequestContext $input, string $password): array
    {
        $before = $gate->isAuthenticated($input);
        $login  = $gate->authenticate($input, $password);
        $after  = $gate->isAuthenticated($input);
        $gate->logout($input);

        return [$before, $login, $after, $gate->isAuthenticated($input), $gate->isMisconfigured()];
    }

    /**
     * @return array{string, bool, bool}|array{string, int}
     */
    private function throttledOutcome(ThrottledSiteBackupAccessGate $gate, Request $request, string $password): array
    {
        $before = $request->getSession()->getId();
        try {
            $login = $gate->authenticate($request, $password);
        } catch (TooManyRequestsHttpException $e) {
            return ['429', $e->getStatusCode()];
        }

        return ['login', $login, $before !== $request->getSession()->getId()];
    }

    private function request(bool $withSession, bool $preAuthenticated, string $ip = '10.0.0.1'): Request
    {
        $request = Request::create('/_site_backup', 'POST', server: ['REMOTE_ADDR' => $ip]);
        if ($withSession) {
            $session = new Session(new MockArraySessionStorage());
            $session->start();
            if ($preAuthenticated) {
                $session->set('_nowo_site_backup_auth', true);
            }
            $request->setSession($session);
        }

        return $request;
    }

    private function context(bool $withSession, bool $preAuthenticated, string $ip = '10.0.0.1'): PanelRequestContext
    {
        return PanelRequestContextFactory::fromRequest($this->request($withSession, $preAuthenticated, $ip));
    }

    private function limiter(int $limit = 3): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_login', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
    }

    private function recordingSession(): RecordingPanelSession
    {
        return new RecordingPanelSession();
    }
}

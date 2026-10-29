<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Security;

use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

use function json_encode;

final class ThrottledSiteBackupAccessGateTest extends TestCase
{
    /** bcrypt (cost 4) of "secret". */
    private const HASH = '$2y$04$HsjSLBF8m.DViUVXdzhNEOXJmSU2ryJPf1zcNPqwUrnc4yp.gVcGi';

    private function factory(int $limit = 2): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_login', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
    }

    private function request(string $ip = '10.0.0.1'): Request
    {
        $request = Request::create('/_site_backup', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testThrottlesPerClientIpAndThrows429WithRetryAfter(): void
    {
        $gate = new ThrottledSiteBackupAccessGate(new PasswordSiteBackupAccessGate(self::HASH), $this->factory(2));

        self::assertFalse($gate->authenticate($this->request(), 'wrong'));
        self::assertFalse($gate->authenticate($this->request(), 'wrong'));

        try {
            // Even the right password is refused once the window is exhausted.
            $gate->authenticate($this->request(), 'secret');
            self::fail('Expected TooManyRequestsHttpException.');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(429, $e->getStatusCode());
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
            self::assertIsInt($retryAfter);
            self::assertGreaterThanOrEqual(1, $retryAfter);
            self::assertLessThanOrEqual(900, $retryAfter);
        }

        // Other client IPs keep their own budget.
        self::assertTrue($gate->authenticate($this->request('10.0.0.2'), 'secret'));
    }

    public function testRequestWithoutClientIpUsesSharedUnknownKey(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('unknown')->willReturn($this->factory()->create('unknown'));

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $gate = new ThrottledSiteBackupAccessGate(new PasswordSiteBackupAccessGate(self::HASH), $factory);

        self::assertFalse($gate->authenticate($request, 'wrong'));
    }

    public function testSuccessResetsLimiterAndMigratesSession(): void
    {
        $factory = $this->factory(2);
        $gate    = new ThrottledSiteBackupAccessGate(new PasswordSiteBackupAccessGate(self::HASH), $factory);

        self::assertFalse($gate->authenticate($this->request(), 'wrong'));

        $request = $this->request();
        $session = $request->getSession();
        $session->start();
        $before = $session->getId();

        self::assertTrue($gate->authenticate($request, 'secret'));
        self::assertNotSame($before, $session->getId());
        self::assertTrue($gate->isAuthenticated($request));

        // Reset: the full budget is available again for this IP.
        self::assertSame(2, $factory->create('10.0.0.1')->consume(0)->getRemainingTokens());
    }

    public function testSuccessWithoutSessionStillResetsLimiter(): void
    {
        $inner = $this->createMock(SiteBackupAccessGateInterface::class);
        $inner->method('isProtectionEnabled')->willReturn(true);
        $inner->method('authenticate')->willReturn(true);
        $factory = $this->factory(2);

        $gate = new ThrottledSiteBackupAccessGate($inner, $factory);

        self::assertTrue($gate->authenticate(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.9']), 'x'));
        self::assertSame(2, $factory->create('10.0.0.9')->consume(0)->getRemainingTokens());
    }

    public function testDisabledThrottleOnlyMigratesSession(): void
    {
        $gate = new ThrottledSiteBackupAccessGate(new PasswordSiteBackupAccessGate(self::HASH));

        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($gate->authenticate($this->request(), 'wrong'));
        }

        $request = $this->request();
        $request->getSession()->start();
        $before = $request->getSession()->getId();
        self::assertTrue($gate->authenticate($request, 'secret'));
        self::assertNotSame($before, $request->getSession()->getId());
    }

    public function testProtectionDisabledBypassesLimiterAndSessionRotation(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->expects(self::never())->method('create');

        $gate    = new ThrottledSiteBackupAccessGate(new PasswordSiteBackupAccessGate(null, false), $factory);
        $request = $this->request();
        $request->getSession()->start();
        $before = $request->getSession()->getId();

        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($gate->authenticate($request, 'anything'));
        }
        self::assertSame($before, $request->getSession()->getId());
        self::assertFalse($gate->isProtectionEnabled());
    }

    public function testDelegatesOtherMethods(): void
    {
        $inner = $this->createMock(SiteBackupAccessGateInterface::class);
        $inner->expects(self::once())->method('isAuthenticated')->willReturn(true);
        $inner->expects(self::once())->method('logout');
        $inner->expects(self::once())->method('isProtectionEnabled')->willReturn(true);
        $inner->expects(self::once())->method('isMisconfigured')->willReturn(true);

        $gate    = new ThrottledSiteBackupAccessGate($inner, $this->factory());
        $request = new Request();

        self::assertTrue($gate->isAuthenticated($request));
        $gate->logout($request);
        self::assertTrue($gate->isProtectionEnabled());
        self::assertTrue($gate->isMisconfigured());
    }

    /**
     * REQ-OBS-001: attempts are logged without the client IP or the password.
     */
    public function testLoginAttemptsAreLoggedWithoutIpOrPassword(): void
    {
        $logger = new RecordingLogger();
        $gate   = new ThrottledSiteBackupAccessGate(new PasswordSiteBackupAccessGate(self::HASH), $this->factory(2), logger: $logger);

        self::assertFalse($gate->authenticate($this->request('10.9.8.7'), 'wrong'));
        self::assertTrue($gate->authenticate($this->request('10.9.8.7'), 'secret'));
        self::assertFalse($gate->authenticate($this->request('10.9.8.7'), 'wrong'));
        self::assertFalse($gate->authenticate($this->request('10.9.8.7'), 'wrong'));

        try {
            $gate->authenticate($this->request('10.9.8.7'), 'wrong');
            self::fail('Expected TooManyRequestsHttpException.');
        } catch (TooManyRequestsHttpException) {
        }

        self::assertSame([
            'notice SiteBackup: panel login failed.',
            'info SiteBackup: panel login succeeded.',
            'notice SiteBackup: panel login failed.',
            'notice SiteBackup: panel login failed.',
            'warning SiteBackup: panel login throttled.',
        ], $logger->lines());
        $all = (string) json_encode($logger->records);
        self::assertStringNotContainsString('10.9.8.7', $all);
        self::assertStringNotContainsString('wrong', $all);
        self::assertSame(['bundle' => 'site_backup', 'action' => 'panel_login'], $logger->records[0]['context']);
    }
}

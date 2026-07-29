<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Security;

use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use const PASSWORD_DEFAULT;

final class PasswordSiteBackupAccessGateTest extends TestCase
{
    public function testDisabledAllowsAccess(): void
    {
        $gate    = new PasswordSiteBackupAccessGate(null, false);
        $request = new Request();

        self::assertFalse($gate->isProtectionEnabled());
        self::assertFalse($gate->isMisconfigured());
        self::assertTrue($gate->isAuthenticated($request));
        self::assertTrue($gate->authenticate($request, 'anything'));
    }

    public function testMisconfiguredWhenEnabledWithoutHash(): void
    {
        $gate = new PasswordSiteBackupAccessGate(null, true);

        self::assertTrue($gate->isProtectionEnabled());
        self::assertTrue($gate->isMisconfigured());
        self::assertFalse($gate->isAuthenticated(new Request()));
        self::assertFalse($gate->authenticate(new Request(), 'secret'));
    }

    public function testMisconfiguredWhenEnabledWithEmptyHash(): void
    {
        $gate = new PasswordSiteBackupAccessGate('', true);

        self::assertTrue($gate->isMisconfigured());
        self::assertFalse($gate->isAuthenticated(new Request()));
    }

    public function testAuthenticateAndLogoutWithSession(): void
    {
        $hash    = password_hash('secret', PASSWORD_DEFAULT);
        $gate    = new PasswordSiteBackupAccessGate($hash, true);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        self::assertFalse($gate->isMisconfigured());
        self::assertFalse($gate->isAuthenticated($request));
        self::assertFalse($gate->authenticate($request, 'wrong'));
        self::assertTrue($gate->authenticate($request, 'secret'));
        self::assertTrue($gate->isAuthenticated($request));

        $gate->logout($request);
        self::assertFalse($gate->isAuthenticated($request));
    }

    public function testFailsClosedWithoutSession(): void
    {
        $hash    = password_hash('secret', PASSWORD_DEFAULT);
        $gate    = new PasswordSiteBackupAccessGate($hash, true);
        $request = new Request();

        self::assertFalse($gate->isAuthenticated($request));
        self::assertFalse($gate->authenticate($request, 'secret'));
    }

    public function testLogoutWithoutSessionIsNoOp(): void
    {
        $gate    = new PasswordSiteBackupAccessGate(password_hash('x', PASSWORD_DEFAULT), true);
        $request = new Request();
        $gate->logout($request);
        self::assertFalse($gate->isAuthenticated($request));
    }
}

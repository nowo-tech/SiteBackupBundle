<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Security;

use Nowo\SiteBackupBundle\Security\AllowAllSiteBackupAccessChecker;
use Nowo\SiteBackupBundle\Security\ConfigurableSiteBackupAccessChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SiteBackupAccessCheckerTest extends TestCase
{
    public function testAllowAllAlwaysGrants(): void
    {
        $checker = new AllowAllSiteBackupAccessChecker();

        self::assertTrue($checker->canAccess(null));
        self::assertTrue($checker->canAccess(new stdClass()));
    }

    public function testConfigurableGrantsWhenRolesEmpty(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::never())->method('isGranted');

        $checker = new ConfigurableSiteBackupAccessChecker($auth, []);

        self::assertTrue($checker->canAccess(null));
    }

    public function testConfigurableGrantsWhenAnyRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');

        $checker = new ConfigurableSiteBackupAccessChecker($auth, ['ROLE_USER', 'ROLE_ADMIN']);

        self::assertTrue($checker->canAccess(null));
    }

    public function testConfigurableDeniesWhenNoRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(false);

        $checker = new ConfigurableSiteBackupAccessChecker($auth, ['ROLE_ADMIN']);

        self::assertFalse($checker->canAccess(null));
    }
}

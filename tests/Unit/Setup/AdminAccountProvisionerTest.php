<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserData;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\AdminUserStep;
use Nowo\SiteBackupBundle\Tests\Support\Ports\TypedHostProvisioner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function print_r;

final class AdminAccountProvisionerTest extends TestCase
{
    private function password(): string
    {
        return 'REDACTED-TEST-x';
    }

    public function testTypedProvisionerReceivesTheValueObject(): void
    {
        $provisioner = new TypedHostProvisioner();
        $step        = new AdminUserStep('admin_user_0', 'Admin', $provisioner, ['ROLE_ADMIN', 'ROLE_OPS']);

        $result = $step->run($this->ctx(), new SetupStepInput(['email' => 'root@example.test', 'password' => $this->password()]));

        self::assertTrue($result->isSuccess());
        self::assertSame([['root@example.test', ['ROLE_ADMIN', 'ROLE_OPS']]], $provisioner->created);
        self::assertTrue($step->isComplete($this->ctx()), 'adminExists() of the typed port.');
    }

    /**
     * The typed and the array port create the same admin from the same input.
     */
    public function testLegacyAndTypedPortsGetTheSameAdmin(): void
    {
        $legacy = new class implements AdminUserProvisionerInterface {
            /** @var list<array{email: string, password: string, roles?: list<string>}> */
            public array $data = [];

            public function adminExists(): bool
            {
                return false;
            }

            public function createAdmin(array $data): void
            {
                $this->data[] = $data;
            }
        };
        $typed = new class implements AdminAccountProvisionerInterface {
            /** @var list<array{email: string, password: string, roles: list<string>}> */
            public array $data = [];

            public function adminExists(): bool
            {
                return false;
            }

            public function createAdminAccount(AdminUserData $admin): void
            {
                $this->data[] = ['email' => $admin->email, 'password' => $admin->plainPassword(), 'roles' => $admin->roles];
            }
        };
        foreach ([[['email' => 'a@example.test', 'password' => $this->password()], true], [['email' => '', 'password' => $this->password()], false], [['email' => 'a@example.test'], false]] as [$input, $created]) {
            $a = (new AdminUserStep('a', 'A', $legacy, ['ROLE_SUPER_ADMIN'], false))->run($this->ctx(), new SetupStepInput($input));
            $b = (new AdminUserStep('a', 'A', $typed, ['ROLE_SUPER_ADMIN'], false))->run($this->ctx(), new SetupStepInput($input));
            self::assertSame([$a->isSuccess(), $a->isWaitingForInput(), $a->getMessage()], [$b->isSuccess(), $b->isWaitingForInput(), $b->getMessage()]);
            self::assertSame($created, $a->isSuccess());
        }
        self::assertSame($legacy->data, $typed->data);
    }

    public function testTheTypedPortWinsWhenBothAreImplemented(): void
    {
        $both = new class implements AdminUserProvisionerInterface, AdminAccountProvisionerInterface {
            public string $called = '';

            public function adminExists(): bool
            {
                return false;
            }

            public function createAdmin(array $data): void
            {
                $this->called = 'array';
            }

            public function createAdminAccount(AdminUserData $admin): void
            {
                $this->called = 'typed';
            }
        };
        (new AdminUserStep('a', 'A', $both))->run($this->ctx(), new SetupStepInput(['email' => 'a@example.test', 'password' => $this->password()]));

        self::assertSame('typed', $both->called);
    }

    public function testNullProvisionerFailsOnBothPorts(): void
    {
        $null = new NullAdminUserProvisioner();
        self::assertFalse($null->adminExists());

        $result = (new AdminUserStep('a', 'A', $null))->run($this->ctx(), new SetupStepInput(['email' => 'a@example.test', 'password' => $this->password()]));
        self::assertFalse($result->isSuccess());

        $this->expectException(RuntimeException::class);
        $null->createAdminAccount(new AdminUserData('a@example.test', $this->password()));
    }

    public function testValueObjectHidesThePassword(): void
    {
        $admin = new AdminUserData('a@example.test', $this->password(), ['ROLE_ADMIN']);

        self::assertSame(['ROLE_ADMIN'], $admin->roles);
        self::assertStringNotContainsString($this->password(), print_r($admin, true));
        self::assertSame(['email' => 'a@example.test', 'plainPassword' => '***', 'roles' => ['ROLE_ADMIN']], $admin->__debugInfo());
        self::assertSame(['ROLE_SUPER_ADMIN'], (new AdminUserData('a@example.test', $this->password()))->roles);
        self::assertSame(['email' => 'a@example.test', 'plainPassword' => '***', 'roles' => ['ROLE_ADMIN']], AdminUserData::castForVarDumper($admin, []));
    }

    public function testValueObjectRejectsEmptyCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AdminUserData('', $this->password());
    }

    private function ctx(): SetupContext
    {
        return new SetupContext('/tmp', 'default');
    }
}

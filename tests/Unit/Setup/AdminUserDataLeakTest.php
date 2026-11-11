<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use LogicException;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\SiteBackupBundle\Setup\AdminUserData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function json_encode;
use function print_r;
use function serialize;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * MJ-S-L1: the admin password never leaks through the usual dump / export paths.
 */
final class AdminUserDataLeakTest extends TestCase
{
    private function password(): string
    {
        return 'REDACTED-TEST-leak';
    }

    private function admin(): AdminUserData
    {
        return new AdminUserData('a@example.test', $this->password(), ['ROLE_ADMIN']);
    }

    public function testAccessorReturnsThePassword(): void
    {
        self::assertSame($this->password(), $this->admin()->plainPassword());
    }

    public function testJsonIsMasked(): void
    {
        $json = json_encode($this->admin(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($this->password(), $json);
        self::assertSame('{"email":"a@example.test","plainPassword":"***","roles":["ROLE_ADMIN"]}', $json);
    }

    public function testVarExportArrayCastAndPrintRDoNotShowIt(): void
    {
        $admin = $this->admin();

        self::assertStringNotContainsString($this->password(), var_export($admin, true));
        self::assertStringNotContainsString($this->password(), print_r((array) $admin, true));
        self::assertStringNotContainsString($this->password(), print_r($admin, true));
    }

    public function testVarDumperIsMasked(): void
    {
        NowoSiteBackupBundle::registerVarDumperCasters();
        $output = (new CliDumper())->dump((new VarCloner())->cloneVar($this->admin()), true);

        self::assertIsString($output);
        self::assertStringNotContainsString($this->password(), $output);
        self::assertStringContainsString('***', $output);
    }

    public function testCloneCarriesNoPassword(): void
    {
        $clone = clone $this->admin();

        $this->expectException(LogicException::class);
        $clone->plainPassword();
    }

    public function testCannotBeSerialised(): void
    {
        $this->expectException(LogicException::class);
        serialize($this->admin());
    }

    public function testCannotBeUnserialised(): void
    {
        $this->expectException(LogicException::class);
        (new ReflectionClass(AdminUserData::class))->newInstanceWithoutConstructor()->__unserialize([]);
    }
}

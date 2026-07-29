<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use const PHP_BINARY;

final class ConsoleProcessRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-runner-' . uniqid('', true);
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRunPhpVersion(): void
    {
        $runner = new ConsoleProcessRunner($this->dir, PHP_BINARY, 30);
        $result = $runner->run(['--version']);
        self::assertFalse($result['ok']);
    }

    public function testRunOrFailThrows(): void
    {
        $runner = new ConsoleProcessRunner($this->dir, PHP_BINARY, 30);
        $this->expectException(RuntimeException::class);
        $runner->runOrFail(['does-not-exist-command']);
    }
}

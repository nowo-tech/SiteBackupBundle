<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function file_put_contents;

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

    /**
     * REQ-RUNTIME-001: a hung console step is killed at process_timeout (ProcessTimedOutException, as before).
     */
    public function testHungCommandTimesOut(): void
    {
        (new Filesystem())->mkdir($this->dir . '/bin');
        file_put_contents($this->dir . '/bin/console', "sleep 60\n");
        $runner = new ConsoleProcessRunner($this->dir, 'sh', 1);

        $this->expectException(ProcessTimedOutException::class);
        $runner->run(['doctrine:migrations:migrate']);
    }
}

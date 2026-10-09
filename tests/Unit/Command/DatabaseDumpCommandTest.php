<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Command;

use Nowo\SiteBackupBundle\Command\DatabaseDumpCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function chmod;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class DatabaseDumpCommandTest extends TestCase
{
    private string $fakeBinary;

    protected function setUp(): void
    {
        $this->fakeBinary = sys_get_temp_dir() . '/fake-mysqldump-' . uniqid('', true) . '.sh';
        // Echo argv + MYSQL_PWD (never on argv) as "SQL"; exit 3 for user "fail".
        file_put_contents($this->fakeBinary, "#!/bin/sh\necho \"-- args: \$*\"\necho \"-- pwd: \$MYSQL_PWD\"\necho \"warn\" >&2\ncase \"\$*\" in *--user=fail*) exit 3;; esac\nexit 0\n");
        chmod($this->fakeBinary, 0o755);
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeBinary);
    }

    public function testStreamsDumpWithPasswordInEnvironment(): void
    {
        $tester = new CommandTester(new DatabaseDumpCommand('mysql://app:p%40ss@db:3306/shop', 30));
        $code   = $tester->execute(['--binary' => $this->fakeBinary, '--skip-ssl-verify-server-cert' => true, '--option' => ['--column-statistics=0']], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $code);
        $out = $tester->getDisplay();
        self::assertStringContainsString('-- args: --single-transaction --no-tablespaces --skip-ssl-verify-server-cert --host=db --port=3306 --user=app --column-statistics=0 shop', $out);
        self::assertStringContainsString('-- pwd: p@ss', $out);
        self::assertStringNotContainsString('warn', $out);
        self::assertStringContainsString('warn', $tester->getErrorOutput());
    }

    public function testUrlOptionOverridesDefaultAndPropagatesExitCode(): void
    {
        $tester = new CommandTester(new DatabaseDumpCommand());
        $code   = $tester->execute(['--url' => 'mysql://fail@h/d', '--binary' => $this->fakeBinary]);

        self::assertSame(3, $code);
        self::assertStringContainsString('--host=h', $tester->getDisplay());
    }

    public function testInvalidUrlFails(): void
    {
        $tester = new CommandTester(new DatabaseDumpCommand());
        $code   = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(2, $code);
        self::assertStringContainsString('DATABASE_URL is missing or invalid', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testNonConsoleOutputReceivesDiagnostics(): void
    {
        $tester = new CommandTester(new DatabaseDumpCommand('postgresql://u@h/d'));
        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('MySQL / MariaDB', $tester->getDisplay());
    }
}

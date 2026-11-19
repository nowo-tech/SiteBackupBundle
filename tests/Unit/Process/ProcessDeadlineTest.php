<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Process;

use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function hrtime;
use function is_dir;
use function is_file;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function usleep;

/**
 * REQ-RUNTIME-001: hard + idle timeout, process tree killed on expiry.
 */
final class ProcessDeadlineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-deadline-' . uniqid('', true);
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRunSetsBothTimeoutsAndKeepsOutput(): void
    {
        $process = new Process(['sh', '-c', 'echo out; sleep 0.2; echo err >&2']);
        $ticks   = 0;

        ProcessDeadline::run($process, 30, static function () use (&$ticks): void {
            ++$ticks;
        });

        self::assertTrue($process->isSuccessful());
        self::assertSame(30.0, $process->getTimeout());
        self::assertSame(30.0, $process->getIdleTimeout());
        self::assertGreaterThan(1, $ticks, 'tick before start and on polls');
        self::assertSame("out\n", $process->getOutput());
        self::assertSame("err\n", $process->getErrorOutput());
    }

    public function testRunWithoutTick(): void
    {
        $process = new Process(['true']);

        ProcessDeadline::run($process, 30);

        self::assertTrue($process->isSuccessful());
    }

    public function testTimeoutKillsTheProcessAndItsChildren(): void
    {
        if (!is_dir('/proc/self')) {
            self::markTestSkipped('Needs a Linux procfs to observe the child process.');
        }

        $pidFile = $this->dir . '/child.pid';
        // The shell starts a long child (like `mysqldump` under `sh -c "… > dump.sql"`) and waits for it.
        $process = new Process(['sh', '-c', 'sleep 60 & echo $! > ' . $pidFile . '; wait']);
        $started = hrtime(true);

        try {
            ProcessDeadline::run($process, 1);
            self::fail('Expected ProcessTimedOutException');
        } catch (ProcessTimedOutException $e) {
            self::assertTrue($e->isGeneralTimeout());
        }

        self::assertLessThan(10_000_000_000, hrtime(true) - $started, 'the deadline fired late');
        self::assertFalse($process->isRunning());
        self::assertFileExists($pidFile);
        $childPid = (int) trim((string) file_get_contents($pidFile));
        self::assertGreaterThan(0, $childPid);
        self::assertFalse($this->isAlive($childPid), 'the child process survived the timeout');
    }

    /**
     * Audit UT-3: a callback that throws (a lost operation lock) kills the process tree before the exception escapes.
     */
    public function testAThrowingTickKillsTheProcessTree(): void
    {
        if (!is_dir('/proc/self')) {
            self::markTestSkipped('Needs a Linux procfs to observe the child process.');
        }

        $pidFile = $this->dir . '/tick-child.pid';
        $process = new Process(['sh', '-c', 'sleep 60 & echo $! > ' . $pidFile . '; wait']);
        $calls   = 0;
        $started = hrtime(true);

        try {
            ProcessDeadline::run($process, 60, static function () use (&$calls, $pidFile): void {
                // The first call runs before start(); throw once the child exists.
                if (++$calls > 1 && is_file($pidFile)) {
                    throw new RuntimeException('lock lost');
                }
            });
            self::fail('Expected the callback exception');
        } catch (RuntimeException $e) {
            self::assertSame('lock lost', $e->getMessage());
        }

        self::assertLessThan(10_000_000_000, hrtime(true) - $started);
        self::assertFalse($process->isRunning());
        $childPid = (int) trim((string) file_get_contents($pidFile));
        self::assertGreaterThan(0, $childPid);
        self::assertFalse($this->isAlive($childPid), 'the child kept running without the lock');
    }

    /**
     * Audit UT2-2: with ext-posix the tree dies even when no `kill` binary exists (Debian php images).
     */
    public function testKillTreeNeedsNoKillBinaryWithPosix(): void
    {
        if (!is_dir('/proc/self') || !function_exists('posix_kill')) {
            self::markTestSkipped('Needs a Linux procfs and ext-posix.');
        }

        $process = $this->pipeline();
        self::assertTrue(ProcessDeadline::killTree($process, '/proc', null, null, '/nonexistent/kill'));
        self::assertFalse($process->isRunning());
        self::assertSame([], $this->survivors(), 'the pipeline survived');
    }

    /**
     * Audit UT2-2: without ext-posix a missing `kill` binary is reported, never silent.
     */
    /**
     * Audit UT3-3: a SIGKILL that posix_kill() cannot deliver (EPERM) is logged and reported.
     */
    public function testARefusedSigkillIsReported(): void
    {
        if (!is_dir('/proc/self') || !function_exists('posix_kill')) {
            self::markTestSkipped('Needs a Linux procfs and ext-posix.');
        }

        $process = $this->pipeline();
        $logger  = new RecordingLogger();
        $calls   = 0;
        self::assertFalse(ProcessDeadline::killTree($process, '/proc', $logger, true, 'kill', static function (int $pid) use (&$calls): bool {
            ++$calls;
            // Really kill it, but report the first one as refused.
            posix_kill($pid, 9);

            return $calls > 1;
        }));
        self::assertStringContainsString('SIGKILL failed', $logger->records[0]['message'] ?? '');
        self::assertSame([], $this->survivors());
    }

    public function testAMissingKillBinaryIsReported(): void
    {
        if (!is_dir('/proc/self')) {
            self::markTestSkipped('Needs a Linux procfs.');
        }

        $process = $this->pipeline();
        $logger  = new RecordingLogger();
        self::assertFalse(ProcessDeadline::killTree($process, '/proc', $logger, false, '/nonexistent/kill'));
        self::assertStringContainsString('descendants may survive', $logger->records[0]['message'] ?? '');

        // Clean up what survived (the root is already stopped by Process::stop()).
        foreach ($this->survivors() as $pid) {
            (new Process(['kill', '-KILL', (string) $pid]))->run();
        }
    }

    public function testAnUnstartableKillBinaryGoesToThePhpErrorLog(): void
    {
        if (!is_dir('/proc/self')) {
            self::markTestSkipped('Needs a Linux procfs.');
        }

        $process = $this->pipeline();
        $log     = $this->dir . '/php-error.log';
        $before  = ini_set('error_log', $log);
        try {
            // A NUL byte makes proc_open() refuse the command (no logger: the PHP error log gets it).
            self::assertFalse(ProcessDeadline::killTree($process, '/proc', null, false, "kill\0"));
        } finally {
            ini_set('error_log', (string) $before);
        }
        self::assertStringContainsString('descendants may survive', (string) @file_get_contents($log));

        foreach ($this->survivors() as $pid) {
            (new Process(['kill', '-KILL', (string) $pid]))->run();
        }
    }

    public function testTheKillBinaryFallbackKillsTheTree(): void
    {
        if (!is_dir('/proc/self')) {
            self::markTestSkipped('Needs a Linux procfs.');
        }

        $process = $this->pipeline();
        self::assertTrue(ProcessDeadline::killTree($process, '/proc', new RecordingLogger(), false));
        self::assertSame([], $this->survivors());
    }

    private function pipeline(): Process
    {
        $process = Process::fromShellCommandline('sleep 301 | cat > /dev/null');
        $process->start();
        $deadline = hrtime(true) + 5_000_000_000;
        while ($this->survivors() === [] && hrtime(true) < $deadline) {
            usleep(20_000);
        }
        self::assertNotSame([], $this->survivors(), 'the pipeline did not start');

        return $process;
    }

    /**
     * @return list<int> live (non-zombie) `sleep 301` processes
     */
    private function survivors(): array
    {
        $found = [];
        for ($i = 0; $i < 20; ++$i) {
            $found = [];
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                $cmd = str_replace("\0", ' ', (string) @file_get_contents($file));
                $pid = (int) basename(dirname($file));
                if (str_contains($cmd, 'sleep 301') && $this->running($pid)) {
                    $found[] = $pid;
                }
            }
            if ($found === []) {
                return [];
            }
            usleep(50_000);
        }

        return $found;
    }

    public function testKillTreeWithoutDescendantsStopsTheProcess(): void
    {
        $process = new Process(['sleep', '60']);
        $process->start();

        ProcessDeadline::killTree($process, $this->dir . '/no-procfs');

        self::assertFalse($process->isRunning());
    }

    public function testKillTreeOnANeverStartedProcess(): void
    {
        $process = new Process(['true']);

        ProcessDeadline::killTree($process);

        self::assertFalse($process->isRunning());
    }

    public function testDescendantPidsWalksTheTreeFromAFakeProcfs(): void
    {
        $proc = $this->dir . '/proc';
        $fs   = new Filesystem();
        // pid (comm) state ppid …  — comm with spaces and parentheses must not shift the fields.
        foreach ([
            100 => '100 (sh) S 1 100',
            101 => '101 (php (cli) x) S 100 100',
            102 => '102 (mysqldump) S 101 100',
            103 => '103 (other) S 1 103',
            104 => '104 (loop) S 104 104',
        ] as $pid => $stat) {
            $fs->mkdir($proc . '/' . $pid);
            file_put_contents($proc . '/' . $pid . '/stat', $stat . "\n");
        }
        $fs->mkdir($proc . '/105');
        file_put_contents($proc . '/105/stat', 'garbage without parenthesis');

        self::assertSame([101, 102], ProcessDeadline::descendantPids(100, $proc));
        self::assertSame([], ProcessDeadline::descendantPids(103, $proc));
        self::assertSame([], ProcessDeadline::descendantPids(104, $proc));
        self::assertSame([], ProcessDeadline::descendantPids(100, $this->dir . '/missing'));
    }

    private function running(int $pid): bool
    {
        $stat = is_file('/proc/' . $pid . '/stat') ? (string) @file_get_contents('/proc/' . $pid . '/stat') : '';

        return $stat !== '' && substr($stat, (int) strrpos($stat, ')') + 2, 1) !== 'Z';
    }

    private function isAlive(int $pid): bool
    {
        // Allow the kernel a moment to deliver SIGKILL.
        for ($i = 0; $i < 20; ++$i) {
            $stat = is_file('/proc/' . $pid . '/stat') ? (string) @file_get_contents('/proc/' . $pid . '/stat') : '';
            // Gone, or a zombie waiting for a parent that does not reap (container PID 1).
            if ($stat === '' || substr($stat, (int) strrpos($stat, ')') + 2, 1) === 'Z') {
                return false;
            }
            usleep(100_000);
        }

        return true;
    }
}

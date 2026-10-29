<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Process;

use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function file_get_contents;
use function file_put_contents;
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

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Process;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

use function array_diff;
use function array_map;
use function array_merge;
use function array_shift;
use function array_values;
use function constant;
use function defined;
use function error_log;
use function explode;
use function file_get_contents;
use function function_exists;
use function glob;
use function hrtime;
use function is_dir;
use function is_string;
use function posix_get_last_error;
use function posix_kill;
use function rtrim;
use function strrpos;
use function substr;
use function trim;
use function usleep;

/**
 * Runs a subprocess under a hard deadline (REQ-RUNTIME-001).
 *
 * - Sets both {@see Process::setTimeout()} and {@see Process::setIdleTimeout()} to the operation timeout.
 * - When the deadline expires the whole process tree is killed: descendants first (a `sh -c "dump > file"`
 *   pipeline or `php bin/console …` would otherwise leave `mysqldump` running as an orphan), then the
 *   process itself ({@see Process::stop()} with a 0 s grace period, i.e. SIGKILL).
 * - Throws Symfony's {@see ProcessTimedOutException}, like {@see Process::run()} does on timeout.
 *
 * Descendants are discovered through `/proc` (Linux). Elsewhere only the direct process is stopped.
 *
 * Stateless: static helpers only.
 */
final class ProcessDeadline
{
    private const POLL_MICROSECONDS = 50_000;

    private const SIGSTOP_LINUX = 19;

    private const SIGKILL_LINUX = 9;

    private const ESRCH = 3;

    /** Rounds of stop-and-collect: a stopped process cannot fork, so new descendants stop appearing quickly. */
    private const COLLECT_ROUNDS = 5;

    /**
     * Meant for subprocesses that write little to their own stdout / stderr (`tar`, a dump redirected to a file,
     * console steps): pipes are read on every 50 ms poll. Stream large outputs with {@see Process::run()} instead.
     *
     * @param (callable(): void)|null $tick called before start and on every poll while the process runs
     *
     * @throws ProcessTimedOutException when the process exceeds $timeoutSeconds (process tree already killed)
     */
    public static function run(Process $process, int $timeoutSeconds, ?callable $tick = null, string $procRoot = '/proc'): void
    {
        $process->setTimeout((float) $timeoutSeconds);
        $process->setIdleTimeout((float) $timeoutSeconds);

        if ($tick !== null) {
            $tick();
        }

        // Monotonic deadline taken before start(): it fires before Process' own timeout check.
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        $process->start();

        while ($process->isRunning()) {
            if (hrtime(true) >= $deadline) {
                self::killTree($process, $procRoot);

                throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
            }
            // Idle-timeout safety net (same value as the hard deadline).
            $process->checkTimeout();
            if ($tick !== null) {
                try {
                    $tick();
                } catch (Throwable $e) {
                    // 1.37+ (audit UT-3): a failing callback (a lost operation lock) stops the operation; the
                    // subprocess must not keep writing without the lock.
                    self::killTree($process, $procRoot);

                    throw $e;
                }
            }
            usleep(self::POLL_MICROSECONDS);
        }

        $process->wait();
    }

    /**
     * Kills every descendant of the process (SIGKILL), then the process itself.
     *
     * 1.37+ (audit UT2-2): with ext-posix the root is stopped (SIGSTOP) first, so a sequential shell cannot start its
     * next command, then descendants are stopped and collected again until no new one appears, and all are killed with
     * `posix_kill()` (no `kill` binary needed: Debian-based php images have none). Without ext-posix the `kill` binary
     * is used. A failure (the binary, or a SIGKILL that `posix_kill()` refuses) is logged: to `$logger` when given,
     * else to the PHP error log (the bundle's own callers pass none, so it is the PHP error log).
     *
     * @param bool|null $usePosix null = ext-posix when available (false only for tests of the fallback)
     * @param string $killBinary the fallback binary (tests)
     * @param (Closure(int): bool)|null $sigkill @internal test seam for the posix SIGKILL
     *
     * @return bool false when the fallback binary failed (descendants may survive)
     */
    public static function killTree(Process $process, string $procRoot = '/proc', ?LoggerInterface $logger = null, ?bool $usePosix = null, string $killBinary = 'kill', ?Closure $sigkill = null): bool
    {
        $pid = $process->getPid();
        if ($pid === null) {
            $process->stop(0);

            return true;
        }

        $ok = ($usePosix ?? function_exists('posix_kill')) ? self::killWithPosix($pid, $procRoot, $logger, $sigkill) : self::killWithBinary($pid, $procRoot, $killBinary, $logger);
        $process->stop(0);

        return $ok;
    }

    /**
     * @param (Closure(int): bool)|null $sigkill test seam: sends SIGKILL, true when delivered or the process is gone
     */
    private static function killWithPosix(int $pid, string $procRoot, ?LoggerInterface $logger, ?Closure $sigkill): bool
    {
        $stop = defined('SIGSTOP') ? constant('SIGSTOP') : self::SIGSTOP_LINUX;
        $kill = defined('SIGKILL') ? constant('SIGKILL') : self::SIGKILL_LINUX;

        @posix_kill($pid, $stop);
        $seen = [];
        for ($round = 0; $round < self::COLLECT_ROUNDS; ++$round) {
            $new = array_values(array_diff(self::descendantPids($pid, $procRoot), $seen));
            if ($new === []) {
                break;
            }
            foreach ($new as $child) {
                @posix_kill($child, $stop);
                $seen[] = $child;
            }
        }
        $failed = [];
        foreach ([...$seen, $pid] as $target) {
            // A process that exited meanwhile (ESRCH) is not a failure.
            $delivered = $sigkill instanceof Closure ? $sigkill($target) : (@posix_kill($target, $kill) || posix_get_last_error() === self::ESRCH);
            if (!$delivered) {
                $failed[] = $target;
            }
        }
        if ($failed !== []) {
            self::report($logger, 'SiteBackup: SIGKILL failed for part of the subprocess tree; it may survive.', $pid);

            return false;
        }

        return true;
    }

    private static function report(?LoggerInterface $logger, string $message, int $pid): void
    {
        if ($logger instanceof LoggerInterface) {
            $logger->error($message, ['bundle' => 'site_backup', 'pid' => $pid]);
        } else {
            error_log($message);
        }
    }

    private static function killWithBinary(int $pid, string $procRoot, string $killBinary, ?LoggerInterface $logger): bool
    {
        $ok          = self::signal($killBinary, '-STOP', [$pid]);
        $descendants = self::descendantPids($pid, $procRoot);
        if ($descendants !== []) {
            $ok = self::signal($killBinary, '-KILL', $descendants) && $ok;
        }
        if (!$ok) {
            self::report($logger, 'SiteBackup: could not signal the subprocess tree (no ext-posix and the kill binary failed); descendants may survive.', $pid);
        }

        return $ok;
    }

    /**
     * @param list<int> $pids
     */
    private static function signal(string $killBinary, string $signal, array $pids): bool
    {
        try {
            $kill = new Process(array_merge([$killBinary, $signal], array_map('strval', $pids)));
            $kill->setTimeout(5.0)->run();

            return $kill->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Descendant pids of $pid (children, grandchildren, …) read from `/proc/<pid>/stat`.
     *
     * @return list<int> empty when $procRoot is not a procfs directory
     */
    public static function descendantPids(int $pid, string $procRoot = '/proc'): array
    {
        if (!is_dir($procRoot)) {
            return [];
        }

        $children = [];
        foreach (glob(rtrim($procRoot, '/') . '/[0-9]*/stat') ?: [] as $statFile) {
            $stat = @file_get_contents($statFile);
            // "<pid> (<comm>) <state> <ppid> …": comm may contain spaces / parentheses, cut after the last ")".
            $close = is_string($stat) ? strrpos($stat, ')') : false;
            if ($close === false) {
                continue;
            }
            $fields = explode(' ', trim(substr($stat, $close + 2)));
            $ppid   = (int) ($fields[1] ?? 0);
            $self   = (int) $stat;
            if ($ppid > 0 && $self > 0) {
                $children[$ppid][] = $self;
            }
        }

        $found = [];
        $queue = [$pid];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (!isset($found[$child]) && $child !== $pid) {
                    $found[$child] = $child;
                    $queue[]       = $child;
                }
            }
        }

        return array_values($found);
    }
}

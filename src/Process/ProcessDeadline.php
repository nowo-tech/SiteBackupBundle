<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function array_map;
use function array_merge;
use function array_shift;
use function array_values;
use function explode;
use function file_get_contents;
use function glob;
use function hrtime;
use function is_dir;
use function is_string;
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
                $tick();
            }
            usleep(self::POLL_MICROSECONDS);
        }

        $process->wait();
    }

    /**
     * Kills every descendant of the process (SIGKILL), then the process itself.
     */
    public static function killTree(Process $process, string $procRoot = '/proc'): void
    {
        $pid         = $process->getPid();
        $descendants = $pid !== null ? self::descendantPids($pid, $procRoot) : [];
        if ($descendants !== []) {
            (new Process(array_merge(['kill', '-KILL'], array_map('strval', $descendants))))
                ->setTimeout(5.0)
                ->run();
        }

        $process->stop(0);
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

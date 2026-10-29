<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use RuntimeException;
use Symfony\Component\Process\Process;

use function implode;
use function sprintf;
use function trim;

/**
 * Runs `php bin/console …` under a hard + idle timeout (REQ-RUNTIME-001, {@see ProcessDeadline}).
 */
final class ConsoleProcessRunner
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $phpBinary,
        private readonly int $timeoutSeconds,
    ) {
    }

    /**
     * @param list<string> $consoleArgs arguments after bin/console (e.g. ['cache:clear'])
     *
     * @return array{ok: bool, output: string, error: string}
     */
    public function run(array $consoleArgs): array
    {
        $command = array_merge([$this->phpBinary, 'bin/console'], $consoleArgs, ['--no-interaction']);
        $process = new Process($command, $this->projectDir);
        // Hard + idle timeout; on expiry the process tree is killed and ProcessTimedOutException is thrown.
        ProcessDeadline::run($process, $this->timeoutSeconds);

        return [
            'ok'     => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error'  => $process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput(),
        ];
    }

    /**
     * @param list<string> $consoleArgs
     */
    public function runOrFail(array $consoleArgs): string
    {
        $result = $this->run($consoleArgs);
        if (!$result['ok']) {
            throw new RuntimeException(sprintf('Command failed (%s): %s', implode(' ', $consoleArgs), trim($result['error']) !== '' ? trim($result['error']) : 'unknown error'));
        }

        return $result['output'];
    }
}

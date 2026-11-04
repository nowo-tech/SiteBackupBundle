<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Crypto;

use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function clearstatcache;
use function dirname;
use function fclose;
use function fileperms;
use function fwrite;
use function hrtime;
use function is_executable;
use function is_file;
use function is_resource;
use function is_string;
use function mb_substr;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function realpath;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function stream_set_blocking;
use function trim;
use function usleep;
use function version_compare;

/**
 * age passphrase encryption through the `age` binary ≥ 1.3 and `age-plugin-batchpass` (1.29+, owner decision Q-O4
 * option A).
 *
 * - The passphrase is written to a dedicated pipe that the child sees as fd 3 (`AGE_PASSPHRASE_FD=3`): it is never in
 *   argv (visible in `ps`) nor in the environment (`/proc/<pid>/environ`). The child environment is built
 *   explicitly: `PATH`, the fd number and the scrypt work factors only.
 * - Files are standard age v1 files with an scrypt stanza: `age -d` (asking for the passphrase) or
 *   `age -d -j batchpass` open them.
 * - {@see self::assertAvailable()} refuses a missing binary, a version below 1.3 or a missing plugin.
 */
final class AgeBinaryEncryptor implements ArchiveEncryptorInterface
{
    public const NAME = 'age-scrypt';

    public const MIN_VERSION = '1.3.0';

    public const HEADER = 'age-encryption.org/v1';

    private const POLL_MICROSECONDS = 50_000;

    /** EO-L5 / EO-R4: the binary, resolved once to an absolute path when the service is built. */
    private readonly ?string $resolvedBinary;

    /** EO-R4: why the configured binary was refused (null when it resolved). */
    private ?string $refusal = null;

    public function __construct(
        private readonly string $binary = 'age',
        private readonly int $workFactor = 18,
        private readonly int $maxWorkFactor = 20,
        private readonly int $timeoutSeconds = 600,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly ?string $plugin = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($workFactor < 10 || $workFactor > 22 || $maxWorkFactor < $workFactor || $maxWorkFactor > 22) {
            throw new FormatUnavailableException('age work factors must satisfy 10 <= work_factor <= max_work_factor <= 22.');
        }
        $this->resolvedBinary = $this->resolveBinary();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function suffix(): string
    {
        return '.age';
    }

    public function recognises(string $header): bool
    {
        return str_starts_with($header, self::HEADER . "\n");
    }

    public function assertAvailable(): void
    {
        $binary = $this->resolvedBinary;
        if ($binary === null) {
            throw new FormatUnavailableException($this->refusal ?? sprintf('age encryption needs the "%s" binary (age >= %s with age-plugin-batchpass); set backup.encryption.age.binary to its absolute path.', $this->binary, self::MIN_VERSION));
        }

        $process = new Process([$binary, '--version']);
        $process->setTimeout(30.0);
        $process->run();
        $version = trim($process->getOutput() . $process->getErrorOutput());
        if (!$process->isSuccessful() || preg_match('/v?(\d+\.\d+\.\d+)/', $version, $m) !== 1) {
            throw new FormatUnavailableException(sprintf('Unable to read the age version from "%s".', $binary));
        }
        if (version_compare($m[1], self::MIN_VERSION, '<')) {
            throw new FormatUnavailableException(sprintf('age %s is too old: age >= %s with age-plugin-batchpass is required.', $m[1], self::MIN_VERSION));
        }

        if ($this->findPlugin($binary) === null) {
            throw new FormatUnavailableException('age-plugin-batchpass must sit next to the age binary (it ships with age >= 1.3); a plugin elsewhere on PATH is never used.');
        }
    }

    public function encrypt(string $plainFile, string $encryptedFile, #[SensitiveParameter] ArchivePassword $password, ?callable $tick = null): void
    {
        $this->assertAvailable();
        $this->run(['-e', '-j', 'batchpass', '-o', $encryptedFile, '--', $plainFile], $encryptedFile, $password, $tick, false);
    }

    public function decrypt(string $encryptedFile, string $plainFile, #[SensitiveParameter] ArchivePassword $password, ?callable $tick = null): void
    {
        $this->assertAvailable();
        $this->run(['-d', '-j', 'batchpass', '-o', $plainFile, '--', $encryptedFile], $plainFile, $password, $tick, true);
    }

    /**
     * @param list<string> $arguments
     * @param (callable(): void)|null $tick
     */
    private function run(array $arguments, string $output, #[SensitiveParameter] ArchivePassword $password, ?callable $tick, bool $decrypting): void
    {
        $binary = (string) $this->resolvedBinary;
        $env    = [
            // EO-L5: only the pinned binary's directory, so age can only start the plugin that sits next to it.
            'PATH'                           => dirname($binary),
            'AGE_PASSPHRASE_FD'              => '3',
            'AGE_PASSPHRASE_WORK_FACTOR'     => (string) $this->workFactor,
            'AGE_PASSPHRASE_MAX_WORK_FACTOR' => (string) $this->maxWorkFactor,
        ];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'r'],
        ];

        // Owner-only before age opens it: it holds a plaintext archive when decrypting.
        $this->filesystem->touch($output);
        $this->filesystem->chmod($output, 0o600);

        // proc_open (not Symfony Process): only it can hand the child an extra fd. The loop below enforces the deadline.
        // @phpstan-ignore-next-line
        $process = proc_open([$binary, ...$arguments], $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            $this->filesystem->remove($output);

            throw new FormatUnavailableException('Unable to start age.');
        }

        // The only copy of the passphrase the child gets: written to fd 3, then closed (EOF ends the read).
        fwrite($pipes[3], $password->reveal());
        fclose($pipes[3]);
        stream_set_blocking($pipes[2], false);

        $stderr   = '';
        $deadline = hrtime(true) + $this->timeoutSeconds * 1_000_000_000;
        $exitCode = -1;
        while (true) {
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (hrtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[2]);
                proc_close($process);
                $this->filesystem->remove($output);

                throw new FormatUnavailableException(sprintf('age timed out after %d seconds (process_timeout).', $this->timeoutSeconds));
            }
            if ($tick !== null) {
                $tick();
            }
            usleep(self::POLL_MICROSECONDS);
        }
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        if ($exitCode === 0) {
            return;
        }

        $this->filesystem->remove($output);
        $message = mb_substr(SecretRedactor::redact(trim($stderr)), 0, 300);
        // EO-I1: a wrong passphrase, a hostile work factor and a tampered / truncated file are told apart.
        if ($decrypting && str_contains($stderr, 'incorrect passphrase')) {
            throw new WrongPasswordException('Unable to decrypt the archive: wrong password.');
        }
        if ($decrypting && str_contains($stderr, 'work factor')) {
            throw new CorruptArchiveException('The age header asks for a scrypt work factor above backup.encryption.age.max_work_factor.');
        }
        if ($decrypting) {
            throw new CorruptArchiveException('The age archive failed its integrity check (tampered or truncated).', CorruptArchiveException::CHECKSUM);
        }

        throw new FormatUnavailableException(sprintf('age failed (exit %d): %s', $exitCode, $message === '' ? 'no output' : $message));
    }

    /**
     * EO-R4: an absolute path is used as given (after realpath); a bare name is looked up on PHP's PATH once, here,
     * and the result is logged. A relative path, or a binary in a world-writable directory, is refused.
     */
    private function resolveBinary(): ?string
    {
        if (str_contains($this->binary, '/')) {
            if (!str_starts_with($this->binary, '/')) {
                $this->refusal = sprintf('backup.encryption.age.binary "%s" is relative: use an absolute path.', $this->binary);

                return null;
            }
            $found = $this->binary;
        } else {
            $found = (new ExecutableFinder())->find($this->binary);
            if (!is_string($found) || $found === '') {
                return null;
            }
        }

        // Fresh permissions, not a cached stat (PHP 8.2 keeps the last stat across chmod()).
        clearstatcache(true);
        $real = realpath($found);
        if ($real === false || !is_file($real) || !is_executable($real)) {
            return null;
        }
        foreach ([$real, dirname($real)] as $path) {
            $mode = @fileperms($path);
            if ($mode === false || ($mode & 0o002) !== 0) {
                $this->refusal = sprintf('The age binary "%s" or its directory is world-writable: refused.', $real);

                return null;
            }
        }

        if ($real !== $this->binary) {
            $this->logger?->notice('age binary "{configured}" resolved to "{resolved}"; set backup.encryption.age.binary to an absolute path.', ['configured' => $this->binary, 'resolved' => $real]);
        }

        return $real;
    }

    private function findPlugin(string $binary): ?string
    {
        $beside = dirname($binary) . '/age-plugin-batchpass';
        if (!is_file($beside) || !is_executable($beside)) {
            return null;
        }
        if ($this->plugin !== null && realpath($this->plugin) !== realpath($beside)) {
            return null;
        }

        return $beside;
    }
}

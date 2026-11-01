<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function dirname;
use function file_get_contents;
use function hash;
use function hash_equals;
use function is_file;
use function random_bytes;
use function trim;

/**
 * One-time setup access token (1.27+, REQ-SEC-004 CG F1): issued on the server by
 * `nowo:site-backup:setup-token`, stored as a SHA-256 hash (0600) under var/site-backup/, accepted by the setup wizard
 * like setup.setup_token and removed when the wizard completes. Lets a first install open the web wizard without a
 * static setup.setup_token while the wizard stays closed to anonymous visitors (default-deny).
 */
final class SetupAccessTokenStore
{
    public function __construct(
        private readonly string $tokenFile,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Generates a new token (replacing any previous one) and returns it in clear; only its hash is stored.
     */
    public function issue(): string
    {
        $token = bin2hex(random_bytes(24));
        $this->filesystem->mkdir(dirname($this->tokenFile), 0o700);
        $this->filesystem->dumpFile($this->tokenFile, hash('sha256', $token) . "\n");
        $this->filesystem->chmod($this->tokenFile, 0o600);

        return $token;
    }

    public function hasToken(): bool
    {
        return is_file($this->tokenFile);
    }

    public function isValid(string $provided): bool
    {
        if ($provided === '' || !is_file($this->tokenFile)) {
            return false;
        }

        $stored = trim((string) file_get_contents($this->tokenFile));

        return $stored !== '' && hash_equals($stored, hash('sha256', $provided));
    }

    public function clear(): void
    {
        $this->filesystem->remove($this->tokenFile);
    }

    public function getTokenFile(): string
    {
        return $this->tokenFile;
    }
}

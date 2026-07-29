<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use DateTimeImmutable;
use JsonException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

use function bin2hex;
use function dirname;
use function escapeshellarg;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function fnmatch;
use function hash_file;
use function is_dir;
use function is_file;
use function is_readable;
use function json_decode;
use function json_encode;
use function ltrim;
use function random_bytes;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;

use const DATE_ATOM;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_VERSION;

/**
 * Builds an integral site backup: selected paths + optional DB dump + SHA-256 manifest.
 */
final class BackupArchiver
{
    /**
     * @param list<string> $includePaths relative to project dir
     * @param list<string> $excludePatterns Finder exclude patterns
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $storageDir,
        private readonly array $includePaths,
        private readonly array $excludePatterns,
        private readonly ?string $databaseDumpCommand,
        private readonly int $processTimeoutSeconds,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function create(?string $label = null, ?string $createdBy = null): BackupArtifact
    {
        $this->filesystem->mkdir($this->storageDir);

        $id         = (new DateTimeImmutable())->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $filename   = $id . '.tar.gz';
        $target     = rtrim($this->storageDir, '/\\') . '/' . $filename;
        $workDir    = sys_get_temp_dir() . '/nowo-site-backup-' . $id;
        $payloadDir = $workDir . '/payload';

        $this->filesystem->mkdir($payloadDir);

        try {
            $checksums = $this->copyIncludedPaths($payloadDir);
            $meta      = [
                'project_dir' => $this->projectDir,
                'includes'    => $this->includePaths,
                'excludes'    => $this->excludePatterns,
                'created_at'  => (new DateTimeImmutable())->format(DATE_ATOM),
                'label'       => $label,
                'created_by'  => $createdBy,
                'php_version' => PHP_VERSION,
            ];

            if ($this->databaseDumpCommand !== null && $this->databaseDumpCommand !== '') {
                $dumpRelative = 'database/dump.sql';
                $dumpAbsolute = $payloadDir . '/' . $dumpRelative;
                $this->filesystem->mkdir(dirname($dumpAbsolute));
                $this->runDatabaseDump($dumpAbsolute);
                $checksums[$dumpRelative] = (string) hash_file('sha256', $dumpAbsolute);
                $meta['database_dump']    = $dumpRelative;
            }

            $manifest = [
                'version'   => 1,
                'id'        => $id,
                'checksums' => $checksums,
                'meta'      => $meta,
            ];

            $manifestPath = $payloadDir . '/MANIFEST.json';
            try {
                file_put_contents(
                    $manifestPath,
                    json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                );
            } catch (JsonException $e) {
                throw new RuntimeException('Unable to write MANIFEST.json: ' . $e->getMessage(), 0, $e);
            }

            $this->createTarGz($payloadDir, $target);

            $archiveSha = (string) hash_file('sha256', $target);
            $size       = (int) filesize($target);

            $artifact = new BackupArtifact(
                id: $id,
                filename: $filename,
                absolutePath: $target,
                createdAt: new DateTimeImmutable(),
                sizeBytes: $size,
                archiveSha256: $archiveSha,
                checksums: $checksums,
                meta: $meta,
                label: $label,
                createdBy: $createdBy,
            );

            $this->writeSidecar($artifact);

            return $artifact;
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * @return list<BackupArtifact>
     */
    public function listArtifacts(): array
    {
        if (!is_dir($this->storageDir)) {
            return [];
        }

        $finder = (new Finder())->files()->in($this->storageDir)->name('*.meta.json')->sortByName(true);
        $list   = [];
        foreach ($finder as $file) {
            $raw = file_get_contents($file->getPathname());
            if ($raw === false) {
                continue;
            }
            try {
                /** @var array<string, mixed> $data */
                $data   = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $list[] = BackupArtifact::fromArray($data);
            } catch (JsonException) {
                continue;
            }
        }

        return array_reverse($list);
    }

    public function find(string $id): ?BackupArtifact
    {
        foreach ($this->listArtifacts() as $artifact) {
            if ($artifact->getId() === $id) {
                return $artifact;
            }
        }

        return null;
    }

    public function delete(string $id): bool
    {
        $artifact = $this->find($id);
        if (!$artifact instanceof BackupArtifact) {
            return false;
        }

        $this->filesystem->remove($artifact->getAbsolutePath());
        $sidecar = $this->sidecarPath($artifact->getId());
        if (is_file($sidecar)) {
            $this->filesystem->remove($sidecar);
        }

        return true;
    }

    /**
     * Verifies archive SHA-256 and every MANIFEST entry after extract to a temp dir.
     *
     * @return array{ok: bool, errors: list<string>, checksums: array<string, string>}
     */
    public function verifyIntegrity(BackupArtifact $artifact): array
    {
        $errors = [];
        if (!is_file($artifact->getAbsolutePath())) {
            return ['ok' => false, 'errors' => ['Archive file missing.'], 'checksums' => []];
        }

        $actualSha = (string) hash_file('sha256', $artifact->getAbsolutePath());
        if ($actualSha !== $artifact->getArchiveSha256()) {
            $errors[] = sprintf('Archive SHA-256 mismatch (expected %s, got %s).', $artifact->getArchiveSha256(), $actualSha);
        }

        $extractDir = sys_get_temp_dir() . '/nowo-site-backup-verify-' . $artifact->getId();
        $this->filesystem->mkdir($extractDir);

        try {
            $this->extractTarGz($artifact->getAbsolutePath(), $extractDir);
            $manifestFile = $extractDir . '/MANIFEST.json';
            if (!is_file($manifestFile)) {
                $errors[] = 'MANIFEST.json missing inside archive.';

                return ['ok' => false, 'errors' => $errors, 'checksums' => []];
            }

            try {
                /** @var array{checksums?: array<string, string>} $manifest */
                $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $errors[] = 'Invalid MANIFEST.json: ' . $e->getMessage();

                return ['ok' => false, 'errors' => $errors, 'checksums' => []];
            }

            $checksums = $manifest['checksums'] ?? [];
            foreach ($checksums as $relative => $expected) {
                $path = $extractDir . '/' . $relative;
                if (!is_file($path)) {
                    $errors[] = 'Missing file from manifest: ' . $relative;
                    continue;
                }
                $got = (string) hash_file('sha256', $path);
                if ($got !== $expected) {
                    $errors[] = sprintf('Checksum mismatch for %s', $relative);
                }
            }

            return ['ok' => $errors === [], 'errors' => $errors, 'checksums' => $checksums];
        } finally {
            $this->filesystem->remove($extractDir);
        }
    }

    public function extractTo(BackupArtifact $artifact, string $destinationDir): void
    {
        $this->filesystem->mkdir($destinationDir);
        $this->extractTarGz($artifact->getAbsolutePath(), $destinationDir);
    }

    /**
     * @return array<string, string>
     */
    private function copyIncludedPaths(string $payloadDir): array
    {
        $checksums = [];
        $project   = rtrim($this->projectDir, '/\\');

        foreach ($this->resolveIncludePaths() as $relative) {
            // '' = project root (entire tree minus exclude_patterns)
            $source = $relative === '' ? $project : $project . '/' . $relative;
            if (!file_exists($source)) {
                continue;
            }

            if (is_file($source)) {
                if ($this->isExcludedRelative($relative)) {
                    continue;
                }
                $dest = $payloadDir . '/' . $relative;
                $this->filesystem->mkdir(dirname($dest));
                $this->filesystem->copy($source, $dest, true);
                $checksums[$relative] = (string) hash_file('sha256', $dest);
                continue;
            }

            if (!is_dir($source) || !is_readable($source)) {
                continue;
            }

            $finder = (new Finder())->files()->in($source)->ignoreDotFiles(false);
            foreach ($this->excludePatterns as $pattern) {
                $finder->notPath($pattern);
            }

            foreach ($finder as $file) {
                $full     = $file->getPathname();
                $relInner = substr($full, strlen($source) + 1);
                $relInner = str_replace('\\', '/', $relInner);
                $relPath  = $relative === '' ? $relInner : $relative . '/' . $relInner;
                if ($this->isExcludedRelative($relPath)) {
                    continue;
                }
                $dest = $payloadDir . '/' . $relPath;
                $this->filesystem->mkdir(dirname($dest));
                $this->filesystem->copy($full, $dest, true);
                $checksums[$relPath] = (string) hash_file('sha256', $dest);
            }
        }

        return $checksums;
    }

    /**
     * Empty include_paths or "." → entire project root (paths without "./" prefix).
     * Omitting the config key still uses Symfony defaults (selective list), not this.
     *
     * @return list<string> relative paths; '' means project root
     */
    private function resolveIncludePaths(): array
    {
        if ($this->includePaths === []) {
            return [''];
        }

        $normalized = [];
        foreach ($this->includePaths as $relative) {
            $relative = trim(str_replace('\\', '/', (string) $relative), '/');
            if ($relative === '' || $relative === '.') {
                return [''];
            }
            $normalized[] = $relative;
        }

        return array_values(array_unique($normalized));
    }

    private function isExcludedRelative(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        // Always protect backup storage from nesting into the archive
        if (str_starts_with($relative, 'var/site-backup/') || str_contains($relative, '/var/site-backup/')) {
            return true;
        }

        foreach ($this->excludePatterns as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);
            if ($pattern === '') {
                continue;
            }
            if (fnmatch($pattern, $relative) || fnmatch('*/' . ltrim($pattern, '/'), $relative)) {
                return true;
            }
        }

        return false;
    }

    private function runDatabaseDump(string $dumpAbsolute): void
    {
        $command = $this->databaseDumpCommand;
        if ($command === null || $command === '') {
            return;
        }

        $process = Process::fromShellCommandline($command . ' > ' . escapeshellarg($dumpAbsolute));
        $process->setTimeout($this->processTimeoutSeconds);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Database dump failed: ' . $process->getErrorOutput());
        }
    }

    private function createTarGz(string $payloadDir, string $target): void
    {
        $process = new Process(['tar', '-czf', $target, '-C', $payloadDir, '.']);
        $process->setTimeout($this->processTimeoutSeconds);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('tar create failed: ' . $process->getErrorOutput());
        }
    }

    private function extractTarGz(string $archive, string $destination): void
    {
        $process = new Process(['tar', '-xzf', $archive, '-C', $destination]);
        $process->setTimeout($this->processTimeoutSeconds);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('tar extract failed: ' . $process->getErrorOutput());
        }
    }

    private function writeSidecar(BackupArtifact $artifact): void
    {
        try {
            file_put_contents(
                $this->sidecarPath($artifact->getId()),
                json_encode($artifact->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to write backup sidecar: ' . $e->getMessage(), 0, $e);
        }
    }

    private function sidecarPath(string $id): string
    {
        return rtrim($this->storageDir, '/\\') . '/' . $id . '.meta.json';
    }
}

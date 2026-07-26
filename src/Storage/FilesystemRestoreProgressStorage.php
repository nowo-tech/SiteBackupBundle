<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Storage;

use JsonException;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function json_decode;
use function json_encode;
use function rename;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Persists restore progress outside (or beside) the paths being overwritten.
 */
final class FilesystemRestoreProgressStorage implements RestoreProgressStorageInterface
{
    public function __construct(
        private readonly string $filePath,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function load(): RestoreProgress
    {
        if (!is_file($this->filePath)) {
            return new RestoreProgress();
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false || $raw === '') {
            return new RestoreProgress();
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new RestoreProgress();
        }

        return RestoreProgress::fromArray($data);
    }

    public function save(RestoreProgress $progress): void
    {
        $dir = dirname($this->filePath);
        $this->filesystem->mkdir($dir);

        try {
            $json = json_encode($progress->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode restore progress: ' . $e->getMessage(), 0, $e);
        }

        $tmp = tempnam($dir, 'restore_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temporary restore progress file.');
        }

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write restore progress file.');
        }

        if (!@rename($tmp, $this->filePath)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically replace restore progress file.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Storage;

use JsonException;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function array_reverse;
use function count;
use function dirname;
use function file;
use function file_put_contents;
use function is_file;
use function json_decode;
use function json_encode;
use function trim;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

final class FilesystemBackupHistoryStorage implements BackupHistoryStorageInterface
{
    public function __construct(
        private readonly string $filePath,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function append(BackupHistoryEntry $entry): void
    {
        $dir = dirname($this->filePath);
        $this->filesystem->mkdir($dir);

        try {
            $line = json_encode($entry->toArray(), JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode history entry: ' . $e->getMessage(), 0, $e);
        }

        if (file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to append backup history.');
        }
    }

    public function list(int $limit = 50): array
    {
        if ($limit < 1 || !is_file($this->filePath)) {
            return [];
        }

        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                /** @var array<string, mixed> $data */
                $data      = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $entries[] = BackupHistoryEntry::fromArray($data);
            } catch (JsonException) {
                continue;
            }
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }
}

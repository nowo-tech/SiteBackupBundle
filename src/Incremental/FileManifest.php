<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;
use JsonException;
use Nowo\SiteBackupBundle\Model\BackupOptions;

use function count;
use function hash;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function ksort;
use function preg_match;
use function str_contains;
use function str_replace;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * The full file list of a backup (1.36+): project-relative path → size, mtime, SHA-256, plus the `scope` (hash of the
 * include paths and exclude patterns it was collected with). Recorded in the meta of every chain backup
 * (`file_manifest`) and given back as {@see BackupOptions::$baseManifest} to archive only
 * what changed since.
 *
 * Meta form: `{"scope": "<sha256>", "entries": {"<path>": [size, mtime, "<sha256>"]}}`; `{"size", "mtime",
 * "sha256"}` objects are accepted too. An mtime of -1 means "not trusted" (the file changed while it was collected):
 * the next delta re-hashes it.
 */
final readonly class FileManifest
{
    public const UNTRUSTED_MTIME = -1;

    /** @var array<string, array{0: int, 1: int, 2: string}> */
    public array $entries;

    /**
     * @param array<string, array{0: int, 1: int, 2: string}> $entries path => [size, mtime, sha256]
     */
    public function __construct(array $entries, public string $scope)
    {
        if (preg_match('/^[0-9a-f]{64}$/', $scope) !== 1) {
            throw new InvalidArgumentException('The manifest scope is a SHA-256.');
        }
        foreach ($entries as $path => $entry) {
            self::assertPath((string) $path);
            if (!is_array($entry) || count($entry) !== 3 || !is_int($entry[0] ?? null) || $entry[0] < 0 || !is_int($entry[1] ?? null)
                || $entry[1] < self::UNTRUSTED_MTIME || !is_string($entry[2] ?? null) || preg_match('/^[0-9a-f]{64}$/', $entry[2]) !== 1) {
                throw new InvalidArgumentException('Manifest entries are [size, mtime, sha256].');
            }
        }
        ksort($entries, SORT_STRING);
        $this->entries = $entries;
    }

    /**
     * The scope of a collection: same include paths and exclude patterns ⇒ same scope.
     *
     * @param list<string> $includes
     * @param list<string> $excludes
     */
    public static function scopeOf(array $includes, array $excludes): string
    {
        return hash('sha256', self::json(['includes' => $includes, 'excludes' => $excludes]));
    }

    /**
     * @param array<mixed> $data the `file_manifest` meta value
     */
    public static function fromArray(array $data): self
    {
        $scope   = $data['scope'] ?? null;
        $entries = $data['entries'] ?? null;
        if (!is_string($scope) || !is_array($entries)) {
            throw new InvalidArgumentException('A file manifest has a scope and entries.');
        }

        $normalised = [];
        foreach ($entries as $path => $entry) {
            // A path made of digits only comes back from JSON as an integer key.
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Manifest entries are [size, mtime, sha256].');
            }
            $normalised[(string) $path] = isset($entry['sha256'])
                ? [$entry['size'] ?? null, $entry['mtime'] ?? null, $entry['sha256']]
                : [$entry[0] ?? null, $entry[1] ?? null, $entry[2] ?? null];
        }

        /* @phpstan-ignore argument.type (validated by the constructor) */
        return new self($normalised, $scope);
    }

    /**
     * @return array{scope: string, entries: array<string, array{0: int, 1: int, 2: string}>}
     */
    public function toArray(): array
    {
        return ['scope' => $this->scope, 'entries' => $this->entries];
    }

    /**
     * SHA-256 of the canonical JSON form: links an incremental (`base_manifest_sha256`) to its parent
     * (`file_manifest_sha256`).
     */
    public function sha256(): string
    {
        return hash('sha256', self::json($this->toArray()));
    }

    /**
     * @return array{0: int, 1: int, 2: string}|null
     */
    public function get(string $path): ?array
    {
        return $this->entries[$path] ?? null;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Paths of this manifest missing from $current (deleted since this manifest was taken), sorted.
     *
     * @return list<string>
     */
    public function deletedIn(self $current): array
    {
        $deleted = [];
        foreach ($this->entries as $path => $entry) {
            if (!isset($current->entries[$path])) {
                $deleted[] = (string) $path;
            }
        }

        return $deleted;
    }

    /**
     * A project-relative path that the archiver could have collected: no absolute path, no `..`, no control bytes.
     */
    public static function assertPath(string $path): void
    {
        $normalised = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($normalised, '/') || str_contains('/' . $normalised . '/', '/../')
            || str_contains('/' . $normalised . '/', '/./') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Manifest paths are project-relative paths without "..".');
        }
    }

    /**
     * @param array<mixed> $value
     */
    private static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The manifest cannot be encoded as JSON.', 0, $e);
        }
    }
}

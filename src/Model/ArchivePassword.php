<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

use function function_exists;
use function log;
use function max;
use function mb_strlen;
use function preg_match;
use function sodium_memzero;
use function str_contains;
use function strlen;

/**
 * Archive password (1.29+). The bundle never stores, generates or logs it: the host keeps it apart from the backups
 * (owner decision D-20261104-15) and passes it per call.
 *
 * - at least {@see self::MIN_LENGTH} characters (owner decision Q-O5), at most 1024, no NUL;
 * - masked in var_dump / string casts, never serialisable;
 * - {@see self::wipe()} overwrites the kept copy (best effort: PHP may hold other copies).
 */
final class ArchivePassword
{
    public const MIN_LENGTH = 16;

    public const MAX_LENGTH = 1024;

    /** Below this estimate a zip password gets a warning: AE-2 derives its key with PBKDF2-SHA1 × 1000 only. */
    public const WEAK_ZIP_BITS = 100;

    private string $value;

    private function __construct(#[SensitiveParameter] string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidArgumentException when the password is too short, too long or contains NUL (the message never
     *                                  contains it)
     */
    public static function fromString(#[SensitiveParameter] string $value): self
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length < self::MIN_LENGTH || strlen($value) > self::MAX_LENGTH || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Archive passwords need 16 to 1024 characters (no NUL).');
        }

        return new self($value);
    }

    /**
     * @internal for the encryptors only
     */
    public function reveal(): string
    {
        if ($this->value === '') {
            throw new LogicException('This archive password was wiped.');
        }

        return $this->value;
    }

    /**
     * Rough strength estimate: length × log2(character classes used). Generated base64url passwords of 24 characters
     * score 144; a dictionary phrase scores high too, so this only catches short or single-class values.
     */
    public function estimatedBits(): int
    {
        $pool = 0;
        foreach (['/[a-z]/' => 26, '/[A-Z]/' => 26, '/[0-9]/' => 10, '/[^a-zA-Z0-9]/' => 33] as $pattern => $size) {
            if (preg_match($pattern, $this->value) === 1) {
                $pool += $size;
            }
        }

        return (int) (mb_strlen($this->value, 'UTF-8') * log(max(2, $pool), 2));
    }

    public function wipe(): void
    {
        if ($this->value !== '' && function_exists('sodium_memzero')) {
            // @phpstan-ignore-next-line (sodium_memzero() nulls its argument; the property is reset right below)
            sodium_memzero($this->value);
        }
        $this->value = '';
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    public function __toString(): string
    {
        return '***';
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Archive passwords cannot be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Archive passwords cannot be unserialised.');
    }
}

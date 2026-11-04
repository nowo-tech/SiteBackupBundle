<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use WeakMap;

use function function_exists;
use function log;
use function max;
use function mb_strlen;
use function preg_match;
use function sodium_memzero;
use function strlen;

/**
 * Archive password (1.29+). The bundle never stores, generates or logs it: the host keeps it apart from the backups
 * (owner decision D-20261104-15) and passes it per call.
 *
 * - at least {@see self::MIN_LENGTH} characters (owner decision Q-O5), at most 1024, no NUL / CR / LF (age's batchpass
 *   plugin strips trailing newlines);
 * - masked in var_dump / string casts, never serialisable;
 * - {@see self::wipe()} overwrites the kept copy (best effort: PHP may hold other copies).
 */
final class ArchivePassword
{
    public const MIN_LENGTH = 16;

    public const MAX_LENGTH = 1024;

    /** Below this estimate a zip password gets a warning: AE-2 derives its key with PBKDF2-SHA1 × 1000 only. */
    public const WEAK_ZIP_BITS = 100;

    /**
     * EO-L1: the value never lives in an object property, so `var_export()`, an `(array)` cast or VarDumper cannot
     * print it. Entries disappear with their object.
     *
     * Worker-safe: a weak map keyed by the password object, so an entry lives exactly as long as its object.
     *
     * @var WeakMap<self, string>|null
     */
    private static ?WeakMap $values = null; // @phpstan-ignore-line (object-keyed weak map, see above)

    private function __construct(#[SensitiveParameter] string $value)
    {
        self::store($this, $value);
    }

    public function __clone()
    {
        // A clone gets no value (it would otherwise be an unwipeable copy).
        self::$values?->offsetUnset($this);
    }

    /**
     * VarDumper caster (registered by the bundle): `ArchivePassword { value: "***" }`.
     *
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    public static function castForVarDumper(self $password, array $a): array
    {
        return ['value' => '***'];
    }

    private static function store(self $password, #[SensitiveParameter] string $value): void
    {
        // @igor-ignore - Object-keyed weak map: each entry dies with its ArchivePassword; nothing outlives a request.
        // @phpstan-ignore-next-line (weak map, see the property)
        self::$values ??= new WeakMap();
        // @phpstan-ignore-next-line (weak map, see the property)
        self::$values[$password] = $value;
    }

    /**
     * @throws InvalidArgumentException when the password is too short, too long or contains NUL (the message never
     *                                  contains it)
     */
    public static function fromString(#[SensitiveParameter] string $value): self
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length < self::MIN_LENGTH || strlen($value) > self::MAX_LENGTH || preg_match('/[\x00\r\n]/', $value) === 1) {
            throw new InvalidArgumentException('Archive passwords need 16 to 1024 characters (no NUL, CR or LF).');
        }

        return new self($value);
    }

    /**
     * @internal for the encryptors only
     */
    public function reveal(): string
    {
        $value = self::$values[$this] ?? '';
        if ($value === '') {
            throw new LogicException('This archive password was wiped.');
        }

        return $value;
    }

    /**
     * Rough strength estimate: length × log2(character classes used). Generated base64url passwords of 24 characters
     * score 144; a dictionary phrase scores high too, so this only catches short or single-class values.
     */
    public function estimatedBits(): int
    {
        $value = self::$values[$this] ?? '';
        $pool  = 0;
        foreach (['/[a-z]/' => 26, '/[A-Z]/' => 26, '/[0-9]/' => 10, '/[^a-zA-Z0-9]/' => 33] as $pattern => $size) {
            if (preg_match($pattern, $value) === 1) {
                $pool += $size;
            }
        }

        return (int) (mb_strlen($value, 'UTF-8') * log(max(2, $pool), 2));
    }

    public function wipe(): void
    {
        $value = self::$values[$this] ?? '';
        if ($value !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        self::$values?->offsetUnset($this);
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

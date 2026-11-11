<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use SensitiveParameter;
use WeakMap;

/**
 * The first administrator the setup wizard asks {@see AdminAccountProvisionerInterface} to create (1.35+).
 *
 * The plain password never lives in an object property (like {@see ArchivePassword}): it
 * is kept in an object-keyed weak map and read with {@see plainPassword()}. `json_encode()`, `var_export()`, an
 * `(array)` cast, `var_dump()` / `print_r()` and VarDumper / the profiler (caster registered by the bundle) never show
 * it; the object cannot be serialised. Hash it and drop the object.
 */
final class AdminUserData implements JsonSerializable
{
    public const MASK = '***';

    /** @var list<string> */
    public readonly array $roles;

    /**
     * Worker-safe: keyed by the object, so an entry lives exactly as long as its AdminUserData.
     *
     * @var WeakMap<self, string>|null
     */
    private static ?WeakMap $passwords = null; // @phpstan-ignore-line (object-keyed weak map, see above)

    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly string $email,
        #[SensitiveParameter]
        string $plainPassword,
        array $roles = ['ROLE_SUPER_ADMIN'],
    ) {
        if ($email === '' || $plainPassword === '') {
            throw new InvalidArgumentException('Admin email and password must not be empty.');
        }
        $this->roles = array_values($roles);
        // @phpstan-ignore-next-line (weak map, see the property)
        self::$passwords ??= new WeakMap(); // @igor-ignore - Object-keyed weak map; entries die with their object.
        // @phpstan-ignore-next-line (weak map, see the property)
        self::$passwords[$this] = $plainPassword; // @igor-ignore - Object-keyed weak map; entries die with their object.
    }

    public function __clone()
    {
        // A clone carries no password (it would otherwise be a second copy).
        self::$passwords?->offsetUnset($this);
    }

    /**
     * The plain password, for hashing only. Never log, store or render it.
     */
    public function plainPassword(): string
    {
        return self::$passwords[$this] ?? throw new LogicException('This admin password is no longer available.');
    }

    /**
     * @return array{email: string, plainPassword: string, roles: list<string>}
     */
    public function jsonSerialize(): array
    {
        return $this->masked();
    }

    /**
     * @return array{email: string, plainPassword: string, roles: list<string>}
     */
    public function __debugInfo(): array
    {
        return $this->masked();
    }

    /**
     * VarDumper caster (registered by the bundle): the password shows as "***".
     *
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    public static function castForVarDumper(self $admin, array $a): array
    {
        return $admin->masked();
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Admin user data cannot be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Admin user data cannot be unserialised.');
    }

    /**
     * @return array{email: string, plainPassword: string, roles: list<string>}
     */
    private function masked(): array
    {
        return ['email' => $this->email, 'plainPassword' => self::MASK, 'roles' => $this->roles];
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * The first administrator the setup wizard asks {@see AdminAccountProvisionerInterface} to create (1.35+).
 *
 * The plain password is a {@see SensitiveParameter} and never shows in `var_dump()` / `print_r()` / the profiler
 * ({@see __debugInfo()}); hash it and drop it.
 */
final readonly class AdminUserData
{
    /** @var list<string> */
    public array $roles;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $plainPassword,
        array $roles = ['ROLE_SUPER_ADMIN'],
    ) {
        if ($email === '' || $plainPassword === '') {
            throw new InvalidArgumentException('Admin email and password must not be empty.');
        }
        $this->roles = array_values($roles);
    }

    /**
     * @return array{email: string, plainPassword: string, roles: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['email' => $this->email, 'plainPassword' => '***', 'roles' => $this->roles];
    }
}

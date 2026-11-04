<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Exception\SecretsInArchiveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretPathPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function forbidden(): iterable
    {
        foreach ([
            '.env', '.env.local', '.env.prod', '.env.prod.local', './.env', '/.env', 'sub/.env', 'sub/.env.dist', 'apps/api/.env.local',
            '.ENV', '.envrc', 'config/secrets/prod/prod.decrypt.private.php', 'config/secrets', 'CONFIG/Secrets/x',
            'var/secrets/halite.key', 'var/secrets/anything', 'config/jwt/private.pem', 'config\\jwt\\public.pem',
            'certs/server.key', 'a/b/c.pem', 'x.P12', 'keystore.jks', 'store.pfx', 'home/.ssh/id_rsa', 'id_ed25519.pub',
            'auth.json', 'sub/auth.json', '.npmrc', '.git-credentials', '.netrc', '.pgpass', '.my.cnf',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('forbidden')]
    public function testFloorPaths(string $path): void
    {
        self::assertTrue((new SecretPathPolicy())->isForbidden($path));
        self::assertTrue(SecretPathPolicy::matchesFloor($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed(): iterable
    {
        foreach (['.env.dist', '.env.example', 'config/packages/framework.yaml', 'src/Kernel.php', 'public/keyboard.js', 'environment.md', 'config/secretsauce.yaml', 'var/secretsX/a', 'docs/keys.md', ''] as $path) {
            yield $path === '' ? '(empty)' : $path => [$path];
        }
    }

    #[DataProvider('allowed')]
    public function testAllowedPaths(string $path): void
    {
        self::assertFalse((new SecretPathPolicy())->isForbidden($path));
    }

    public function testForbiddenPathsAreAddedToTheFloor(): void
    {
        $policy = new SecretPathPolicy(['var/storage/private', 'public/uploads/*.csv', '  ', '']);

        self::assertTrue($policy->isForbidden('var/storage/private/a.pdf'));
        self::assertTrue($policy->isForbidden('VAR/storage/private'));
        self::assertTrue($policy->isForbidden('public/uploads/export.csv'));
        self::assertTrue($policy->isForbidden('.env'), 'the floor stays');
        self::assertFalse($policy->isForbidden('public/uploads/logo.png'));
        self::assertSame(['.env', 'var/storage/private/x'], $policy->forbiddenAmong(['src/a.php', '.env', 'var/storage/private/x']));
    }

    public function testAssertNoSecretsListsThePaths(): void
    {
        $policy = new SecretPathPolicy();
        $policy->assertNoSecrets(['src/a.php', '.env.dist']);

        try {
            $policy->assertNoSecrets(['src/a.php', 'config/secrets/prod/x.php']);
            self::fail('Expected SecretsInArchiveException');
        } catch (SecretsInArchiveException $e) {
            self::assertSame(['config/secrets/prod/x.php'], $e->getPaths());
            self::assertStringContainsString('1 secret path', $e->getMessage());
        }
    }

    public function testNormalize(): void
    {
        self::assertSame('a/b/c', SecretPathPolicy::normalize(' ./a//b/./c/ '));
        self::assertSame('a/b', SecretPathPolicy::normalize('\\a\\b'));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Security;

use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Nowo\SiteBackupBundle\Security\SecurityTokenActorResolver;
use Nowo\SiteBackupBundle\Security\StaticActorResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function mb_strlen;
use function str_repeat;

final class ActorResolverTest extends TestCase
{
    public function testStaticResolverDefaultsToPanel(): void
    {
        self::assertSame('panel', (new StaticActorResolver())->resolveActor(Request::create('/')));
        self::assertSame(ActorResolverInterface::PANEL, (new StaticActorResolver())->resolveActor(Request::create('/')));
        self::assertSame('ops-panel', (new StaticActorResolver('ops-panel'))->resolveActor(Request::create('/')));
        self::assertNull((new StaticActorResolver(null))->resolveActor(Request::create('/')));
    }

    public function testTokenResolverReturnsUserIdentifier(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('alice@example.test', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));

        self::assertSame('alice@example.test', (new SecurityTokenActorResolver($storage))->resolveActor(Request::create('/')));
    }

    public function testTokenResolverFallsBackWithoutToken(): void
    {
        self::assertSame('panel', (new SecurityTokenActorResolver(new TokenStorage()))->resolveActor(Request::create('/')));
        self::assertSame('panel', (new SecurityTokenActorResolver())->resolveActor(Request::create('/')));
        self::assertSame('anon', (new SecurityTokenActorResolver(null, 'anon'))->resolveActor(Request::create('/')));
    }

    public function testTokenResolverKeepsTheIdentifierBoundedAndPrintable(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser("ali\x07ce" . str_repeat('x', 200), null), 'main'));

        $actor = (new SecurityTokenActorResolver($storage))->resolveActor(Request::create('/'));

        self::assertSame('alice' . str_repeat('x', 123), $actor);
        self::assertSame(SecurityTokenActorResolver::MAX_LENGTH, mb_strlen($actor));
    }

    public function testTokenResolverFallsBackWhenNothingPrintableIsLeft(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser("\x01\t\n", null), 'main'));

        self::assertSame('panel', (new SecurityTokenActorResolver($storage))->resolveActor(Request::create('/')));
    }

    public function testNormalizeDropsTabsNewlinesAndFormatCharacters(): void
    {
        self::assertSame('alicebob', SecurityTokenActorResolver::normalize("  ali\tce\nbob\u{202E}\u{200B} "));
        self::assertSame('ok', SecurityTokenActorResolver::normalize("o\xFFk"));
        self::assertSame('', SecurityTokenActorResolver::normalize("\r\n"));
    }
}

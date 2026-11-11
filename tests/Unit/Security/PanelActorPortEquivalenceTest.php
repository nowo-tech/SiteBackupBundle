<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Security;

use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;
use Nowo\SiteBackupBundle\Security\PanelRequestContextFactory;
use Nowo\SiteBackupBundle\Security\SecurityTokenActorResolver;
use Nowo\SiteBackupBundle\Security\StaticActorResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function sprintf;
use function str_repeat;

/**
 * The actor written to history / events / created-by: the framework-free port gives the same actor as the deprecated
 * Request port.
 */
final class PanelActorPortEquivalenceTest extends TestCase
{
    public function testTokenResolverAgreesOnEveryIdentifier(): void
    {
        foreach ([null, 'alice@example.test', "bob\nadmin", "\u{202E}eve", str_repeat('x', 200), ' carol ', "\t"] as $identifier) {
            foreach (['panel', null, 'ops'] as $fallback) {
                $tokens = new TokenStorage();
                if ($identifier !== null) {
                    $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($identifier, null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
                }
                $resolver = new SecurityTokenActorResolver($tokens, $fallback);
                $request  = Request::create('/');

                self::assertSame(
                    $resolver->resolveActor($request),
                    $resolver->resolveActor(PanelRequestContextFactory::fromRequest($request, $tokens)),
                    sprintf('%s / %s', $identifier ?? 'no token', $fallback ?? 'null'),
                );
            }
        }
    }

    public function testStaticResolverAgrees(): void
    {
        foreach (['panel', null, 'ops-panel'] as $actor) {
            $resolver = new StaticActorResolver($actor);
            self::assertSame($resolver->resolveActor(Request::create('/')), $resolver->resolveActor(new PanelRequestContext()));
        }
        self::assertSame(PanelActorResolverInterface::DEFAULT_ACTOR, (new StaticActorResolver())->resolveActor(new PanelRequestContext()));
    }

    public function testTokenResolverReadsTheContextIdentifier(): void
    {
        self::assertSame('dave', (new SecurityTokenActorResolver())->resolveActor(new PanelRequestContext(null, 'dave')));
        self::assertSame('panel', (new SecurityTokenActorResolver())->resolveActor(new PanelRequestContext(null, "\n")));
    }
}

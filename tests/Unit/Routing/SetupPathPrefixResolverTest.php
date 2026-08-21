<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Routing;

use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SetupPathPrefixResolverTest extends TestCase
{
    public function testNeverModeReturnsBarePrefix(): void
    {
        $stack    = new RequestStack([Request::create('/_setup')]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'never', 'en', ['en', 'es']);

        self::assertSame('/_setup', $resolver->resolve());
    }

    public function testAlwaysModeWithLocalizedPath(): void
    {
        $stack    = new RequestStack([Request::create('/es/_setup')]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'always', 'en', ['en', 'es']);

        self::assertSame('/es/_setup', $resolver->resolve());
    }

    public function testAlwaysModeWithoutMatchingPathUsesRequestLocale(): void
    {
        $request = Request::create('/other');
        $request->setLocale('es');
        $stack    = new RequestStack([$request]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'always', 'en', ['en', 'es']);

        self::assertSame('/es/_setup', $resolver->resolve());
    }

    public function testAlwaysModeDefaultsToDefaultLocale(): void
    {
        $stack    = new RequestStack([Request::create('/other')]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'always', 'en', ['en', 'es']);

        self::assertSame('/en/_setup', $resolver->resolve());
    }

    public function testBothModeDetectsLocalizedPath(): void
    {
        $stack    = new RequestStack([Request::create('/es/_setup')]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'both', 'en', ['en', 'es']);

        self::assertSame('/es/_setup', $resolver->resolve());
    }

    public function testBothModeDetectsBarePath(): void
    {
        $stack    = new RequestStack([Request::create('/_setup')]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'both', 'en', ['en', 'es']);

        self::assertSame('/_setup', $resolver->resolve());
    }

    public function testBothModeNonSetupPathDefaultLocaleReturnsBare(): void
    {
        $stack    = new RequestStack([Request::create('/blog')]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'both', 'en', ['en', 'es']);

        self::assertSame('/_setup', $resolver->resolve());
    }

    public function testBothModeNonSetupPathNonDefaultLocaleReturnsLocalized(): void
    {
        $request = Request::create('/blog');
        $request->setLocale('es');
        $stack    = new RequestStack([$request]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'both', 'en', ['en', 'es']);

        self::assertSame('/es/_setup', $resolver->resolve());
    }

    public function testNoRequestFallback(): void
    {
        $stack    = new RequestStack();
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'always', 'en', ['en', 'es']);

        self::assertSame('/en/_setup', $resolver->resolve());
    }

    public function testAlwaysModeWithUnknownLocaleDefaultsToDefault(): void
    {
        $request = Request::create('/other');
        $request->setLocale('fr');
        $stack    = new RequestStack([$request]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'always', 'en', ['en', 'es']);

        self::assertSame('/en/_setup', $resolver->resolve());
    }

    public function testBothModeWithUnknownLocaleReturnsBare(): void
    {
        $request = Request::create('/other');
        $request->setLocale('fr');
        $stack    = new RequestStack([$request]);
        $resolver = new SetupPathPrefixResolver($stack, '/_setup', 'both', 'en', ['en', 'es']);

        self::assertSame('/_setup', $resolver->resolve());
    }
}

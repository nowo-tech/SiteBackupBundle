<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\EventSubscriber;

use Nowo\SiteBackupBundle\EventSubscriber\ColdStartSchemaGateSubscriber;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Setup\ColdStart\ColdStartRequestAttributes;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ColdStartSchemaGateSubscriberTest extends TestCase
{
    public function testRedirectsWhenSchemaMissing(): void
    {
        $checker = new class implements SchemaExistenceCheckerInterface {
            public function schemaExists(): bool
            {
                return false;
            }
        };

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/dashboard'));
        $resolver = new SetupPathPrefixResolver($requestStack, '/_setup', 'never', 'en', ['en']);

        $subscriber = new ColdStartSchemaGateSubscriber(
            schemaChecker: $checker,
            pathPrefixResolver: $resolver,
            setupPathPrefix: '/_setup',
            safePathPrefixes: ['/health/'],
            enabledLocales: [],
            stopPropagation: true,
        );

        $request = Request::create('/dashboard');
        $event   = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequestProbe($event);
        $subscriber->onKernelRequestRedirect($event);

        self::assertFalse($request->attributes->get(ColdStartRequestAttributes::SCHEMA_EXISTS));
        self::assertNotNull($event->getResponse());
        self::assertSame('/_setup', $event->getResponse()->headers->get('Location'));
        self::assertTrue($event->isPropagationStopped());
    }

    public function testSkipsRedirectForSafePath(): void
    {
        $checker = new class implements SchemaExistenceCheckerInterface {
            public function schemaExists(): bool
            {
                return false;
            }
        };

        $subscriber = new ColdStartSchemaGateSubscriber(
            schemaChecker: $checker,
            pathPrefixResolver: new SetupPathPrefixResolver(new RequestStack(), '/_setup', 'never', 'en', ['en']),
            setupPathPrefix: '/_setup',
            safePathPrefixes: ['/health/'],
            stopPropagation: true,
        );

        $request = Request::create('/health/ping');
        $event   = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequestProbe($event);
        $subscriber->onKernelRequestRedirect($event);
        $subscriber->onKernelRequestStopLateListeners($event);

        self::assertNull($event->getResponse());
        self::assertTrue($event->isPropagationStopped());
    }
}

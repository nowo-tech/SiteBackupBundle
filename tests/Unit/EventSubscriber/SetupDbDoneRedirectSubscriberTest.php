<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\EventSubscriber;

use Nowo\SiteBackupBundle\EventSubscriber\SetupDbDoneRedirectSubscriber;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupDbDoneGuard;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use Nowo\SiteBackupBundle\Tests\Unit\Setup\FakeDurableSetupDoneStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SetupDbDoneRedirectSubscriberTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testRedirectsWhenWizardShouldClose(): void
    {
        $guard = $this->guardThatCloses();

        $subscriber = new SetupDbDoneRedirectSubscriber($guard, '/_setup', ['es'], '/admin');
        self::assertArrayHasKey(KernelEvents::REQUEST, SetupDbDoneRedirectSubscriber::getSubscribedEvents());

        $kernel = $this->createMock(HttpKernelInterface::class);
        $event  = new RequestEvent($kernel, Request::create('/_setup'), HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/admin', $event->getResponse()?->getTargetUrl());
    }

    public function testMatchesLocalizedSetupPaths(): void
    {
        $subscriber = new SetupDbDoneRedirectSubscriber($this->guardThatCloses(), '/_setup', ['es']);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $event      = new RequestEvent($kernel, Request::create('/es/_setup/api/step'), HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testSkipsWhenGuardAllowsWizardOrPathIsNotSetup(): void
    {
        $openGuard  = $this->guardThatStaysOpen();
        $subscriber = new SetupDbDoneRedirectSubscriber($openGuard);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $event      = new RequestEvent($kernel, Request::create('/_setup'), HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);
        self::assertFalse($event->hasResponse());

        $closed = new SetupDbDoneRedirectSubscriber($this->guardThatCloses());
        $event  = new RequestEvent($kernel, Request::create('/dashboard'), HttpKernelInterface::MAIN_REQUEST);
        $closed->onKernelRequest($event);
        self::assertFalse($event->hasResponse());

        $event = new RequestEvent($kernel, Request::create('/_setup/done'), HttpKernelInterface::MAIN_REQUEST);
        $closed->onKernelRequest($event);
        self::assertFalse($event->hasResponse());
    }

    private function guardThatCloses(): SetupDbDoneGuard
    {
        return new SetupDbDoneGuard(
            new FakeDurableSetupDoneStore(true),
            new SetupNeedEvaluator([], true),
            new SetupMarkerManager(
                $this->harnessProjectDir . '/var/site-backup/setup.required',
                $this->harnessProjectDir . '/var/site-backup/setup.done',
            ),
            new FilesystemSetupProgressStorage($this->harnessProjectDir . '/var/site-backup/setup-progress.json'),
        );
    }

    private function guardThatStaysOpen(): SetupDbDoneGuard
    {
        return new SetupDbDoneGuard(
            new FakeDurableSetupDoneStore(false),
            new SetupNeedEvaluator([], true),
            new SetupMarkerManager(
                $this->harnessProjectDir . '/var/site-backup/setup.required',
                $this->harnessProjectDir . '/var/site-backup/setup.done',
            ),
            new FilesystemSetupProgressStorage($this->harnessProjectDir . '/var/site-backup/setup-progress.json'),
        );
    }
}

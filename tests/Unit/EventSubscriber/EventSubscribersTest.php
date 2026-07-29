<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\EventSubscriber;

use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;
use Nowo\SiteBackupBundle\EventSubscriber\RestoreRequestSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupRequestSubscriber;
use Nowo\SiteBackupBundle\Exclusion\SiteBackupExclusionMatcher;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventSubscribersTest extends TestCase
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

    public function testSetupRequestSubscriberRedirects(): void
    {
        $setupDir  = $this->harnessProjectDir . '/_setup';
        $markers   = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $evaluator = new SetupNeedEvaluator([new MarkerFileDetector($markers, true, true)], true);
        $manager   = $this->createManager();

        $subscriber = new SetupRequestSubscriber(
            enabled: true,
            needEvaluator: $evaluator,
            backupManager: $manager,
            exclusionMatcher: new SiteBackupExclusionMatcher([], [], [], [], []),
            setupPathPrefix: '/_setup',
            panelPathPrefix: '/_site_backup',
        );

        $request = Request::create('/blog?token=abc');
        $event   = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        self::assertNotNull($event->getResponse());
        self::assertStringContainsString('/_setup', (string) $event->getResponse()->headers->get('Location'));
    }

    public function testSetupRequestSubscriberSkips(): void
    {
        $subscriber = new SetupRequestSubscriber(
            enabled: false,
            needEvaluator: new SetupNeedEvaluator([], true),
            backupManager: $this->createManager(),
            exclusionMatcher: new SiteBackupExclusionMatcher([], [], [], [], []),
        );
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testRestoreRequestSubscriberHtmlAndJson(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(
            active: true,
            phase: RestoreProgress::PHASE_APPLYING,
            percent: 50.0,
            message: 'Applying',
            backupId: 'b1',
        ));
        $manager = $this->createManager();

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html>loading</html>');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Translated');

        $subscriber = new RestoreRequestSubscriber(
            enabled: true,
            manager: $manager,
            exclusionMatcher: new SiteBackupExclusionMatcher([], [], [], [], []),
            twig: $twig,
            template: 'restore.html.twig',
            statusCode: 503,
            panelPathPrefix: '/_site_backup',
            defaultMessage: 'restore.page.message',
            translator: $translator,
        );

        $htmlEvent = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/page'),
            HttpKernelInterface::MAIN_REQUEST,
        );
        $subscriber->onKernelRequest($htmlEvent);
        self::assertSame(503, $htmlEvent->getResponse()?->getStatusCode());

        $jsonRequest = Request::create('/page', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $jsonEvent   = new RequestEvent($this->createMock(HttpKernelInterface::class), $jsonRequest, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($jsonEvent);
        self::assertStringContainsString('restoring', (string) $jsonEvent->getResponse()?->getContent());
    }

    public function testRestoreRequestSubscriberFallbackHtmlWithoutTwig(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 10.0));
        $manager = $this->createManager();

        $subscriber = new RestoreRequestSubscriber(
            enabled: true,
            manager: $manager,
            exclusionMatcher: new SiteBackupExclusionMatcher([], [], [], [], []),
            twig: null,
            template: 'restore.html.twig',
        );

        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/page'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        self::assertStringContainsString('<html>', (string) $event->getResponse()?->getContent());
    }

    public function testRestoreRequestSubscriberSkipsExcludedController(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 10.0));
        $manager = $this->createManager();

        $subscriber = new RestoreRequestSubscriber(
            enabled: true,
            manager: $manager,
            exclusionMatcher: new SiteBackupExclusionMatcher([], [], [], [], []),
            twig: null,
            template: 'restore.html.twig',
        );

        $request = Request::create('/page');
        $request->attributes->set('_controller', ExcludedController::class . '::ping');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        self::assertNull($event->getResponse());

        $request2 = Request::create('/page');
        $request2->attributes->set(ExcludeFromRestore::ROUTE_DEFAULT, true);
        $event2 = new RequestEvent($this->createMock(HttpKernelInterface::class), $request2, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event2);
        self::assertNull($event2->getResponse());
    }
}

#[ExcludeFromRestore]
final class ExcludedController
{
    public function ping(): string
    {
        return 'ok';
    }
}

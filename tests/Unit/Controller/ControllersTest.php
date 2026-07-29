<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Controller;

use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Setup\Detector\MarkerFileDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use const PASSWORD_DEFAULT;

final class ControllersTest extends TestCase
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

    public function testPanelLoginAndActions(): void
    {
        $manager = $this->createManager();
        $backup  = $manager->createBackup('panel', 'cli');
        $hash    = password_hash('secret', PASSWORD_DEFAULT);
        $gate    = new PasswordSiteBackupAccessGate($hash, true);
        $twig    = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        $controller = new SiteBackupPanelController(
            $manager,
            $gate,
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );

        $session = new Session(new MockArraySessionStorage());
        self::assertSame(200, $controller->index(Request::create('/'))->getStatusCode());

        $loginRequest = Request::create('/_site_backup', 'POST', [
            'action'      => 'login',
            'password'    => 'secret',
            '_csrf_token' => 'token',
        ]);
        $loginRequest->setSession($session);
        self::assertSame(302, $controller->index($loginRequest)->getStatusCode());

        foreach (['create', 'verify', 'restore', 'clear_restore'] as $action) {
            $request = Request::create('/_site_backup', 'POST', [
                'action'      => $action,
                'backup_id'   => $backup->getId(),
                'label'       => 'new',
                '_csrf_token' => 'token',
            ]);
            $request->setSession($session);
            self::assertSame(200, $controller->index($request)->getStatusCode());
        }

        $deleteRequest = Request::create('/_site_backup', 'POST', [
            'action'      => 'delete',
            'backup_id'   => $backup->getId(),
            '_csrf_token' => 'token',
        ]);
        $deleteRequest->setSession($session);
        self::assertSame(200, $controller->index($deleteRequest)->getStatusCode());

        $unknownRequest = Request::create('/_site_backup', 'POST', [
            'action'      => 'unknown',
            '_csrf_token' => 'token',
        ]);
        $unknownRequest->setSession($session);
        self::assertSame(200, $controller->index($unknownRequest)->getStatusCode());

        self::assertSame(200, $controller->progress()->getStatusCode());

        $historyRequest = Request::create('/history');
        $historyRequest->setSession($session);
        self::assertSame(200, $controller->history($historyRequest)->getStatusCode());

        $logoutRequest = Request::create('/_site_backup', 'POST', [
            'action'      => 'logout',
            '_csrf_token' => 'token',
        ]);
        $logoutRequest->setSession($session);
        self::assertSame(302, $controller->index($logoutRequest)->getStatusCode());
    }

    public function testPanelCsrfFailClosedWithoutManager(): void
    {
        $gate = new PasswordSiteBackupAccessGate(null, false);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            $gate,
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
        );

        $request = Request::create('/_site_backup', 'POST', ['action' => 'create']);
        self::assertSame(200, $controller->index($request)->getStatusCode());
    }

    public function testPanelMisconfiguredLogin(): void
    {
        $gate = new PasswordSiteBackupAccessGate(null, true);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            $gate,
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
        );
        self::assertSame(401, $controller->index(Request::create('/'))->getStatusCode());
    }

    public function testSetupWizardController(): void
    {
        $setupDir = $this->harnessProjectDir . '/setup';
        $markers  = new SetupMarkerManager($setupDir . '/required', $setupDir . '/done');
        $markers->markRequired('fresh_install');
        $evaluator    = new SetupNeedEvaluator([new MarkerFileDetector($markers, false, true)], true);
        $orchestrator = $this->createSetupOrchestrator([
            'fresh_install' => ['steps' => [['type' => 'marker', 'write_done' => true]]],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        $controller = new SetupWizardController(
            $orchestrator,
            $evaluator,
            $twig,
            ['setup_wizard' => 'wizard', 'setup_done' => 'done', 'setup_token' => 'token'],
            '/_setup',
            'Brand',
            'secret-token',
            $csrf,
        );

        self::assertSame(403, $controller->index(Request::create('/'))->getStatusCode());

        $request = Request::create('/?_setup=1', 'GET');
        $request->query->set('token', 'secret-token');
        self::assertSame(302, $controller->index($request)->getStatusCode());

        $markers->clearDone();
        $markers->markRequired('fresh_install');
        $orchestrator->resetProgress();
        $post = Request::create('/', 'POST', ['_csrf_token' => 'token']);
        $post->query->set('token', 'secret-token');
        self::assertSame(302, $controller->index($post)->getStatusCode());

        self::assertSame(200, $controller->done()->getStatusCode());
        self::assertSame(200, $controller->progress()->getStatusCode());

        $api = Request::create('/api/advance', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"profile":"fresh_install"}');
        $api->query->set('token', 'secret-token');
        self::assertSame(200, $controller->advanceApi($api)->getStatusCode());
    }
}

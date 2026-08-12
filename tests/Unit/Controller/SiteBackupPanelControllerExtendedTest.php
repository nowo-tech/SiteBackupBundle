<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Controller;

use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessCheckerInterface;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use const PASSWORD_DEFAULT;

final class SiteBackupPanelControllerExtendedTest extends TestCase
{
    use CreatesFormFactory;
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    private function controller(): SiteBackupPanelController
    {
        $manager = $this->createManager();
        $gate    = new PasswordSiteBackupAccessGate(password_hash('secret', PASSWORD_DEFAULT), true);
        $twig    = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        return new SiteBackupPanelController(
            $manager,
            $gate,
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );
    }

    private function authenticate(SiteBackupPanelController $controller, Session $session): void
    {
        $login = Request::create('/', 'POST', ['action' => 'login', 'password' => 'secret', '_csrf_token' => 'token']);
        $login->setSession($session);
        $controller->index($login);
    }

    public function testLoginFailureInvalidPasswordAndCsrf(): void
    {
        $controller = $this->controller();
        new Session(new MockArraySessionStorage());
        $badLogin = Request::create('/', 'POST', ['action' => 'login', 'password' => 'wrong', '_csrf_token' => 'token']);
        $badLogin->setSession(new Session(new MockArraySessionStorage()));
        self::assertSame(401, $controller->index($badLogin)->getStatusCode());

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(false);
        $controllerCsrf = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(password_hash('secret', PASSWORD_DEFAULT), true),
            $this->createFormFactory($csrf),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );
        $badCsrfLogin = Request::create('/', 'POST', ['action' => 'login', 'password' => 'secret', '_csrf_token' => 'bad']);
        $badCsrfLogin->setSession(new Session(new MockArraySessionStorage()));
        self::assertSame(401, $controllerCsrf->index($badCsrfLogin)->getStatusCode());
    }

    public function testVerifyFailureAndUnauthenticatedHistory(): void
    {
        $manager  = $this->createManager();
        $artifact = $manager->createBackup('v', 'cli');
        file_put_contents($artifact->getAbsolutePath(), 'corrupt');

        $controller = $this->controller();
        $session    = new Session(new MockArraySessionStorage());
        self::assertSame(302, $controller->history(Request::create('/history'))->getStatusCode());
        $this->authenticate($controller, $session);

        $verify = Request::create('/', 'POST', [
            'action'      => 'verify',
            'backup_id'   => $artifact->getId(),
            '_csrf_token' => 'token',
        ]);
        $verify->setSession($session);
        self::assertSame(200, $controller->index($verify)->getStatusCode());
    }

    public function testCreateBackupFailure(): void
    {
        $this->harnessFs->chmod($this->harnessStorageDir, 0444);
        $session = new Session(new MockArraySessionStorage());
        $csrf    = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );
        $request = Request::create('/', 'POST', ['action' => 'create', '_csrf_token' => 'token']);
        $request->setSession($session);
        self::assertSame(200, $controller->index($request)->getStatusCode());
        $this->harnessFs->chmod($this->harnessStorageDir, 0777);
    }

    public function testIndexReturnsForbiddenWhenRoleAccessDenied(): void
    {
        $checker = $this->createMock(SiteBackupAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(false);

        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            $checker,
            false,
        );

        self::assertSame(403, $controller->index(Request::create('/'))->getStatusCode());
        self::assertSame(403, $controller->history(Request::create('/history'))->getStatusCode());
    }

    public function testIndexReturnsForbiddenWhenAccessCheckerMissingAndUnauthenticatedDisallowed(): void
    {
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            null,
            false,
        );

        self::assertSame(403, $controller->index(Request::create('/'))->getStatusCode());
    }

    public function testIndexAllowsWhenAccessCheckerGrants(): void
    {
        $checker = $this->createMock(SiteBackupAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');

        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            $checker,
            false,
        );

        self::assertSame(200, $controller->index(Request::create('/'))->getStatusCode());
    }
}

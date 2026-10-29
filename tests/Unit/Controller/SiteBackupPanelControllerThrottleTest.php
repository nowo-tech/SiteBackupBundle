<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Controller;

use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessGateInterface;
use Nowo\SiteBackupBundle\Security\ThrottledSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use const PASSWORD_DEFAULT;

/**
 * security.login_throttle (v1.17): the panel renders a friendly 429 login page, never a 500.
 */
final class SiteBackupPanelControllerThrottleTest extends TestCase
{
    use CreatesFormFactory;
    use CreatesSiteBackupTestHarness;

    /** @var list<array<string, mixed>> */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->initHarness();
        $this->rendered = [];
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    private function controller(SiteBackupAccessGateInterface $gate, ?TranslatorInterface $translator = null): SiteBackupPanelController
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context): string {
            $this->rendered[] = $context;

            return 'html';
        });
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        return new SiteBackupPanelController(
            $this->createManager(),
            $gate,
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
            null,
            true,
            $translator,
        );
    }

    private function login(string $password): Request
    {
        $request = Request::create('/', 'POST', ['action' => 'login', 'password' => $password, '_csrf_token' => 'token'], server: ['REMOTE_ADDR' => '192.0.2.10']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function throttledGate(): ThrottledSiteBackupAccessGate
    {
        return new ThrottledSiteBackupAccessGate(
            new PasswordSiteBackupAccessGate(password_hash('secret', PASSWORD_DEFAULT)),
            new RateLimiterFactory(['id' => 'panel', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '15 minutes'], new InMemoryStorage()),
        );
    }

    public function testThrottledLoginRendersTranslated429WithRetryAfter(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')
            ->willReturnCallback(static function (string $id, array $parameters, ?string $domain): string {
                self::assertSame('NowoSiteBackupBundle', $domain);
                if ($id === 'panel.login.invalid_password') {
                    return 'translated-invalid';
                }
                self::assertSame('panel.login.too_many_attempts', $id);
                self::assertGreaterThanOrEqual(1, (int) $parameters['%minutes%']);
                // 15-minute window; symfony/rate-limiter 8.1.10+ rounds the retry time up (901 s → 16 min).
                self::assertLessThanOrEqual(16, (int) $parameters['%minutes%']);

                return 'translated-too-many';
            });

        $controller = $this->controller($this->throttledGate(), $translator);

        self::assertSame(401, $controller->index($this->login('wrong'))->getStatusCode());

        $response = $controller->index($this->login('secret'));
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('html', $response->getContent());
        $retryAfter = (int) $response->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(901, $retryAfter);

        self::assertSame('translated-invalid', $this->rendered[0]['page']->error);
        $context = $this->rendered[1];
        self::assertSame('translated-too-many', $context['page']->error);
        self::assertTrue($context['throttled']);
        self::assertFalse($this->rendered[0]['throttled']);
    }

    public function testThrottledLoginWithoutTranslatorFallsBackToEnglish(): void
    {
        $gate = $this->createMock(SiteBackupAccessGateInterface::class);
        $gate->method('isAuthenticated')->willReturn(false);
        $gate->method('isProtectionEnabled')->willReturn(true);
        $gate->method('authenticate')->willThrowException(new TooManyRequestsHttpException(61));

        $response = $this->controller($gate)->index($this->login('secret'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('61', $response->headers->get('Retry-After'));
        self::assertSame('Too many login attempts. Try again in 2 minute(s).', $this->rendered[0]['page']->error);
    }

    public function testThrottleExceptionWithoutRetryAfterDefaultsToSixtySeconds(): void
    {
        $gate = $this->createMock(SiteBackupAccessGateInterface::class);
        $gate->method('isAuthenticated')->willReturn(false);
        $gate->method('isProtectionEnabled')->willReturn(true);
        $gate->method('authenticate')->willThrowException(new TooManyRequestsHttpException());

        $response = $this->controller($gate)->index($this->login('secret'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertSame('Too many login attempts. Try again in 1 minute(s).', $this->rendered[0]['page']->error);
    }

    public function testSuccessfulLoginRedirectsThroughThrottledGate(): void
    {
        $response = $this->controller($this->throttledGate())->index($this->login('secret'));

        self::assertSame(302, $response->getStatusCode());
    }

    public function testWithoutCsrfManagerLoginAndActionsAreRejected(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        $build = fn (SiteBackupAccessGateInterface $gate, ?CsrfTokenManagerInterface $manager): SiteBackupPanelController => new SiteBackupPanelController(
            $this->createManager(),
            $gate,
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $manager,
        );

        $protected = new PasswordSiteBackupAccessGate(password_hash('secret', PASSWORD_DEFAULT));
        self::assertSame(401, $build($protected, null)->index($this->login('secret'))->getStatusCode());

        $open = new PasswordSiteBackupAccessGate(null, false);
        self::assertSame(200, $build($open, null)->index($this->login('secret'))->getStatusCode());
        // Authenticated panel POST with action=login builds the login form and is a no-op.
        self::assertSame(200, $build($open, $csrf)->index($this->login('secret'))->getStatusCode());
    }
}

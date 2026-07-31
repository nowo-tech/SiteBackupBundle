<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Controller;

use Nowo\SiteBackupBundle\Controller\SetupUnlocalizedLocaleRedirectController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SetupUnlocalizedLocaleRedirectControllerTest extends TestCase
{
    public function testRedirectToCanonicalRoute(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('nowo_site_backup_setup', ['_locale' => 'en'])
            ->willReturn('/en/_setup');

        $controller = new SetupUnlocalizedLocaleRedirectController($urlGenerator);

        $request = Request::create('/_setup');
        $request->attributes->set('_site_backup_canonical_route', 'nowo_site_backup_setup');
        $request->attributes->set('_route_params', [
            '_site_backup_canonical_route' => 'nowo_site_backup_setup',
            '_locale'                      => 'en',
        ]);

        $response = $controller->redirect($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/en/_setup', $response->headers->get('Location'));
    }

    public function testRedirectPreservesQueryString(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/en/_setup');

        $controller = new SetupUnlocalizedLocaleRedirectController($urlGenerator);

        $request = Request::create('/_setup?token=abc&foo=bar');
        $request->attributes->set('_site_backup_canonical_route', 'nowo_site_backup_setup');
        $request->attributes->set('_route_params', []);

        $response = $controller->redirect($request);
        self::assertStringContainsString('token=abc', (string) $response->headers->get('Location'));
        self::assertStringContainsString('foo=bar', (string) $response->headers->get('Location'));
    }

    public function testThrowsWhenNoCanonicalRoute(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $controller   = new SetupUnlocalizedLocaleRedirectController($urlGenerator);

        $request = Request::create('/_setup');
        $this->expectException(NotFoundHttpException::class);
        $controller->redirect($request);
    }

    public function testThrowsWhenCanonicalRouteIsEmpty(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $controller   = new SetupUnlocalizedLocaleRedirectController($urlGenerator);

        $request = Request::create('/_setup');
        $request->attributes->set('_site_backup_canonical_route', '');
        $this->expectException(NotFoundHttpException::class);
        $controller->redirect($request);
    }

    public function testHandlesNullRouteParams(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('nowo_site_backup_setup_done', ['_locale' => 'en'])
            ->willReturn('/en/_setup/done');

        $controller = new SetupUnlocalizedLocaleRedirectController($urlGenerator);

        $request = Request::create('/_setup/done');
        $request->attributes->set('_site_backup_canonical_route', 'nowo_site_backup_setup_done');
        $request->attributes->set('_route_params', null);

        $response = $controller->redirect($request);
        self::assertSame('/en/_setup/done', $response->headers->get('Location'));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_array;
use function is_string;

/**
 * Redirects bare setup URLs to the canonical localized route when locale.unlocalized=redirect.
 */
final class SetupUnlocalizedLocaleRedirectController
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function redirect(Request $request): RedirectResponse
    {
        $canonicalRoute = $request->attributes->get('_site_backup_canonical_route');
        if (!is_string($canonicalRoute) || $canonicalRoute === '') {
            throw new NotFoundHttpException('Missing canonical setup route for unlocalized redirect.');
        }

        $parameters = $request->attributes->get('_route_params', []);
        if (!is_array($parameters)) {
            $parameters = [];
        }

        unset($parameters['_site_backup_canonical_route'], $parameters['_locale'], $parameters['_controller']);

        $locale = $request->attributes->get('_locale');
        if (!is_string($locale) || $locale === '') {
            $locale = $request->getLocale();
        }

        $url   = $this->urlGenerator->generate($canonicalRoute, ['_locale' => $locale] + $parameters);
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $url .= '?' . $query;
        }

        return new RedirectResponse($url);
    }
}

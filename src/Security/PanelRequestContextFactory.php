<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Adapter: builds the framework-free {@see PanelRequestContext} from a Symfony `Request` (and the token storage for
 * the user identifier).
 */
final class PanelRequestContextFactory
{
    public static function fromRequest(Request $request, ?TokenStorageInterface $tokenStorage = null): PanelRequestContext
    {
        return new PanelRequestContext(
            clientIp: $request->getClientIp(),
            userIdentifier: $tokenStorage?->getToken()?->getUserIdentifier(),
            session: $request->hasSession() ? new HttpFoundationPanelSession($request->getSession()) : null,
        );
    }
}

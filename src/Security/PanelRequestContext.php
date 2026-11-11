<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

/**
 * Framework-free context of a panel request, passed to {@see PanelAccessGateInterface} and
 * {@see PanelActorResolverInterface} instead of a Symfony `Request`.
 *
 * Built by the bundle from the current request ({@see PanelRequestContextFactory}): the client IP after the
 * trusted-proxy rules, the signed-in user identifier (null without SecurityBundle or for an anonymous visitor) and the
 * visitor's session (null when the request has none: a gate must then deny).
 */
final readonly class PanelRequestContext
{
    public function __construct(
        public ?string $clientIp = null,
        public ?string $userIdentifier = null,
        public ?PanelSessionInterface $session = null,
    ) {
    }
}

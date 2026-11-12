<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host actor resolver implementing the deprecated and the 1.35 port.
 */
final readonly class DualHostActorResolver implements ActorResolverInterface, PanelActorResolverInterface
{
    public function resolveActor(Request|PanelRequestContext $request): string
    {
        return 'dual';
    }
}

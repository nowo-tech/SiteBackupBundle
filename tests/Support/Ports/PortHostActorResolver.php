<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;

/**
 * DDD host actor resolver: "ops:<identifier>" or "ops:anonymous". No Symfony import.
 */
final readonly class PortHostActorResolver implements PanelActorResolverInterface
{
    public function resolveActor(PanelRequestContext $context): string
    {
        return 'ops:' . ($context->userIdentifier ?? 'anonymous');
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A host actor resolver on the deprecated port only.
 */
final class LegacyActorResolverFixture implements ActorResolverInterface
{
    public function resolveActor(Request $request): string
    {
        return 'legacy';
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Psr\Container\ContainerInterface;
use Throwable;

use function is_string;

/**
 * Resolves YAML `checker:` values to {@see SetupTabCheckerInterface} services.
 */
final class SetupTabCheckerLocator
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function get(?string $id): ?SetupTabCheckerInterface
    {
        if (!is_string($id) || $id === '') {
            return null;
        }

        try {
            if (!$this->container->has($id)) {
                return null;
            }
            $service = $this->container->get($id);

            return $service instanceof SetupTabCheckerInterface ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function get_debug_type;
use function is_string;

/**
 * Resolves YAML `checker:` values to {@see SetupTabCheckerInterface} services.
 *
 * REQ-DI-001 documented registry: `$container` is a ServiceLocator holding a **closed set** of ids — exactly the
 * `checker:` values declared under `setup.profiles.*.tabs` (built by SiteBackupExtension through
 * ServiceLocatorTagPass). It is not a grab-bag container. A missing id, a service that fails to build or one that
 * does not implement {@see SetupTabCheckerInterface} yields null (the tab then has no checker) and is logged as a
 * warning so the misconfiguration is visible.
 */
final class SetupTabCheckerLocator
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function get(?string $id): ?SetupTabCheckerInterface
    {
        if (!is_string($id) || $id === '') {
            return null;
        }

        try {
            if (!$this->container->has($id)) {
                $this->logger->warning('SiteBackup: setup tab checker is not registered.', ['bundle' => 'site_backup', 'checker' => $id]);

                return null;
            }
            $service = $this->container->get($id);
        } catch (Throwable $e) {
            $this->logger->warning('SiteBackup: setup tab checker could not be built.', ['bundle' => 'site_backup', 'checker' => $id, 'exception' => $e::class]);

            return null;
        }

        if (!$service instanceof SetupTabCheckerInterface) {
            $this->logger->warning('SiteBackup: setup tab checker does not implement SetupTabCheckerInterface.', ['bundle' => 'site_backup', 'checker' => $id, 'type' => get_debug_type($service)]);

            return null;
        }

        return $service;
    }
}

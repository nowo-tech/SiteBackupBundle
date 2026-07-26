<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Throwable;

/**
 * Optional: when a DBAL Connection is available, failed connect ⇒ setup required.
 */
final class DoctrineConnectDetector implements SetupNeedDetectorInterface
{
    public function __construct(
        private readonly mixed $connection = null,
        private readonly bool $enabled = true,
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->enabled || !\is_object($this->connection)) {
            return false;
        }

        $connection = $this->connection;

        try {
            if (\method_exists($connection, 'executeQuery')) {
                $connection->executeQuery('SELECT 1');

                return false;
            }
            if (\method_exists($connection, 'connect')) {
                $connection->connect();

                return false;
            }
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    public function getReason(): string
    {
        return $this->isSetupRequired() ? 'database connection failed' : 'ok';
    }
}

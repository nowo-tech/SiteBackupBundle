<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Throwable;

use function is_array;
use function is_object;

/**
 * Optional: connected DB with zero tables ⇒ setup required.
 */
final class DoctrineSchemaEmptyDetector implements SetupNeedDetectorInterface
{
    public function __construct(
        private readonly mixed $connection = null,
        private readonly bool $enabled = true,
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->enabled || !is_object($this->connection)) {
            return false;
        }

        $connection = $this->connection;

        try {
            $tables = [];
            if (method_exists($connection, 'createSchemaManager')) {
                /** @var mixed $manager */
                $manager = $connection->createSchemaManager();
                if (is_object($manager) && method_exists($manager, 'listTableNames')) {
                    /** @var mixed $listed */
                    $listed = $manager->listTableNames();
                    $tables = is_array($listed) ? $listed : [];
                }
            } elseif (method_exists($connection, 'getSchemaManager')) {
                /** @var mixed $manager */
                $manager = $connection->getSchemaManager();
                if (is_object($manager) && method_exists($manager, 'listTableNames')) {
                    /** @var mixed $listed */
                    $listed = $manager->listTableNames();
                    $tables = is_array($listed) ? $listed : [];
                }
            } else {
                return false;
            }

            return $tables === [];
        } catch (Throwable) {
            return false;
        }
    }

    public function getReason(): string
    {
        return $this->isSetupRequired() ? 'database schema is empty' : 'ok';
    }
}

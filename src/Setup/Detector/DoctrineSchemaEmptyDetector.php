<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\Memo\WorkerTtlMemo;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Throwable;

use function is_array;
use function is_object;

/**
 * Optional: connected DB with zero tables ⇒ setup required.
 *
 * Listing tables hits information_schema; when a {@see WorkerTtlMemo} is wired
 * (`setup.worker_memo.schema_probe_ttl` > 0) a non-empty schema is remembered per worker
 * for that TTL. Empty / unknown answers are never cached.
 */
final class DoctrineSchemaEmptyDetector implements SetupNeedDetectorInterface
{
    public function __construct(
        private readonly mixed $connection = null,
        private readonly bool $enabled = true,
        private readonly ?WorkerTtlMemo $healthyMemo = null,
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->enabled || !is_object($this->connection)) {
            return false;
        }

        if ($this->healthyMemo?->isFresh() ?? false) {
            return false;
        }

        $tables = $this->listTables($this->connection);
        if ($tables === null) {
            // Unknown (no schema manager / probe failed): never cached.
            $this->healthyMemo?->forget();

            return false;
        }

        if ($tables === []) {
            $this->healthyMemo?->forget();

            return true;
        }

        $this->healthyMemo?->mark();

        return false;
    }

    public function getReason(): string
    {
        return $this->isSetupRequired() ? 'database schema is empty' : 'ok';
    }

    /**
     * @return array<mixed>|null null when the table list could not be read
     */
    private function listTables(object $connection): ?array
    {
        try {
            if (method_exists($connection, 'createSchemaManager')) {
                /** @var mixed $manager */
                $manager = $connection->createSchemaManager();
            } elseif (method_exists($connection, 'getSchemaManager')) {
                /** @var mixed $manager */
                $manager = $connection->getSchemaManager();
            } else {
                return null;
            }

            if (!is_object($manager) || !method_exists($manager, 'listTableNames')) {
                return [];
            }

            /** @var mixed $listed */
            $listed = $manager->listTableNames();

            return is_array($listed) ? $listed : [];
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\Memo\WorkerTtlMemo;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Throwable;

use function is_object;

/**
 * Optional: when a DBAL Connection is available, failed connect ⇒ setup required.
 */
final class DoctrineConnectDetector implements SetupNeedDetectorInterface
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

        // A healthy answer is reused per worker for setup.worker_memo.schema_probe_ttl seconds.
        if ($this->healthyMemo?->isFresh() ?? false) {
            return false;
        }

        $required = $this->probe($this->connection);
        if ($required) {
            $this->healthyMemo?->forget();
        } else {
            $this->healthyMemo?->mark();
        }

        return $required;
    }

    private function probe(object $connection): bool
    {

        try {
            if (method_exists($connection, 'executeQuery')) {
                $connection->executeQuery('SELECT 1');

                return false;
            }
            if (method_exists($connection, 'connect')) {
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

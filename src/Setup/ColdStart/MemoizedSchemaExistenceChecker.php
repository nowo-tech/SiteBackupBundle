<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\ColdStart;

use Nowo\SiteBackupBundle\EventSubscriber\ColdStartSchemaGateSubscriber;
use Nowo\SiteBackupBundle\Setup\Memo\WorkerTtlMemo;

/**
 * Caches a positive schema probe per worker for `setup.worker_memo.schema_probe_ttl` seconds.
 *
 * {@see ColdStartSchemaGateSubscriber} asks on every main
 * request whether the application schema exists (`SELECT 1` + `information_schema`). Once the
 * schema is there it does not disappear between requests, so a `true` answer is reused for the
 * TTL. `false` (empty database, setup wizard running) is never cached, so the gate reacts as soon
 * as migrations create the schema.
 */
final class MemoizedSchemaExistenceChecker implements SchemaExistenceCheckerInterface
{
    public function __construct(
        private readonly SchemaExistenceCheckerInterface $inner,
        private readonly WorkerTtlMemo $memo,
    ) {
    }

    public function schemaExists(): bool
    {
        if ($this->memo->isFresh()) {
            return true;
        }

        if (!$this->inner->schemaExists()) {
            $this->memo->forget();

            return false;
        }

        $this->memo->mark();

        return true;
    }
}

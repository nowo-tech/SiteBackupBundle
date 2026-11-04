<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Database\SchemaInspectorInterface;
use Nowo\SiteBackupBundle\Database\SchemaSnapshot;

final class FixedSchemaInspector implements SchemaInspectorInterface
{
    public int $calls = 0;

    public function __construct(private readonly SchemaSnapshot $snapshot)
    {
    }

    public function inspect(): SchemaSnapshot
    {
        ++$this->calls;

        return $this->snapshot;
    }
}

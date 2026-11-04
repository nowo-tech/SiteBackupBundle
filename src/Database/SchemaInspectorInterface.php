<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

/**
 * Reads the table / view / foreign-key graph of the dumped database (1.28+).
 *
 * The default implementation queries `information_schema` through the `mysql` client
 * ({@see MysqlClientSchemaInspector}); a host may alias its own (e.g. DBAL-based) service.
 */
interface SchemaInspectorInterface
{
    public function inspect(): SchemaSnapshot;
}

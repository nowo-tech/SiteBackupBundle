<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * Binary log tooling failed (1.36+): the server or a client tool (`mysql`, `mysqlbinlog`) is missing, refused a
 * statement, timed out, or printed something unexpected. Messages are redacted and never quote argv.
 */
final class BinlogUnavailableException extends RuntimeException implements SiteBackupException
{
}

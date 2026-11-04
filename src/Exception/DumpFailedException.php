<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * The database dump (or import) subprocess failed or timed out. The message is redacted and cut to 500 chars.
 */
final class DumpFailedException extends RuntimeException implements SiteBackupException
{
}

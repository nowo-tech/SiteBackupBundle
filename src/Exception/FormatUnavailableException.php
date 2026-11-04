<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * The requested archive format needs a PHP extension or library that is not available (e.g. ext-zip).
 */
final class FormatUnavailableException extends RuntimeException implements SiteBackupException
{
}

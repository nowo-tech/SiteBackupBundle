<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * An encrypted archive could not be opened: missing or wrong password (1.29+). The message never holds the password.
 */
final class WrongPasswordException extends RuntimeException implements SiteBackupException
{
}

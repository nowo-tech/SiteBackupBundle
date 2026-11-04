<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function defined;
use function fgets;
use function getenv;
use function is_resource;
use function is_string;
use function preg_match;
use function rtrim;

use const STDIN;

/**
 * Archive password input for console commands (1.29+): `--password-env=NAME` or `--password-stdin`. There is no
 * `--password=<value>` option on purpose (argv is visible in `ps` and shell history).
 *
 * @internal
 */
final class PasswordInput
{
    public static function configure(Command $command): void
    {
        $command
            ->addOption('password-env', null, InputOption::VALUE_REQUIRED, 'Read the archive password from this environment variable')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the archive password from the first line of stdin');
    }

    /**
     * @param resource|null $stdin
     *
     * @throws InvalidArgumentException when the source is empty or the password is invalid (message without it)
     */
    public static function read(InputInterface $input, $stdin = null): ?ArchivePassword
    {
        $env = $input->getOption('password-env');
        if (is_string($env) && $env !== '') {
            if (preg_match('/^[A-Z_][A-Z0-9_]{0,127}$/', $env) !== 1) {
                throw new InvalidArgumentException('--password-env must name an environment variable (A-Z, 0-9, _).');
            }
            $value = getenv($env);
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('The password environment variable is empty or not set.');
            }

            return ArchivePassword::fromString($value);
        }

        if ((bool) $input->getOption('password-stdin')) {
            $stdin ??= defined('STDIN') ? STDIN : null;
            $line = is_resource($stdin) ? fgets($stdin) : false;
            if (!is_string($line) || rtrim($line, "\r\n") === '') {
                throw new InvalidArgumentException('No password on stdin.');
            }

            return ArchivePassword::fromString(rtrim($line, "\r\n"));
        }

        return null;
    }
}

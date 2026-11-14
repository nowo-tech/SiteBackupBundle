<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;

use function in_array;

/**
 * One result of {@see BinlogPrerequisitesInterface::check()} (1.36+): a named prerequisite, its status and a short
 * human explanation (never a credential).
 */
final readonly class Check
{
    public const OK      = 'ok';
    public const WARNING = 'warning';
    public const FAIL    = 'fail';

    public function __construct(
        public string $name,
        public string $status,
        public string $detail,
    ) {
        if (!in_array($status, [self::OK, self::WARNING, self::FAIL], true)) {
            throw new InvalidArgumentException('A check status is ok, warning or fail.');
        }
    }

    public static function ok(string $name, string $detail): self
    {
        return new self($name, self::OK, $detail);
    }

    public static function warning(string $name, string $detail): self
    {
        return new self($name, self::WARNING, $detail);
    }

    public static function fail(string $name, string $detail): self
    {
        return new self($name, self::FAIL, $detail);
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAIL;
    }

    /**
     * @param list<self> $checks
     */
    public static function allPass(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->isFailure()) {
                return false;
            }
        }

        return true;
    }
}

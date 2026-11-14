<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

use function array_slice;
use function implode;
use function sprintf;

/**
 * Binary log events that cannot be attributed to the backed-up schema with certainty (1.36+): the capture or the
 * restore stops, nothing is archived or applied. The problems name event positions and reasons, never SQL text.
 */
final class BinlogFilterException extends RuntimeException implements SiteBackupException
{
    /** Machine-readable reason (same place as {@see BinlogGapException::getReason()}): a new full backup is needed. */
    public const REASON = 'filter_mismatch';

    /**
     * @param list<string> $problems
     */
    public function __construct(private readonly array $problems, int $total)
    {
        parent::__construct(sprintf(
            '%d binary log event(s) cannot be attributed to the backed-up schema; nothing was archived or applied: %s%s',
            $total,
            implode('; ', array_slice($problems, 0, 10)),
            $total > 10 ? sprintf(' (+%d more)', $total - 10) : '',
        ));
    }

    public function getReason(): string
    {
        return self::REASON;
    }

    /**
     * @return list<string>
     */
    public function getProblems(): array
    {
        return $this->problems;
    }
}

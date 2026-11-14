<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

use function array_slice;
use function count;
use function implode;
use function sprintf;

/**
 * A backup chain failed its checks (1.36+): missing parent, gap, other server, other chain, broken hashes. Thrown before
 * anything is extracted or applied; nothing was changed.
 */
final class ChainBrokenException extends RuntimeException implements SiteBackupException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(private readonly array $problems)
    {
        $shown = array_slice($problems, 0, 10);
        parent::__construct(sprintf(
            'The backup chain is not restorable; nothing was applied: %s%s',
            implode(' ', $shown),
            count($problems) > 10 ? sprintf(' (+%d more)', count($problems) - 10) : '',
        ));
    }

    /**
     * @return list<string>
     */
    public function getProblems(): array
    {
        return $this->problems;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;

use function array_map;
use function count;

/**
 * A full backup and its incrementals, oldest first (1.36+). Building one checks nothing: call
 * {@see ChainVerifier::verify()} (a chain restore and a chain import always do).
 */
final readonly class BackupChain
{
    /** @var list<ChainLink> */
    public array $links;

    /**
     * @param list<BackupArtifact> $artifacts root (full) first, head last
     */
    public function __construct(public array $artifacts)
    {
        if ($artifacts === []) {
            throw new InvalidArgumentException('A chain holds at least its full backup.');
        }
        $this->links = array_map(ChainLink::fromArtifact(...), $artifacts);
    }

    public function root(): BackupArtifact
    {
        return $this->artifacts[0];
    }

    public function head(): BackupArtifact
    {
        return $this->artifacts[count($this->artifacts) - 1];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (BackupArtifact $a): string => $a->getId(), $this->artifacts);
    }
}

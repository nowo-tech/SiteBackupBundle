<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use Nowo\SiteBackupBundle\Incremental\ChainRestoreReport;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreStep;
use RuntimeException;
use Throwable;

use function count;
use function implode;
use function sprintf;

/**
 * A chain restore stopped after it started changing the live site (1.36+). {@see self::getReport()} lists every step:
 * applied, failed (possibly partly applied) and not applied. The live site is NOT in a consistent state: restore the
 * chain again (or another backup) before serving it.
 */
final class ChainRestoreException extends RuntimeException implements SiteBackupException
{
    public function __construct(private readonly ChainRestoreReport $report, ?Throwable $previous = null)
    {
        $describe = static function (array $steps): string {
            $parts = [];
            foreach ($steps as $step) {
                $parts[] = $step->backupId . ' ' . $step->part;
            }

            return $parts === [] ? 'none' : implode(', ', $parts);
        };

        parent::__construct(sprintf(
            'The chain restore stopped: %s. Applied: %s. Failed (may be partly applied): %s. Not applied: %s (%d step(s)).',
            (string) $report->error,
            $describe($report->stepsWith(ChainRestoreStep::APPLIED)),
            $describe($report->stepsWith(ChainRestoreStep::FAILED)),
            $describe($report->stepsWith(ChainRestoreStep::NOT_APPLIED)),
            count($report->stepsWith(ChainRestoreStep::NOT_APPLIED)),
        ), 0, $previous);
    }

    public function getReport(): ChainRestoreReport
    {
        return $this->report;
    }
}

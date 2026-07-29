<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Model;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use PHPUnit\Framework\TestCase;

final class SetupProgressTest extends TestCase
{
    public function testRoundTripAndWith(): void
    {
        $progress = new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            profile: 'fresh_install',
            currentStepId: 'step1',
            percent: 50.0,
            message: 'Running',
            error: 'err',
            completedStepIds: ['a'],
            log: ['line'],
            answers: ['email' => 'a@b.c'],
            updatedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $cleared = $progress->with(clearCurrentStepId: true, clearMessage: true, clearError: true);
        self::assertNull($cleared->getCurrentStepId());
        self::assertNull($cleared->getMessage());
        self::assertNull($cleared->getError());

        $again = SetupProgress::fromArray($progress->toArray());
        self::assertSame(SetupProgress::PHASE_RUNNING, $again->getPhase());
        self::assertSame(['a'], $again->getCompletedStepIds());
        self::assertSame(['line'], $again->getLog());
    }

    public function testIsFinishedOnlyWhenCompleted(): void
    {
        self::assertFalse((new SetupProgress(SetupProgress::PHASE_RUNNING))->isFinished());
        self::assertTrue((new SetupProgress(SetupProgress::PHASE_COMPLETED))->isFinished());
    }

    public function testFromArrayFiltersNonStrings(): void
    {
        $progress = SetupProgress::fromArray([
            'completed_step_ids' => ['ok', 1],
            'log'                => ['line', false],
            'answers'            => ['x' => 1],
            'percent'            => 3,
        ]);

        self::assertSame(['ok'], $progress->getCompletedStepIds());
        self::assertSame(['line'], $progress->getLog());
        self::assertSame(3.0, $progress->getPercent());
    }
}

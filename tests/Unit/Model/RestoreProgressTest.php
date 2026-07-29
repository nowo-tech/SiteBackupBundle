<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use PHPUnit\Framework\TestCase;

final class RestoreProgressTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $progress = new RestoreProgress(
            active: true,
            phase: RestoreProgress::PHASE_APPLYING,
            percent: 42.5,
            message: 'Applying',
            backupId: 'abc',
            log: ['a'],
        );

        $again = RestoreProgress::fromArray($progress->toArray());
        self::assertTrue($again->isActive());
        self::assertSame(RestoreProgress::PHASE_APPLYING, $again->getPhase());
        self::assertSame(42.5, $again->getPercent());
        self::assertSame('abc', $again->getBackupId());
        self::assertSame(['a'], $again->getLog());
    }

    public function testWithClears(): void
    {
        $progress = (new RestoreProgress(message: 'x', backupId: 'b', error: 'e'))
            ->with(clearMessage: true, clearBackupId: true, clearError: true);

        self::assertNull($progress->getMessage());
        self::assertNull($progress->getError());
        self::assertNull($progress->getBackupId());
    }

    public function testPercentValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RestoreProgress(percent: 101.0);
    }

    public function testFromArrayDefaults(): void
    {
        $progress = RestoreProgress::fromArray([
            'log'        => ['ok', 1],
            'percent'    => 'bad',
            'started_at' => 'invalid',
        ]);

        self::assertSame(['ok'], $progress->getLog());
        self::assertSame(0.0, $progress->getPercent());
        self::assertNull($progress->getStartedAt());
    }
}

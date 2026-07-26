<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Model;

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
        $progress = (new RestoreProgress(message: 'x', error: 'e', backupId: 'b'))
            ->with(clearMessage: true, clearError: true, clearBackupId: true);

        self::assertNull($progress->getMessage());
        self::assertNull($progress->getError());
        self::assertNull($progress->getBackupId());
    }
}

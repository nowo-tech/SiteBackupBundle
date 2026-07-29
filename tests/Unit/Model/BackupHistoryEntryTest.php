<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Model;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use PHPUnit\Framework\TestCase;

final class BackupHistoryEntryTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $entry = new BackupHistoryEntry(
            action: 'create',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            actor: 'panel',
            backupId: 'b1',
            message: 'ok',
            context: ['size_bytes' => 1],
        );

        $again = BackupHistoryEntry::fromArray($entry->toArray());
        self::assertSame('create', $again->getAction());
        self::assertSame('panel', $again->getActor());
        self::assertSame('b1', $again->getBackupId());
        self::assertSame(['size_bytes' => 1], $again->getContext());
    }

    public function testFromArrayDefaults(): void
    {
        $entry = BackupHistoryEntry::fromArray(['occurred_at' => 'bad']);
        self::assertSame('unknown', $entry->getAction());
        self::assertSame([], $entry->getContext());
        self::assertNull($entry->getActor());
    }
}

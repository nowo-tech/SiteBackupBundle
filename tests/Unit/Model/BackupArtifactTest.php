<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use PHPUnit\Framework\TestCase;

final class BackupArtifactTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $artifact = new BackupArtifact(
            id: 'abc',
            filename: 'abc.tar.gz',
            absolutePath: '/tmp/abc.tar.gz',
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            sizeBytes: 100,
            archiveSha256: str_repeat('a', 64),
            checksums: ['x' => 'y'],
            meta: ['k' => 'v'],
            label: 'lbl',
            createdBy: 'cli',
        );

        $again = BackupArtifact::fromArray($artifact->toArray());
        self::assertSame('abc', $again->getId());
        self::assertSame('lbl', $again->getLabel());
        self::assertSame('cli', $again->getCreatedBy());
        self::assertSame(['x' => 'y'], $again->getChecksums());
    }

    public function testConstructorValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupArtifact('', 'f', '/p', new DateTimeImmutable(), 0, 'sha');
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupArtifact('id', 'f', '/p', new DateTimeImmutable(), -1, 'sha');
    }

    public function testFromArrayDefaultsAndFiltering(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BackupArtifact::fromArray([
            'checksums'  => ['ok' => 'hash', 1 => 'skip'],
            'meta'       => 'not-array',
            'size_bytes' => '42',
        ]);
    }
}

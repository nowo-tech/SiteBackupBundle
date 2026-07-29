<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use const DATE_ATOM;

final class SetupStorageTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-setup-store-' . uniqid('', true);
        $this->fs->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testSetupProgressStorage(): void
    {
        $file    = $this->dir . '/progress.json';
        $storage = new FilesystemSetupProgressStorage($file);

        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());

        $progress = new SetupProgress(phase: SetupProgress::PHASE_RUNNING, percent: 10.0);
        $storage->save($progress);
        self::assertSame(SetupProgress::PHASE_RUNNING, $storage->load()->getPhase());

        file_put_contents($file, '{bad');
        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());
    }

    public function testSetupProgressStartedAndCompletedAtRoundTrip(): void
    {
        $file    = $this->dir . '/progress-times.json';
        $storage = new FilesystemSetupProgressStorage($file);
        $started = new DateTimeImmutable('2026-07-29T12:00:00+00:00');
        $done    = new DateTimeImmutable('2026-07-29T12:30:00+00:00');

        $storage->save(new SetupProgress(
            phase: SetupProgress::PHASE_COMPLETED,
            updatedAt: $done,
            startedAt: $started,
            completedAt: $done,
        ));

        $loaded = $storage->load();
        self::assertTrue($loaded->isFinished());
        self::assertFalse($loaded->isIncomplete());
        self::assertSame($started->format(DATE_ATOM), $loaded->getStartedAt()?->format(DATE_ATOM));
        self::assertSame($done->format(DATE_ATOM), $loaded->getCompletedAt()?->format(DATE_ATOM));
    }

    public function testMarkerManager(): void
    {
        $required = $this->dir . '/_setup.required';
        $done     = $this->dir . '/_setup.done';
        $markers  = new SetupMarkerManager($required, $done);

        self::assertFalse($markers->isRequiredMarked());
        self::assertFalse($markers->isDone());
        self::assertSame($required, $markers->getRequiredFile());
        self::assertSame($done, $markers->getDoneFile());

        $markers->markDone();
        self::assertTrue($markers->isDone());
        self::assertFalse($markers->isRequiredMarked());

        $markers->markRequired('post_restore');
        self::assertTrue($markers->isRequiredMarked());
        self::assertFalse($markers->isDone());
        self::assertSame('post_restore', $markers->readRequiredProfile());

        $markers->markRequired();
        self::assertNull($markers->readRequiredProfile());

        $markers->markDone();
        $markers->clearDone();
        self::assertFalse($markers->isDone());
    }
}

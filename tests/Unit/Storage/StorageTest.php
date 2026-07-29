<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use const FILE_APPEND;

final class StorageTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-storage-' . uniqid('', true);
        $this->fs->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testHistoryAppendAndList(): void
    {
        $file    = $this->dir . '/history.jsonl';
        $storage = new FilesystemBackupHistoryStorage($file);

        self::assertSame([], $storage->list());
        self::assertSame([], $storage->list(0));

        $storage->append(new BackupHistoryEntry('create', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), backupId: 'a'));
        $storage->append(new BackupHistoryEntry('delete', new DateTimeImmutable('2026-01-02T00:00:00+00:00'), backupId: 'b'));

        file_put_contents($file, "not-json\n", FILE_APPEND);

        $list = $storage->list(1);
        self::assertCount(1, $list);
        self::assertSame('delete', $list[0]->getAction());
    }

    public function testRestoreProgressLoadSave(): void
    {
        $file    = $this->dir . '/restore.json';
        $storage = new FilesystemRestoreProgressStorage($file);

        self::assertSame(RestoreProgress::PHASE_IDLE, $storage->load()->getPhase());

        $progress = new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 55.0);
        $storage->save($progress);

        $loaded = $storage->load();
        self::assertTrue($loaded->isActive());
        self::assertSame(55.0, $loaded->getPercent());
    }

    public function testRestoreProgressLoadInvalidJson(): void
    {
        $file = $this->dir . '/bad.json';
        file_put_contents($file, '{bad');
        $storage = new FilesystemRestoreProgressStorage($file);
        self::assertSame(RestoreProgress::PHASE_IDLE, $storage->load()->getPhase());
    }

    public function testRestoreProgressLoadEmptyFile(): void
    {
        $file = $this->dir . '/empty.json';
        file_put_contents($file, '');
        $storage = new FilesystemRestoreProgressStorage($file);
        self::assertSame(RestoreProgress::PHASE_IDLE, $storage->load()->getPhase());
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Backup;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function file_put_contents;
use function glob;
use function hrtime;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-RUNTIME-001: the database dump subprocess (primary blocking path of a backup) has a hard deadline; the error
 * never echoes the configured command (it may carry credentials).
 */
final class BackupArchiverTimeoutTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-timeout-proj-' . uniqid('', true);
        $this->storageDir = sys_get_temp_dir() . '/nowo-sbb-timeout-store-' . uniqid('', true);
        $this->fs->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "foo: bar\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir]);
    }

    public function testDatabaseDumpTimeoutFailsWithoutLeakingTheCommand(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'MYSQL_PWD=REDACTED-TEST sleep 60',
            processTimeoutSeconds: 1,
        );
        $started = hrtime(true);

        try {
            $archiver->create('slow');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('Database dump timed out after 1 seconds (process_timeout).', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('REDACTED-TEST', $e->getMessage());
        }

        self::assertLessThan(10_000_000_000, hrtime(true) - $started);
        self::assertSame([], glob($this->storageDir . '/*.tar.gz') ?: [], 'no archive is published after a timeout');
    }

    public function testArchiveIdAndDatesComeFromTheClock(): void
    {
        $clock    = new MockClock(new DateTimeImmutable('2026-01-02 03:04:05'));
        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
            clock: $clock,
        );

        $artifact = $archiver->create();

        self::assertStringStartsWith('20260102-030405-', $artifact->getId());
        self::assertSame('2026-01-02 03:04:05', $artifact->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testFailedTarRemovesThePartialArchive(): void
    {
        $this->fs->mkdir($this->storageDir);
        $target = $this->storageDir . '/partial.tar.gz';

        try {
            $this->invokeCreateTarGz($this->archiver(60), $this->projectDir . '/missing-payload', $target);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('tar create failed: ', $e->getMessage());
        }

        self::assertFileDoesNotExist($target);
    }

    public function testTarTimeoutRemovesThePartialArchive(): void
    {
        $this->fs->mkdir($this->storageDir);
        $target = $this->storageDir . '/blocked.tar.gz';
        // tar blocks opening a FIFO nobody reads: a stand-in for a tar that never finishes.
        (new Process(['mkfifo', $target]))->mustRun();

        try {
            $this->invokeCreateTarGz($this->archiver(1), $this->projectDir, $target);
            self::fail('Expected ProcessTimedOutException');
        } catch (ProcessTimedOutException) {
        }

        self::assertFileDoesNotExist($target);
    }

    private function archiver(int $timeout): BackupArchiver
    {
        return new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: $timeout,
        );
    }

    private function invokeCreateTarGz(BackupArchiver $archiver, string $payloadDir, string $target): void
    {
        (new ReflectionMethod(BackupArchiver::class, 'createTarGz'))->invoke($archiver, $payloadDir, $target);
    }
}

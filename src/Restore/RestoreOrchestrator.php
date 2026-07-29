<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Restore;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\RestoreProgressStorageInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Throwable;

use function array_slice;
use function count;
use function dirname;
use function implode;
use function is_dir;
use function is_file;
use function is_string;
use function min;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;

/**
 * Safe restore: validate → extract to staging → apply under restore mode → finalize.
 *
 * The loading page UI reads progress from {@see RestoreProgressStorageInterface}
 * (kept outside overwritten paths when configured). Bundle Twig templates serve the
 * UI so a mid-restore filesystem swap does not blank the visitor experience.
 */
final class RestoreOrchestrator
{
    /**
     * @param list<string> $protectedRelativePaths never overwritten during apply
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly BackupArchiver $archiver,
        private readonly RestoreProgressStorageInterface $progressStorage,
        private readonly array $protectedRelativePaths,
        private readonly ?SetupMarkerManager $setupMarkers = null,
        private readonly bool $triggerSetupAfterRestore = true,
        private readonly string $postRestoreSetupProfile = 'post_restore',
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function getProgress(): RestoreProgress
    {
        return $this->progressStorage->load();
    }

    public function isRestoreActive(): bool
    {
        $progress = $this->progressStorage->load();

        return $progress->isActive() && $progress->getPhase() !== RestoreProgress::PHASE_COMPLETED
            && $progress->getPhase() !== RestoreProgress::PHASE_FAILED
            && $progress->getPhase() !== RestoreProgress::PHASE_IDLE;
    }

    /**
     * Runs a full restore synchronously (CLI / worker). Panel may also call this.
     */
    public function restore(BackupArtifact $artifact, ?string $actor = null): RestoreProgress
    {
        if ($this->isRestoreActive()) {
            throw new RuntimeException('A restore is already in progress.');
        }

        $now        = new DateTimeImmutable();
        $startedLog = 'Restore started for backup ' . $artifact->getId();
        if (is_string($actor) && $actor !== '') {
            $startedLog .= ' (actor=' . $actor . ')';
        }
        $this->writeProgress(new RestoreProgress(
            active: true,
            phase: RestoreProgress::PHASE_PREPARING,
            percent: 1.0,
            message: 'Preparing restore…',
            backupId: $artifact->getId(),
            log: [$this->logLine($startedLog)],
            startedAt: $now,
            updatedAt: $now,
        ));

        $staging = sys_get_temp_dir() . '/nowo-site-backup-restore-' . $artifact->getId();

        try {
            $this->advance(RestoreProgress::PHASE_VALIDATING, 10.0, 'Verifying archive integrity…');
            $integrity = $this->archiver->verifyIntegrity($artifact);
            if (!$integrity['ok']) {
                throw new RuntimeException('Integrity check failed: ' . implode('; ', $integrity['errors']));
            }
            $this->appendLog('Integrity OK (' . count($integrity['checksums']) . ' files).');

            $this->filesystem->remove($staging);
            $this->filesystem->mkdir($staging);

            $this->advance(RestoreProgress::PHASE_EXTRACTING, 35.0, 'Extracting archive to staging…');
            $this->archiver->extractTo($artifact, $staging);
            $this->appendLog('Extracted to staging.');

            $this->advance(RestoreProgress::PHASE_APPLYING, 55.0, 'Applying files safely…');
            $this->applyFromStaging($staging);
            $this->appendLog('Files applied.');

            $this->advance(RestoreProgress::PHASE_FINALIZING, 90.0, 'Finalizing…');
            // Database dump (if present) is left for the app/ops to import via configured post-steps.
            $dump = $staging . '/database/dump.sql';
            if (is_file($dump)) {
                $targetDump = rtrim($this->projectDir, '/\\') . '/var/site-backup/last-restore-dump.sql';
                $this->filesystem->mkdir(dirname($targetDump));
                $this->filesystem->copy($dump, $targetDump, true);
                $this->appendLog('Database dump copied to var/site-backup/last-restore-dump.sql (import separately if needed).');
            }

            $finished = new DateTimeImmutable();
            $message  = 'Restore completed successfully.';
            if ($this->triggerSetupAfterRestore && $this->setupMarkers instanceof SetupMarkerManager) {
                $this->setupMarkers->markRequired($this->postRestoreSetupProfile);
                $message = 'Restore completed. Open /_setup to finish database bootstrap.';
                $this->appendLog('Marked setup.required (profile=' . $this->postRestoreSetupProfile . ').');
            }

            $progress = $this->progressStorage->load()->with(
                active: false,
                phase: RestoreProgress::PHASE_COMPLETED,
                percent: 100.0,
                message: $message,
                updatedAt: $finished,
                finishedAt: $finished,
            );
            $this->progressStorage->save($progress);

            return $progress;
        } catch (Throwable $e) {
            $failed   = new DateTimeImmutable();
            $progress = $this->progressStorage->load();
            $log      = $progress->getLog();
            $log[]    = $this->logLine('ERROR: ' . $e->getMessage());
            $this->progressStorage->save($progress->with(
                active: false,
                phase: RestoreProgress::PHASE_FAILED,
                message: 'Restore failed.',
                error: $e->getMessage(),
                log: array_slice($log, -200),
                updatedAt: $failed,
                finishedAt: $failed,
            ));

            throw $e;
        } finally {
            if (is_dir($staging)) {
                $this->filesystem->remove($staging);
            }
        }
    }

    public function clearFailedOrCompleted(): void
    {
        $this->progressStorage->save(new RestoreProgress());
    }

    private function applyFromStaging(string $staging): void
    {
        $project = rtrim($this->projectDir, '/\\');
        $finder  = (new Finder())->files()->in($staging)->ignoreDotFiles(false);

        $total  = iterator_count($finder);
        $i      = 0;
        $finder = (new Finder())->files()->in($staging)->ignoreDotFiles(false);

        foreach ($finder as $file) {
            ++$i;
            $full     = $file->getPathname();
            $relative = substr($full, strlen($staging) + 1);
            $relative = str_replace('\\', '/', $relative);

            if ($relative === 'MANIFEST.json' || str_starts_with($relative, 'database/')) {
                continue;
            }

            if ($this->isProtected($relative)) {
                $this->appendLog('Skipped protected path: ' . $relative);
                continue;
            }

            $dest = $project . '/' . $relative;
            $this->filesystem->mkdir(dirname($dest));
            // Copy to temp beside destination then rename for near-atomic file replace
            $tmp = $dest . '.nowo-restore-tmp';
            $this->filesystem->copy($full, $tmp, true);
            $this->filesystem->rename($tmp, $dest, true);

            if ($total > 0 && $i % 25 === 0) {
                $pct = 55.0 + (35.0 * ($i / $total));
                $this->advance(RestoreProgress::PHASE_APPLYING, min(89.0, $pct), sprintf('Applying files (%d/%d)…', $i, $total));
            }
        }
    }

    private function isProtected(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        foreach ($this->protectedRelativePaths as $protected) {
            $protected = str_replace('\\', '/', ltrim($protected, '/'));
            if ($relative === $protected || str_starts_with($relative, rtrim($protected, '/') . '/')) {
                return true;
            }
        }

        // Never overwrite live restore progress / history / storage during apply
        return str_starts_with($relative, 'var/site-backup/');
    }

    private function advance(string $phase, float $percent, string $message): void
    {
        $progress = $this->progressStorage->load();
        $this->progressStorage->save($progress->with(
            active: true,
            phase: $phase,
            percent: $percent,
            message: $message,
            updatedAt: new DateTimeImmutable(),
        ));
    }

    private function appendLog(string $message): void
    {
        $progress = $this->progressStorage->load();
        $log      = $progress->getLog();
        $log[]    = $this->logLine($message);
        $this->progressStorage->save($progress->with(
            log: array_slice($log, -200),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    private function writeProgress(RestoreProgress $progress): void
    {
        $this->progressStorage->save($progress);
    }

    private function logLine(string $message): string
    {
        return (new DateTimeImmutable())->format('H:i:s') . ' ' . $message;
    }
}

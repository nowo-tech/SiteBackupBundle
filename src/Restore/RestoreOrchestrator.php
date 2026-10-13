<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Restore;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\RuntimeStatePaths;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\RestoreProgressStorageInterface;
use Nowo\SiteBackupBundle\Worker\WorkerRestartSignal;
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
use function time;

/**
 * Safe restore: validate → extract to staging → apply under restore mode → finalize.
 *
 * The loading page UI reads progress from {@see RestoreProgressStorageInterface}
 * (kept outside overwritten paths when configured). Bundle Twig templates serve the
 * UI so a mid-restore filesystem swap does not blank the visitor experience.
 *
 * Once files were applied, {@see WorkerRestartSignal} is raised: long-lived workers keep
 * serving the code / config they booted with until they are restarted.
 */
final class RestoreOrchestrator
{
    /**
     * Progress messages are translation ids (domain NowoSiteBackupBundle), translated by the loading page,
     * the panel and the JSON responses.
     */
    public const MESSAGE_PREPARING                = 'restore.message.preparing';
    public const MESSAGE_VALIDATING               = 'restore.message.validating';
    public const MESSAGE_EXTRACTING               = 'restore.message.extracting';
    public const MESSAGE_APPLYING                 = 'restore.message.applying';
    public const MESSAGE_FINALIZING               = 'restore.message.finalizing';
    public const MESSAGE_COMPLETED                = 'restore.message.completed';
    public const MESSAGE_COMPLETED_SETUP_REQUIRED = 'restore.message.completed_setup_required';
    public const MESSAGE_FAILED                   = 'restore.message.failed';

    /**
     * @param list<string> $protectedRelativePaths never overwritten during apply
     * @param int|null $staleAfterSeconds an "active" restore whose progress was not updated for this long is
     *                                    considered abandoned (dead worker / killed process): the loading page
     *                                    stops being served and a new restore may start. null or 0 = never stale
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
        private readonly ?WorkerRestartSignal $workerRestartSignal = null,
        private readonly ?int $staleAfterSeconds = null,
    ) {
    }

    public function getProgress(): RestoreProgress
    {
        return $this->progressStorage->load();
    }

    public function isRestoreActive(): bool
    {
        $progress = $this->progressStorage->load();

        return $this->isRunning($progress) && !$this->isStale($progress);
    }

    /**
     * True when the stored progress claims a running restore but was not updated within stale_after_seconds.
     */
    public function isRestoreStale(): bool
    {
        $progress = $this->progressStorage->load();

        return $this->isRunning($progress) && $this->isStale($progress);
    }

    private function isRunning(RestoreProgress $progress): bool
    {
        return $progress->isActive() && $progress->getPhase() !== RestoreProgress::PHASE_COMPLETED
            && $progress->getPhase() !== RestoreProgress::PHASE_FAILED
            && $progress->getPhase() !== RestoreProgress::PHASE_IDLE;
    }

    private function isStale(RestoreProgress $progress): bool
    {
        if ($this->staleAfterSeconds === null || $this->staleAfterSeconds <= 0) {
            return false;
        }

        $last = $progress->getUpdatedAt() ?? $progress->getStartedAt();
        if (!$last instanceof DateTimeImmutable) {
            return false;
        }

        return (time() - $last->getTimestamp()) > $this->staleAfterSeconds;
    }

    /**
     * Runs a full restore synchronously (CLI / worker). Panel may also call this.
     */
    public function restore(BackupArtifact $artifact, ?string $actor = null): RestoreProgress
    {
        if ($this->isRestoreActive()) {
            throw new RuntimeException('A restore is already in progress.');
        }

        $initialLog = [];
        if ($this->isRestoreStale()) {
            $previous     = $this->progressStorage->load();
            $initialLog[] = $this->logLine(sprintf(
                'Previous restore%s abandoned: no progress for more than %d s (phase=%s).',
                $previous->getBackupId() !== null ? ' of backup ' . $previous->getBackupId() : '',
                (int) $this->staleAfterSeconds,
                $previous->getPhase(),
            ));
        }

        $now        = new DateTimeImmutable();
        $startedLog = 'Restore started for backup ' . $artifact->getId();
        if (is_string($actor) && $actor !== '') {
            $startedLog .= ' (actor=' . $actor . ')';
        }
        $initialLog[] = $this->logLine($startedLog);
        $this->writeProgress(new RestoreProgress(
            active: true,
            phase: RestoreProgress::PHASE_PREPARING,
            percent: 1.0,
            message: self::MESSAGE_PREPARING,
            backupId: $artifact->getId(),
            log: $initialLog,
            startedAt: $now,
            updatedAt: $now,
        ));

        $staging      = sys_get_temp_dir() . '/nowo-site-backup-restore-' . $artifact->getId();
        $filesTouched = false;

        try {
            $this->advance(RestoreProgress::PHASE_VALIDATING, 10.0, self::MESSAGE_VALIDATING);
            $integrity = $this->archiver->verifyIntegrity($artifact);
            if (!$integrity['ok']) {
                throw new RuntimeException('Integrity check failed: ' . implode('; ', $integrity['errors']));
            }
            $this->appendLog('Integrity OK (' . count($integrity['checksums']) . ' files).');

            $this->filesystem->remove($staging);
            $this->filesystem->mkdir($staging);

            $this->advance(RestoreProgress::PHASE_EXTRACTING, 35.0, self::MESSAGE_EXTRACTING);
            $this->archiver->extractTo($artifact, $staging);
            $this->appendLog('Extracted to staging.');

            $this->advance(RestoreProgress::PHASE_APPLYING, 55.0, self::MESSAGE_APPLYING);
            $filesTouched = true;
            $applied      = $this->applyFromStaging($staging);
            $this->appendLog(sprintf('Files applied (%d).', $applied));
            if ($this->workerRestartSignal instanceof WorkerRestartSignal) {
                $this->appendLog('Restart PHP workers (FrankenPHP worker mode) so they load the restored code and config.');
            }

            $this->advance(RestoreProgress::PHASE_FINALIZING, 90.0, self::MESSAGE_FINALIZING);
            // Database dump (if present) is left for the app/ops to import via configured post-steps.
            $dump = $staging . '/database/dump.sql';
            if (is_file($dump)) {
                $targetDump = rtrim($this->projectDir, '/\\') . '/var/site-backup/last-restore-dump.sql';
                $this->filesystem->mkdir(dirname($targetDump));
                $this->filesystem->copy($dump, $targetDump, true);
                // Plaintext copy of the whole DB: owner-only until SqlFileStep imports (and deletes) it.
                $this->filesystem->chmod($targetDump, 0o600);
                $this->appendLog('Database dump copied to var/site-backup/last-restore-dump.sql (import separately if needed).');
            }

            $finished = new DateTimeImmutable();
            $message  = self::MESSAGE_COMPLETED;
            if ($this->triggerSetupAfterRestore && $this->setupMarkers instanceof SetupMarkerManager) {
                $this->setupMarkers->markRequired($this->postRestoreSetupProfile);
                $message = self::MESSAGE_COMPLETED_SETUP_REQUIRED;
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
                message: self::MESSAGE_FAILED,
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
            if ($filesTouched) {
                $this->workerRestartSignal?->request('restore:' . $artifact->getId());
            }
        }
    }

    public function clearFailedOrCompleted(): void
    {
        $this->progressStorage->save(new RestoreProgress());
    }

    /**
     * @return int number of files written to the project
     */
    private function applyFromStaging(string $staging): int
    {
        $project = rtrim($this->projectDir, '/\\');
        $finder  = (new Finder())->files()->in($staging)->ignoreDotFiles(false);

        $total   = iterator_count($finder);
        $i       = 0;
        $applied = 0;
        $finder  = (new Finder())->files()->in($staging)->ignoreDotFiles(false);

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
            ++$applied;

            if ($total > 0 && $i % 25 === 0) {
                $pct = 55.0 + (35.0 * ($i / $total));
                $this->advance(RestoreProgress::PHASE_APPLYING, min(89.0, $pct), self::MESSAGE_APPLYING);
            }
        }

        return $applied;
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
        if (str_starts_with($relative, 'var/site-backup/')) {
            return true;
        }

        // Same for the configured storage_dir / progress_file / history_file when they live elsewhere in the project
        return RuntimeStatePaths::matches($relative, $this->archiver->getRuntimeStateRelativePaths());
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

<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function file_get_contents;
use function is_file;
use function trim;

use const DATE_ATOM;

/**
 * File markers of the setup wizard: {@code setup.required}, {@code setup.done} and (1.32+) {@code setup.reopened}.
 *
 * {@code setup.reopened} is written only by {@see SetupWizardReopener} when it clears a
 * done instance, and removed when the wizard completes ({@see markDone()}) or by an operator
 * ({@code nowo:site-backup:setup-reset --initial}). No HTTP input reaches it, so a request cannot forge or drop the
 * "this is a reopen" state (audit IN-I1).
 */
final class SetupMarkerManager
{
    private readonly string $reopenedFile;

    public function __construct(
        private readonly string $requiredFile,
        private readonly string $doneFile,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly ClockInterface $clock = new NativeClock(),
        ?string $reopenedFile = null,
    ) {
        $this->reopenedFile = $reopenedFile !== null && $reopenedFile !== ''
            ? $reopenedFile
            : dirname($this->doneFile) . '/setup.reopened';
    }

    public function isRequiredMarked(): bool
    {
        return is_file($this->requiredFile);
    }

    public function isDone(): bool
    {
        return is_file($this->doneFile);
    }

    public function markRequired(?string $profile = null): void
    {
        $this->filesystem->mkdir(dirname($this->requiredFile));
        $content = $profile !== null && $profile !== '' ? $profile : '1';
        $this->filesystem->dumpFile($this->requiredFile, $content . "\n");
        if (is_file($this->doneFile)) {
            $this->filesystem->remove($this->doneFile);
        }
    }

    public function readRequiredProfile(): ?string
    {
        if (!is_file($this->requiredFile)) {
            return null;
        }
        $raw = trim((string) file_get_contents($this->requiredFile));

        return $raw !== '' && $raw !== '1' ? $raw : null;
    }

    public function markDone(): void
    {
        $this->filesystem->mkdir(dirname($this->doneFile));
        $this->filesystem->dumpFile($this->doneFile, $this->clock->now()->format(DATE_ATOM) . "\n");
        if (is_file($this->requiredFile)) {
            $this->filesystem->remove($this->requiredFile);
        }
        $this->clearReopened();
    }

    /**
     * 1.32+: the wizard was re-opened on an instance that had completed setup.
     */
    public function isReopened(): bool
    {
        return is_file($this->reopenedFile);
    }

    public function markReopened(): void
    {
        $this->filesystem->mkdir(dirname($this->reopenedFile));
        $this->filesystem->dumpFile($this->reopenedFile, $this->clock->now()->format(DATE_ATOM) . "\n");
    }

    public function clearReopened(): void
    {
        if (is_file($this->reopenedFile)) {
            $this->filesystem->remove($this->reopenedFile);
        }
    }

    public function getReopenedFile(): string
    {
        return $this->reopenedFile;
    }

    public function clearDone(): void
    {
        if (is_file($this->doneFile)) {
            $this->filesystem->remove($this->doneFile);
        }
    }

    public function getDoneFile(): string
    {
        return $this->doneFile;
    }

    public function getRequiredFile(): string
    {
        return $this->requiredFile;
    }
}

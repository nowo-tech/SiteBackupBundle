<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function file_get_contents;
use function is_file;
use function trim;

use const DATE_ATOM;

final class SetupMarkerManager
{
    public function __construct(
        private readonly string $requiredFile,
        private readonly string $doneFile,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
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
        $this->filesystem->dumpFile($this->doneFile, (new DateTimeImmutable())->format(DATE_ATOM) . "\n");
        if (is_file($this->requiredFile)) {
            $this->filesystem->remove($this->requiredFile);
        }
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

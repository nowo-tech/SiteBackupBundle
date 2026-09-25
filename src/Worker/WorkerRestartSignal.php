<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Worker;

use DateTimeImmutable;
use JsonException;
use Nowo\SiteBackupBundle\Event\WorkerRestartRequiredEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * "Restart PHP workers" signal shared by every worker and the CLI (marker file under var/site-backup/).
 *
 * Written when a restore or a setup step changed code, `.env.local` or the cache. The marker stays
 * until an operator clears it (`nowo:site-backup:worker-restart --clear`) after restarting workers.
 */
final class WorkerRestartSignal
{
    public function __construct(
        private readonly string $markerFile,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function request(string $reason): void
    {
        $this->filesystem->mkdir(dirname($this->markerFile));
        $this->filesystem->dumpFile($this->markerFile, json_encode([
            'reason'       => $reason,
            'requested_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR) . "\n");

        $this->eventDispatcher?->dispatch(new WorkerRestartRequiredEvent($reason));
    }

    public function isRequested(): bool
    {
        return is_file($this->markerFile);
    }

    /**
     * @return array{reason: string, requested_at: ?string}|null
     */
    public function read(): ?array
    {
        if (!is_file($this->markerFile)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($this->markerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }

        if (!is_array($data)) {
            return ['reason' => 'unknown', 'requested_at' => null];
        }

        return [
            'reason'       => is_string($data['reason'] ?? null) ? $data['reason'] : 'unknown',
            'requested_at' => is_string($data['requested_at'] ?? null) ? $data['requested_at'] : null,
        ];
    }

    public function clear(): void
    {
        if (is_file($this->markerFile)) {
            $this->filesystem->remove($this->markerFile);
        }
    }

    public function getMarkerFile(): string
    {
        return $this->markerFile;
    }
}

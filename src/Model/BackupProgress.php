<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

/**
 * Liveness signal passed to {@see BackupOptions::$heartbeat} while a backup runs (1.28+).
 *
 * Fired at least every few seconds during long steps so a caller can refresh a lock or a Messenger keepalive.
 */
final readonly class BackupProgress
{
    public const PHASE_COPYING   = 'copying';
    public const PHASE_DUMPING   = 'dumping';
    public const PHASE_ARCHIVING = 'archiving';
    public const PHASE_HASHING   = 'hashing';
    public const PHASE_DONE      = 'done';

    public function __construct(
        public string $phase,
        public ?int $bytes = null,
    ) {
    }
}

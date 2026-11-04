<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

/**
 * Names of every event the bundle dispatches (1.28+). Events are dispatched by class name: these constants are the
 * FQCNs, usable with `#[AsEventListener(event: SiteBackupEvents::BACKUP_FAILED)]`.
 */
final class SiteBackupEvents
{
    public const BACKUP_STARTED          = BackupStartedEvent::class;
    public const BACKUP_CREATED          = BackupCreatedEvent::class;
    public const BACKUP_FAILED           = BackupFailedEvent::class;
    public const BACKUP_DELETED          = BackupDeletedEvent::class;
    public const RESTORE_STARTED         = RestoreStartedEvent::class;
    public const RESTORE_COMPLETED       = RestoreCompletedEvent::class;
    public const RESTORE_FAILED          = RestoreFailedEvent::class;
    public const SETUP_STARTED           = SetupStartedEvent::class;
    public const SETUP_STEP_COMPLETED    = SetupStepCompletedEvent::class;
    public const SETUP_STEP_FAILED       = SetupStepFailedEvent::class;
    public const SETUP_COMPLETED         = SetupCompletedEvent::class;
    public const WORKER_RESTART_REQUIRED = WorkerRestartRequiredEvent::class;
}

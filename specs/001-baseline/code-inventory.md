# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/site-backup-bundle`  
**Last audited**: 2026-10-29 (v1.24.x + unreleased REQ-100 pass; features 002–006 included; feature IDs are prefixed with their spec number, e.g. `004 FR-PERF-001` → [`specs/004-worker-perf-dump-hardening/`](../004-worker-perf-dump-hardening/spec.md))

Every production source under `src/` is listed exactly once, grouped by domain (REQ-SPECKIT-001 / REQ-SPECKIT-003).

## Bundle & dependency injection

| Source file | Requirement IDs |
| --- | --- |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | FR-SEC-002 |
| `DependencyInjection/Compiler/LoginThrottleStoragePass.php` | 005 FR-THR-005 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | FR-TWIG-001 |
| `DependencyInjection/Configuration.php` | FR-DI-001, FR-CFG-001, FR-CFG-002 |
| `DependencyInjection/SiteBackupExtension.php` | FR-DI-001, FR-DI-002, FR-DI-003 |
| `NowoSiteBackupBundle.php` | FR-DI-001 |

## Configuration & routing

| Source file | Requirement IDs |
| --- | --- |
| `Resources/config/packages/nowo_site_backup.yaml` | FR-CFG-001 |
| `Resources/config/routes.yaml` | FR-DI-001 |
| `Resources/config/services.yaml` | FR-DI-001 |
| `Routing/PanelRouteLoader.php` | FR-DI-001, FR-HTTP-001 |
| `Routing/SetupLocales.php` | FR-SETUP-004 |
| `Routing/SetupPathPrefixResolver.php` | FR-SETUP-004 |
| `Routing/SetupRouteLoader.php` | FR-SETUP-004 |

## Attributes & exclusions

| Source file | Requirement IDs |
| --- | --- |
| `Attribute/AsSetupNeedDetector.php` | FR-SETUP-003 |
| `Attribute/AsSetupTabChecker.php` | FR-SETUP-010 |
| `Attribute/ExcludeFromRestore.php` | FR-ATTR-001 |
| `Exclusion/SiteBackupExclusionMatcher.php` | FR-BACKUP-002 |

## Backup, database dump & restore

| Source file | Requirement IDs |
| --- | --- |
| `Backup/BackupArchiver.php` | FR-BACKUP-001, FR-RUNTIME-001, FR-DI-003 |
| `Backup/RuntimeStatePaths.php` | FR-BACKUP-002, FR-RESTORE-001 |
| `Database/MysqlDumpCommandFactory.php` | FR-BACKUP-001, 004 FR-DUMP-002 |
| `Model/BackupArtifact.php` | FR-MODEL-001 |
| `Model/BackupHistoryEntry.php` | FR-MODEL-001 |
| `Model/RestoreProgress.php` | FR-MODEL-001 |
| `Model/SetupProgress.php` | FR-MODEL-001 |
| `Restore/RestoreOrchestrator.php` | FR-RESTORE-001, FR-DI-003 |
| `Service/SiteBackupManager.php` | FR-SVC-001, FR-OBS-001, FR-DI-003 |
| `Storage/BackupHistoryStorageInterface.php` | FR-STORE-001 |
| `Storage/FilesystemBackupHistoryStorage.php` | FR-STORE-001 |
| `Storage/FilesystemRestoreProgressStorage.php` | FR-STORE-001 |
| `Storage/RestoreProgressStorageInterface.php` | FR-STORE-001 |
| `Worker/WorkerRestartSignal.php` | FR-WORKER-001, FR-DI-003 |

## Runtime: subprocess deadlines & log redaction

| Source file | Requirement IDs |
| --- | --- |
| `Log/SecretRedactor.php` | FR-OBS-001 |
| `Process/ProcessDeadline.php` | FR-RUNTIME-001 |

## Console commands

| Source file | Requirement IDs |
| --- | --- |
| `Command/CreateBackupCommand.php` | FR-CLI-001 |
| `Command/DatabaseDumpCommand.php` | FR-CLI-001, 004 FR-DUMP-001, FR-RUNTIME-001 |
| `Command/HashPasswordCommand.php` | FR-CLI-001 |
| `Command/ListBackupsCommand.php` | FR-CLI-001 |
| `Command/RestoreBackupCommand.php` | FR-CLI-001 |
| `Command/SetupCommand.php` | FR-CLI-001 |
| `Command/SetupResetCommand.php` | FR-CLI-001 |
| `Command/SetupStatusCommand.php` | FR-CLI-001 |
| `Command/VerifyBackupCommand.php` | FR-CLI-001 |
| `Command/WorkerRestartCommand.php` | FR-CLI-001 |

## HTTP controllers & request subscribers

| Source file | Requirement IDs |
| --- | --- |
| `Controller/SetupUnlocalizedLocaleRedirectController.php` | FR-HTTP-001, FR-DI-002 |
| `Controller/SetupWizardController.php` | FR-HTTP-001, FR-DI-002 |
| `Controller/SiteBackupPanelController.php` | FR-HTTP-001, FR-DI-002 |
| `EventSubscriber/ColdStartSchemaGateSubscriber.php` | FR-HTTP-002 |
| `EventSubscriber/LongRequestTimeLimitSubscriber.php` | 004 FR-TIME-001, FR-DI-002 |
| `EventSubscriber/ProductionSecretsGuardSubscriber.php` | 004 FR-SEC-001 |
| `EventSubscriber/RestoreRequestSubscriber.php` | FR-HTTP-002 |
| `EventSubscriber/SetupDbDoneRedirectSubscriber.php` | FR-HTTP-002 |
| `EventSubscriber/SetupRequestSubscriber.php` | FR-HTTP-002 |
| `EventSubscriber/UnlocalizedDefaultLocaleSubscriber.php` | FR-SETUP-004 |

## Domain events

| Source file | Requirement IDs |
| --- | --- |
| `Event/BackupCreatedEvent.php` | FR-EVT-001 |
| `Event/BackupDeletedEvent.php` | FR-EVT-001 |
| `Event/RestoreCompletedEvent.php` | FR-EVT-001 |
| `Event/RestoreFailedEvent.php` | FR-EVT-001 |
| `Event/RestoreStartedEvent.php` | FR-EVT-001 |
| `Event/SetupCompletedEvent.php` | FR-EVT-001 |
| `Event/SetupStartedEvent.php` | FR-EVT-001 |
| `Event/SetupStepCompletedEvent.php` | FR-EVT-001 |
| `Event/SetupStepFailedEvent.php` | FR-EVT-001 |
| `Event/WorkerRestartRequiredEvent.php` | FR-WORKER-001 |

## Security

| Source file | Requirement IDs |
| --- | --- |
| `Security/AllowAllSiteBackupAccessChecker.php` | FR-SEC-001 |
| `Security/ConfigurableSiteBackupAccessChecker.php` | FR-SEC-001 |
| `Security/PasswordSiteBackupAccessGate.php` | FR-SEC-001 |
| `Security/SiteBackupAccessCheckerInterface.php` | FR-SEC-001 |
| `Security/SiteBackupAccessGateInterface.php` | FR-SEC-001 |
| `Security/ThrottledSiteBackupAccessGate.php` | FR-SEC-001, 005 FR-THR-002, FR-OBS-001 |

## Forms

| Source file | Requirement IDs |
| --- | --- |
| `Form/AbstractSiteBackupFormType.php` | FR-HTTP-001 |
| `Form/Panel/CreateBackupType.php` | FR-HTTP-001 |
| `Form/Panel/PanelActionType.php` | FR-HTTP-001 |
| `Form/Panel/PanelLoginType.php` | FR-HTTP-001 |
| `Form/Setup/AdminUserType.php` | FR-HTTP-001 |
| `Form/Setup/BootstrapModeType.php` | FR-HTTP-001 |
| `Form/Setup/ContinueType.php` | FR-HTTP-001 |
| `Form/Setup/DatabaseUrlType.php` | FR-HTTP-001 |
| `Form/Setup/ResetType.php` | FR-HTTP-001 |
| `Form/Setup/SampleDataType.php` | FR-HTTP-001 |
| `Form/Setup/SetupTokenType.php` | 006 FR-TOK-001 |

## Enums

| Source file | Requirement IDs |
| --- | --- |
| `Enum/LocaleInPathMode.php` | FR-SETUP-004 |
| `Enum/UnlocalizedLocaleMode.php` | FR-SETUP-004 |

## Setup wizard — core

| Source file | Requirement IDs |
| --- | --- |
| `Setup/AdminUserProvisionerInterface.php` | FR-SETUP-001 |
| `Setup/ConsoleProcessRunner.php` | FR-SETUP-001, FR-RUNTIME-001 |
| `Setup/DurableSetupDoneStoreInterface.php` | FR-SETUP-001 |
| `Setup/NullAdminUserProvisioner.php` | FR-SETUP-001 |
| `Setup/NullDurableSetupDoneStore.php` | FR-SETUP-001 |
| `Setup/SetupContext.php` | FR-SETUP-001 |
| `Setup/SetupDbDoneGuard.php` | FR-SETUP-001, FR-DI-003 |
| `Setup/SetupNeedDetectorInterface.php` | FR-SETUP-001 |
| `Setup/SetupOrchestrator.php` | FR-SETUP-001, FR-CFG-001, FR-OBS-001, FR-DI-003 |
| `Setup/SetupStepFactory.php` | FR-SETUP-001 |
| `Setup/SetupStepInput.php` | FR-SETUP-001 |
| `Setup/SetupStepInterface.php` | FR-SETUP-001 |
| `Setup/SetupStepResult.php` | FR-SETUP-001 |
| `Setup/SetupTabCheckResult.php` | FR-SETUP-001 |
| `Setup/SetupTabCheckerInterface.php` | FR-SETUP-001 |
| `Setup/SetupTabCheckerLocator.php` | FR-SETUP-008, FR-DI-004, FR-OBS-001 |
| `Setup/SetupWizardReopener.php` | FR-SETUP-005, 003 FR-DONE-001 |

## Setup wizard — cold start, detectors & worker memo

| Source file | Requirement IDs |
| --- | --- |
| `Setup/ColdStart/ColdStartRequestAttributes.php` | FR-SETUP-001 |
| `Setup/ColdStart/MemoizedSchemaExistenceChecker.php` | 004 FR-PERF-002 |
| `Setup/ColdStart/MysqlSchemaExistenceChecker.php` | FR-SETUP-001 |
| `Setup/ColdStart/SchemaExistenceCheckerInterface.php` | FR-SETUP-001 |
| `Setup/Detector/DoctrineConnectDetector.php` | FR-SETUP-003, FR-DI-002 |
| `Setup/Detector/DoctrineSchemaEmptyDetector.php` | FR-SETUP-003, FR-DI-002 |
| `Setup/Detector/IncompleteSetupProgressDetector.php` | FR-SETUP-003, FR-DI-002 |
| `Setup/Detector/MarkerFileDetector.php` | FR-SETUP-003, FR-DI-002 |
| `Setup/Detector/SetupNeedEvaluator.php` | FR-SETUP-003 |
| `Setup/Memo/WorkerTtlMemo.php` | 004 FR-PERF-001 |

## Setup wizard — steps

| Source file | Requirement IDs |
| --- | --- |
| `Setup/Step/AbstractSetupStep.php` | FR-SETUP-001 |
| `Setup/Step/AdminUserStep.php` | FR-SETUP-001 |
| `Setup/Step/BootstrapModeStep.php` | FR-SETUP-001 |
| `Setup/Step/CacheClearStep.php` | FR-SETUP-001 |
| `Setup/Step/ConditionalAnswerStep.php` | FR-SETUP-001 |
| `Setup/Step/ConsoleStep.php` | FR-SETUP-001 |
| `Setup/Step/CustomSetupStep.php` | FR-SETUP-001 |
| `Setup/Step/DatabaseCreateStep.php` | FR-SETUP-001 |
| `Setup/Step/DatabaseUrlStep.php` | FR-SETUP-001 |
| `Setup/Step/MarkerStep.php` | FR-SETUP-001 |
| `Setup/Step/MigrationsStep.php` | FR-SETUP-001 |
| `Setup/Step/RequirementsStep.php` | FR-SETUP-001 |
| `Setup/Step/SampleDataStep.php` | FR-SETUP-001 |
| `Setup/Step/SchemaUpdateStep.php` | FR-SETUP-001 |
| `Setup/Step/SqlFileStep.php` | FR-SETUP-001 |
| `Setup/Step/SqlImportPath.php` | FR-SETUP-006 |
| `Setup/Step/TabStep.php` | FR-SETUP-001 |

## Setup wizard — progress storage

| Source file | Requirement IDs |
| --- | --- |
| `Setup/Storage/CacheDoctrineSetupProgressStorage.php` | FR-SETUP-002 |
| `Setup/Storage/CacheSetupProgressStorage.php` | FR-SETUP-002 |
| `Setup/Storage/ChainSetupProgressStorage.php` | FR-SETUP-002 |
| `Setup/Storage/DoctrineDbalSetupProgressStorage.php` | FR-SETUP-002, FR-DI-003 |
| `Setup/Storage/DoctrineDbalSetupStepJournal.php` | FR-SETUP-002, FR-DI-003 |
| `Setup/Storage/FilesystemSetupProgressStorage.php` | FR-SETUP-002 |
| `Setup/Storage/SetupMarkerManager.php` | FR-SETUP-002, FR-DI-003 |
| `Setup/Storage/SetupProgressStorageInterface.php` | FR-SETUP-002 |

## Twig & UiKit page views

| Source file | Requirement IDs |
| --- | --- |
| `Twig/SiteBackupExtension.php` | FR-TWIG-002 |
| `View/PanelPages.php` | FR-TWIG-003, FR-UI-001 |
| `View/SetupPages.php` | FR-TWIG-003, FR-UI-001 |
| `View/SetupTokenGate.php` | FR-SETUP-011, FR-UI-001 |
| `View/SiteBackupView.php` | FR-TWIG-003, FR-UI-001 |

## Twig templates

| Source file | Requirement IDs |
| --- | --- |
| `Resources/views/_messages.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/_legacy_layout.html.twig` | FR-TWIG-003, FR-UI-001 |
| `Resources/views/panel/_restore_status.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/confirm.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/create.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/history.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/index.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/login.html.twig` | FR-TWIG-003 |
| `Resources/views/restore/page.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_admin_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_bootstrap_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_continue_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_database_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_legacy_layout.html.twig` | FR-TWIG-003, FR-UI-001 |
| `Resources/views/setup/_sample_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/done.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/token.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/wizard.html.twig` | FR-TWIG-003 |

## Translations

| Source file | Requirement IDs |
| --- | --- |
| `Resources/translations/NowoSiteBackupBundle.de.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.en.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.es.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.fr.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.it.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.nl.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.pt.yaml` | FR-I18N-001 |

## Coverage summary

| Category | Files |
| --- | ---: |
| Bundle & dependency injection | 6 |
| Configuration & routing | 7 |
| Attributes & exclusions | 4 |
| Backup, database dump & restore | 14 |
| Runtime: subprocess deadlines & log redaction | 2 |
| Console commands | 10 |
| HTTP controllers & request subscribers | 10 |
| Domain events | 10 |
| Security | 6 |
| Forms | 11 |
| Enums | 2 |
| Setup wizard — core | 17 |
| Setup wizard — cold start, detectors & worker memo | 10 |
| Setup wizard — steps | 17 |
| Setup wizard — progress storage | 8 |
| Twig & UiKit page views | 5 |
| Twig templates | 18 |
| Translations | 7 |
| **Total production sources** | **164** |

Audit command: `git ls-files src | wc -l` → 164.

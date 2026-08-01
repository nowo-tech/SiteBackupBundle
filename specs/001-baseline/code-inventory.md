# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)
**Package**: `nowo-tech/site-backup-bundle`
**Last audited**: 2026-07-30 (FR-SETUP layout_template / 1.4.0)

Every production source under `src/` is listed below (REQ-SPECKIT-001 / REQ-SPECKIT-003).

| Source files | Count |
| --- | --- |
| Total | **109** |

| Source file | Requirement IDs |
| --- | --- |
| `Attribute/ExcludeFromRestore.php` | FR-ATTR-001 |
| `Attribute/AsSetupNeedDetector.php` | FR-SETUP-003 |
| `Attribute/AsSetupTabChecker.php` | FR-SETUP-010 |
| `Backup/BackupArchiver.php` | FR-BACKUP-001 |
| `Command/CreateBackupCommand.php` | FR-CLI-001 |
| `Command/HashPasswordCommand.php` | FR-CLI-001 |
| `Command/ListBackupsCommand.php` | FR-CLI-001 |
| `Command/RestoreBackupCommand.php` | FR-CLI-001 |
| `Command/SetupCommand.php` | FR-CLI-001 |
| `Command/SetupResetCommand.php` | FR-CLI-001 |
| `Command/SetupStatusCommand.php` | FR-CLI-001 |
| `Command/VerifyBackupCommand.php` | FR-CLI-001 |
| `Controller/SetupWizardController.php` | FR-HTTP-001 |
| `Controller/SiteBackupPanelController.php` | FR-HTTP-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | FR-TWIG-001 |
| `DependencyInjection/Configuration.php` | FR-CFG-001 |
| `DependencyInjection/SiteBackupExtension.php` | FR-CFG-001 |
| `Event/BackupCreatedEvent.php` | FR-EVT-001 |
| `Event/BackupDeletedEvent.php` | FR-EVT-001 |
| `Event/RestoreCompletedEvent.php` | FR-EVT-001 |
| `Event/RestoreFailedEvent.php` | FR-EVT-001 |
| `Event/RestoreStartedEvent.php` | FR-EVT-001 |
| `Event/SetupCompletedEvent.php` | FR-EVT-001 |
| `Event/SetupStartedEvent.php` | FR-EVT-001 |
| `Event/SetupStepCompletedEvent.php` | FR-EVT-001 |
| `Event/SetupStepFailedEvent.php` | FR-EVT-001 |
| `EventSubscriber/RestoreRequestSubscriber.php` | FR-HTTP-002 |
| `EventSubscriber/SetupRequestSubscriber.php` | FR-HTTP-002, FR-SETUP-004 |
| `Exclusion/SiteBackupExclusionMatcher.php` | FR-BACKUP-002 |
| `Model/BackupArtifact.php` | FR-MODEL-001 |
| `Model/BackupHistoryEntry.php` | FR-MODEL-001 |
| `Model/RestoreProgress.php` | FR-MODEL-001 |
| `Model/SetupProgress.php` | FR-MODEL-001 |
| `NowoSiteBackupBundle.php` | FR-BUNDLE-001 |
| `Resources/config/packages/nowo_site_backup.yaml` | FR-DI-001 |
| `Resources/config/routes.yaml` | FR-DI-001, FR-SETUP-004 |
| `Resources/config/services.yaml` | FR-DI-001 |
| `Resources/translations/NowoSiteBackupBundle.de.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.en.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.es.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.fr.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.it.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.nl.yaml` | FR-I18N-001 |
| `Resources/translations/NowoSiteBackupBundle.pt.yaml` | FR-I18N-001 |
| `Resources/views/panel/history.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/index.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/layout.html.twig` | FR-TWIG-003 |
| `Resources/views/panel/login.html.twig` | FR-TWIG-003 |
| `Resources/views/restore/page.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_admin_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_bootstrap_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_continue_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_database_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/_sample_form.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/admin.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/database.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/done.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/layout.html.twig` | FR-TWIG-003, FR-UI-related |
| `Resources/views/setup/sample_data.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/token.html.twig` | FR-TWIG-003 |
| `Resources/views/setup/wizard.html.twig` | FR-TWIG-003 |
| `Restore/RestoreOrchestrator.php` | FR-RESTORE-001 |
| `Security/PasswordSiteBackupAccessGate.php` | FR-SEC-001 |
| `Security/SiteBackupAccessGateInterface.php` | FR-SEC-001 |
| `Service/SiteBackupManager.php` | FR-SVC-001 |
| `Setup/AdminUserProvisionerInterface.php` | FR-SETUP-001 |
| `Setup/ConsoleProcessRunner.php` | FR-SETUP-001 |
| `Setup/Detector/DoctrineConnectDetector.php` | FR-SETUP-003 |
| `Setup/Detector/DoctrineSchemaEmptyDetector.php` | FR-SETUP-003 |
| `Setup/Detector/IncompleteSetupProgressDetector.php` | FR-SETUP-003 |
| `Setup/Detector/MarkerFileDetector.php` | FR-SETUP-003 |
| `Setup/Detector/SetupNeedEvaluator.php` | FR-SETUP-003 |
| `Setup/NullAdminUserProvisioner.php` | FR-SETUP-001 |
| `Setup/SetupContext.php` | FR-SETUP-001 |
| `Setup/SetupNeedDetectorInterface.php` | FR-SETUP-003 |
| `Setup/SetupOrchestrator.php` | FR-SETUP-001 |
| `Setup/SetupStepFactory.php` | FR-SETUP-001 |
| `Setup/SetupStepInput.php` | FR-SETUP-001 |
| `Setup/SetupStepInterface.php` | FR-SETUP-001 |
| `Setup/SetupStepResult.php` | FR-SETUP-001 |
| `Setup/SetupTabCheckResult.php` | FR-SETUP-008 |
| `Setup/SetupTabCheckerInterface.php` | FR-SETUP-008 |
| `Setup/SetupTabCheckerLocator.php` | FR-SETUP-008 |
| `Setup/Step/AbstractSetupStep.php` | FR-SETUP-001 |
| `Setup/Step/AdminUserStep.php` | FR-SETUP-001 |
| `Setup/Step/BootstrapModeStep.php` | FR-SETUP-006 |
| `Setup/Step/CacheClearStep.php` | FR-SETUP-001 |
| `Setup/Step/ConditionalAnswerStep.php` | FR-SETUP-006 |
| `Setup/Step/ConsoleStep.php` | FR-SETUP-001 |
| `Setup/Step/CustomSetupStep.php` | FR-SETUP-008 |
| `Setup/Step/DatabaseCreateStep.php` | FR-SETUP-001 |
| `Setup/Step/DatabaseUrlStep.php` | FR-SETUP-001 |
| `Setup/Step/MarkerStep.php` | FR-SETUP-001 |
| `Setup/Step/MigrationsStep.php` | FR-SETUP-001 |
| `Setup/Step/RequirementsStep.php` | FR-SETUP-001 |
| `Setup/Step/SampleDataStep.php` | FR-SETUP-001 |
| `Setup/Step/SchemaUpdateStep.php` | FR-SETUP-001 |
| `Setup/Step/SqlFileStep.php` | FR-SETUP-001 |
| `Setup/Step/TabStep.php` | FR-SETUP-008, FR-SETUP-009 |
| `Setup/Storage/ChainSetupProgressStorage.php` | FR-SETUP-002 |
| `Setup/Storage/DoctrineDbalSetupProgressStorage.php` | FR-SETUP-002 |
| `Setup/Storage/FilesystemSetupProgressStorage.php` | FR-SETUP-002 |
| `Setup/Storage/SetupMarkerManager.php` | FR-SETUP-001 |
| `Setup/Storage/SetupProgressStorageInterface.php` | FR-SETUP-002 |
| `Storage/BackupHistoryStorageInterface.php` | FR-STORE-001 |
| `Storage/FilesystemBackupHistoryStorage.php` | FR-STORE-001 |
| `Storage/FilesystemRestoreProgressStorage.php` | FR-STORE-001 |
| `Storage/RestoreProgressStorageInterface.php` | FR-STORE-001 |
| `Twig/SiteBackupExtension.php` | FR-TWIG-002 |


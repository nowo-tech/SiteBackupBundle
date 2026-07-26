# Code inventory (baseline)

| Area | Path | Role |
| --- | --- | --- |
| Bundle | `src/NowoSiteBackupBundle.php` | Bundle + TwigPathsPass |
| DI | `src/DependencyInjection/*` | Config tree + wiring |
| Backup | `src/Backup/BackupArchiver.php` | Create / list / verify / extract |
| Restore | `src/Restore/RestoreOrchestrator.php` | Safe apply + progress + setup.required |
| Facade | `src/Service/SiteBackupManager.php` | App API + events/history |
| HTTP restore | `src/EventSubscriber/RestoreRequestSubscriber.php` | Loading page interceptor |
| Setup | `src/Setup/**` | Wizard engine, steps, detectors, markers |
| HTTP setup | `src/EventSubscriber/SetupRequestSubscriber.php` | Redirect to `/_setup` |
| Panel | `src/Controller/SiteBackupPanelController.php` | CRUD UI + progress.json |
| Wizard UI | `src/Controller/SetupWizardController.php` | Setup wizard + API |
| CLI | `src/Command/*` | backup + setup commands |
| Twig | `src/Resources/views/**` | restore, panel, setup |
| i18n | `src/Resources/translations/NowoSiteBackupBundle.*` | Domain translations |

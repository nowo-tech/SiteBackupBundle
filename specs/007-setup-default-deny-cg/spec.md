# Feature Specification: Default-deny setup wizard, dump argv hardening, private staging (REQ-SEC-004 Appendix CG)

**Feature Branch**: `fix/sec-004-cg`  
**Created**: 2026-11-01  
**Status**: Implemented (proposed **v1.27.0**)  
**Origin**: REQ-SEC-004 re-audit of v1.26.0 (Appendix CG): setup wizard access default-deny, `mysqldump` argv
hardening, private backup staging, closed state independent of the progress file.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-CG-001 | `SetupWizardController::index` and `advanceApi` answer only while `SetupNeedEvaluator::isSetupRequired()` (302 `/` for the page, 404 for the API); the read-only `progress` API stays token-gated only (BC for pollers that read `completed`). The closed state never reads the progress phase: `setup.done` / the durable store close the wizard when progress is idle, lost or stale (I1). |
| FR-CG-002 | A GET never calls `SetupOrchestrator::advance()` (no `setup.required` / progress write); an idle run renders the CSRF-protected Continue form whose POST starts it. |
| FR-CG-003 | Default-deny: a request is admitted with the static `setup.setup_token` or a one-time token (`SetupAccessTokenStore`, SHA-256 hash in `setup.access_token_file`, `0600`, `hash_equals`), deleted when a run completes (page or API). Without a static token the 403 gate shows `setup.token.lead_console` (how to run the command). |
| FR-CG-004 | `nowo:site-backup:setup-token` issues the one-time token and prints it with the wizard URL; `--open[=profile]` writes `setup.required` (and resets a progress record left `completed`); `--revoke` deletes the token; warns when setup is not required. |
| FR-CG-005 | `setup.allow_without_token` (default `false`): literal boolean (env placeholders refused), `InvalidConfigurationException` unless `kernel.debug` is true; opens the page (never `api/advance`) when no static token is set. |
| FR-CG-006 | `api/advance` always requires a provided token (static or one-time): it has no form CSRF token, the secret is its CSRF defence. |
| FR-CG-007 | `MysqlDumpCommandFactory` emits `--` before the database name and rejects database / user / host / `unix_socket` values that start with `-` or contain control characters (decoded) — `DatabaseUrlSafety`; `DatabaseUrlStep` refuses the same values before writing `.env.local`. |
| FR-CG-008 | Backup, verify and restore staging directories come from `PrivateTempDirectory::create()` (`0700`, atomic `mkdir`, 128-bit random suffix, explicit `chmod`, no `umask()`); the dump file is pre-created `0600` before the shell redirect and `MANIFEST.json` is `chmod 0600`. |

## Non-goals

- Changing `security_guard.enabled` (still opt-in: turning it on by default would refuse HTTP on hosts with any
  documented secret, a much wider break).
- Gating `/_setup/done` (static completion page, no state change).

## Tests

- `tests/Integration/SecAudit/SetupWizardDefaultDenyTest.php` (real kernel, bundle defaults; the CG PoCs as assertions):
  anonymous GET neither starts setup nor redirects the site; anonymous POST / API cannot create an admin while setup is
  required; one-time token first install end to end; static token gate; I1; `allow_without_token` debug-only and
  literal-boolean.
- `tests/Unit/Setup/SetupAccessTokenStoreTest.php`, `tests/Unit/Command/SetupTokenCommandTest.php`,
  `tests/Unit/Database/DatabaseUrlSafetyTest.php`, `tests/Unit/Setup/Step/SetupStepsTest.php`
  (`testDatabaseUrlStepRejectsOptionInjectionIntoTheDumpArgv`), `tests/Unit/Backup/PrivateTempDirectoryTest.php`,
  `tests/Unit/Backup/BackupTempPermissionsTest.php`, `tests/Unit/DependencyInjection/SiteBackupExtensionTest.php`.

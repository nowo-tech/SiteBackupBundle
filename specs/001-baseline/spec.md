# Site Backup Bundle — baseline product spec

**Package**: `nowo-tech/site-backup-bundle`  
**Baseline**: `001-baseline`  
**Last amended**: 2026-10-29

## Summary

`nowo-tech/site-backup-bundle` (config root `nowo_site_backup`) guarantees integrators:

- **Integral backups** — selected project paths + optional database dump packed into an owner-only `.tar.gz` with a
  SHA-256 `MANIFEST.json`, from the CLI (`nowo:site-backup:*`) or the UiKit admin panel (`/_site_backup`).
- **Safe restores** — validate → stage → apply with protected paths, while visitors get a 503 loading page and the
  panel / progress JSON stay reachable.
- **Setup wizard** (`/_setup`) — named profiles (`setup.default_profile` + `setup.profiles`) that bootstrap a cold or
  freshly restored database (requirements, `DATABASE_URL`, create DB, migrations / SQL import, admin, sample data).
- **Fail-closed security** — role checker + password gate + CSRF (compile-time check), setup token, optional
  production secrets guard, per-IP login throttle.
- **Bounded runtime** — every subprocess runs under `process_timeout` (hard + idle), the process tree is killed on
  expiry; destructive operations are logged without secrets; FrankenPHP worker-safe services.

## User scenarios

### US-1 — Operator creates a backup
**Priority**: P1  
Given a configured include path set, When the operator runs `nowo:site-backup:create` or uses the panel, Then a `.tar.gz` + `MANIFEST.json` (SHA-256) is written under `var/site-backup/`.

### US-2 — Safe restore for visitors
**Priority**: P1  
Given a restore is in progress, When a visitor hits a normal route, Then they receive HTTP 503 with the loading page; panel/progress routes remain available.

### US-3 — Panel access
**Priority**: P1  
Given `password_protection: true` without `password_hash`, When anyone opens `/_site_backup`, Then access is denied (fail-closed). Given a valid hash and CSRF, When they authenticate, Then create/verify/restore/delete work.

### US-4 — Cold-start / post-restore setup wizard
**Priority**: P1  
Given setup is required (markers, empty schema, or incomplete progress), When a visitor hits a normal HTML route, Then the site gate redirects to `setup.path_prefix` (default **`/_setup`**). The wizard runs the configured profile steps (requirements, database, migrations, admin, optional sample data) and writes `setup.done` on success.

**Acceptance**:

1. **Given** `setup.done` is absent and a detector fires, **When** GET `/dashboard`, **Then** redirect to `/_setup` (or configured prefix).
2. **Given** wizard phase is `waiting_input` for admin, **When** the operator submits email/password + CSRF, **Then** the admin provisioner runs and the pipeline advances.
3. **Given** setup completes, **When** GET `/_setup`, **Then** 404 or redirect home (wizard not public once done).

### US-5 — Durable setup progress (Doctrine / chain)
**Priority**: P1  
Given `setup.progress_storage: chain` (or `doctrine`) and a working DBAL connection, When the operator is mid-wizard and `var/` is wiped, Then load prefers the DB row so the current step/phase survive; `IncompleteSetupProgressDetector` keeps the site gate on until `setup.done` exists.

**Acceptance**:

1. **Given** progress phase `running`/`waiting_input`/`failed` in DB and no `setup.done`, **When** the incomplete detector is enabled (default), **Then** `isSetupRequired()` is true.
2. **Given** `progress_storage: filesystem` only, **When** `var/site-backup/setup-progress.json` is deleted mid-wizard, **Then** progress is lost (documented trade-off).
3. **Given** chain mode, **When** save succeeds **and** DBAL is usable, **Then** both JSON and DBAL table (`setup.progress_table`, default `nowo_site_backup_setup_progress`) are updated; payload includes `started_at` / `completed_at`. When `progress_step_rows` is true, per-step rows are upserted into `setup.progress_steps_table` via runtime DDL (not Symfony Migrations). Early steps before a DB exists keep filesystem-only progress (DB mirror soft-fails).
4. **Given** step rows with `status=completed`, **When** progress JSON lacks those ids, **Then** load merges them from the journal so resume stays correct.
### US-6 — Configurable setup path
**Priority**: P2  
Given `setup.path_prefix: /install`, When the container compiles, Then the wizard routes, the site gate redirect and the exclusion matcher use `/install` (and `/{locale}/install` when `setup.locale.in_path` is not `never`).

**Acceptance**:

1. **Given** the default config, **When** routes are listed, **Then** the wizard lives under `/_setup`.
2. **Given** a custom prefix, **When** setup is required and GET `/`, **Then** redirect to the custom prefix.

### US-7 — Gate until setup is 100% complete
**Priority**: P1  
Given the operator has started the setup wizard (or `setup.required` / incomplete progress / `require_done_marker`), When a visitor hits a normal route before `setup.done` exists, Then the site gate redirects to `/_setup`. Starting the wizard writes `setup.required` so the gate cannot be left mid-flight.

**Acceptance**:

1. **Given** wizard phase is `running` / `waiting_input` / `failed` and no `setup.done`, **When** GET `/`, **Then** redirect to setup.
2. **Given** wizard starts from `idle`, **When** the first `advance` runs, **Then** `setup.required` is marked with the active profile.
3. **Given** the `marker` step writes `setup.done`, **When** GET `/`, **Then** the normal app is served.

### US-8 — Full database import vs guided admin
**Priority**: P1  
Given `fresh_install`, When the operator reaches `bootstrap_mode`, Then they choose **guided** (create admin later) or **full database** (import a `.sql` dump). Full import runs `sql_file`, then **migrations** and any configured idempotent loaders (`console` / `sql_file` / `sample_data`); `admin_user` uses `skip_if_admin_exists` so a dump that already contains users skips the registration form.

**Acceptance**:

1. **Given** guided mode, **When** pipeline reaches `admin_user`, **Then** the admin form is shown (unless an admin already exists).
2. **Given** full_database mode and a valid `sql_import_path` (or default `var/site-backup/full-import.sql` / `last-restore-dump.sql`), **When** advance continues, **Then** SQL is imported before migrations.
3. **Given** full_database mode without a dump file, **When** the operator submits, **Then** the wizard stays on waiting_input asking for the path.
4. **Given** profile `full_database` via `?profile=full_database`, **When** the dump file exists, **Then** import runs without the bootstrap choice step.

### US-9 — YAML tabs, checkers, and advance mode
**Priority**: P1  
Given `setup.profiles.*.tabs` (ordered), When the wizard runs, Then each tab may declare a `checker` service (`SetupTabCheckerInterface`), optional `template` / `runner`, and `label` as a **translation id**. `advance_mode: automatic|manual` (global or per profile) controls whether auto tabs chain until interaction is required.

**Acceptance**:

1. **Given** a profile with `tabs`, **When** the wizard loads, **Then** UI chips follow tab order and labels are translated via `|trans`.
2. **Given** `advance_mode: automatic`, **When** tabs are `auto` and checkers return ok, **Then** the orchestrator continues until `needs_input` / fail / done.
3. **Given** `advance_mode: manual`, **When** one auto tab completes, **Then** the UI waits for Continuar before the next auto tab.
4. **Given** a custom tab with `checker` returning needs_input, **When** the operator opens the wizard, **Then** the configured Twig `template` is shown.
5. **Given** only legacy `steps` (no `tabs`), **When** the profile runs, **Then** behaviour matches pre-tabs profiles (BC).

### US-10 — Hung subprocesses are bounded
**Priority**: P1  
Given `process_timeout: 600`, When the database dump, `tar` or a console setup step hangs, Then it is killed (with its child processes) after 600 s and the backup / step fails with a controlled error that does not repeat the dump command.

**Acceptance**:

1. **Given** a dump command that never ends, **When** a backup runs, **Then** `Database dump timed out after N seconds (process_timeout).` is raised, no archive is published and no `mysqldump` child survives.
2. **Given** `tar` fails or times out, **When** the backup aborts, **Then** no partial archive stays in `storage_dir`.
3. **Given** the FrankenPHP demo, **When** its Caddyfiles load, **Then** PHP `max_execution_time` (360 s) and Caddy `write` (400 s) are above `process_timeout` (300 s) and `max_wait_time` is 30 s.

### US-11 — Operators can audit destructive operations
**Priority**: P2  
Given MonologBundle, When a backup is created, deleted or restored, or a setup run starts / fails / completes, Then a record is written on the `site_backup` channel with the action and backup id / profile / step — never credentials, actors or labels.

### US-12 — Profile misconfiguration fails early
**Priority**: P2  
Given `setup.profiles` without the `setup.default_profile` key, When the container compiles, Then it fails with `Invalid nowo_site_backup.setup.default_profile "…": it must be a key of nowo_site_backup.setup.profiles (defined: …)`.

## Functional requirements

### Bundle, DI & configuration

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | Bundle registers DI, Twig paths, routes, commands |
| FR-DI-001 | `services.yaml` / routes attribute loader with path-prefix parameters |
| FR-DI-002 | Attribute wiring (REQ-SF-003): `#[AsSetupNeedDetector(priority)]` built-in detectors, `#[AsEventListener]` time-limit listener, `#[AsController]` controllers, event subscribers via autoconfigure; manual tags only for config-driven listener priorities, route loaders, the UiKit area-nav provider and the login-throttle storage pass |
| FR-DI-003 | Time-dependent services take `Psr\Clock\ClockInterface` (framework `clock`; default `NativeClock`) |
| FR-DI-004 | `SetupTabCheckerLocator` is a closed-set ServiceLocator of the configured tab `checker:` ids (documented registry, not a grab-bag container) |
| FR-CFG-001 | Strict `Configuration` tree with safe defaults; setup profiles use canonical `setup.default_profile` + `setup.profiles` |
| FR-CFG-002 | `setup.default_profile` must be a key of `setup.profiles` (compile-time error naming the path and the defined profiles; skipped while setup is disabled) |

### Backup, restore & runtime

| ID | Requirement |
| --- | --- |
| FR-BACKUP-001 | Create archive + checksums; optional DB dump; owner-only archive |
| FR-BACKUP-002 | Exclusion matcher (paths / attributes); runtime state never archived |
| FR-RESTORE-001 | Validate → stage → apply with protected paths; heartbeats + stale detection |
| FR-RUNTIME-001 | `ProcessDeadline`: `process_timeout` is the hard and idle timeout of `tar`, the dump, `db-dump` and console steps; on expiry descendants then the process are killed; dump timeout message without the command; partial archive removed |
| FR-SVC-001 | `SiteBackupManager` façade |
| FR-STORE-001 | Filesystem history + restore progress storage |
| FR-MODEL-001 | Artifact / progress / history / `SetupProgress` models |
| FR-ATTR-001 | `#[ExcludeFromRestore]` |
| FR-EVT-001 | Domain events for backup/restore/setup lifecycle |
| FR-WORKER-001 | FrankenPHP worker scenario B (no kernel reset): no cross-request mutable state in shared services; DBAL setup storages self-heal schema memo + `ResetInterface`; after restore / `cache_clear` / `database_url` env write, `WorkerRestartSignal` + `WorkerRestartRequiredEvent` require an operational worker restart |
| FR-OBS-001 | PSR-3 logging on channel `site_backup`: start / success / failure of create, delete, restore and setup runs; structured context; `SecretRedactor` masks credentials; no actors / labels / secrets |

### HTTP, security & CLI

| ID | Requirement |
| --- | --- |
| FR-HTTP-001 | Panel + setup controllers use `#[Route]` + CSRF fail-closed |
| FR-HTTP-002 | Restore/setup request subscribers gate the public site |
| FR-SEC-001 | Password gate fail-closed when hash missing; `isMisconfigured()`; role checker; login throttle |
| FR-SEC-002 | `CsrfProtectionPass`: the container refuses to compile while the panel or the setup wizard is registered without form CSRF (`form.type_extension.csrf`, token manager, `framework.form.csrf_protection`) |
| FR-CLI-001 | Console commands for backup/restore/verify/list/setup/hash/db-dump/worker-restart |

### Setup wizard

| ID | Requirement |
| --- | --- |
| FR-SETUP-001 | Wizard steps, orchestrator, markers, profiles, admin provisioner |
| FR-SETUP-002 | Progress storage: `filesystem` \| `doctrine` \| `chain`; `started_at` / `completed_at`; optional DBAL table auto-create (runtime DDL, not migrations); optional per-step journal (`progress_step_rows`) |
| FR-SETUP-003 | Detectors: marker, doctrine connect, schema empty, **incomplete progress** (toggleable) + **app-tagged** `SetupNeedDetectorInterface`; evaluator ORs enabled detectors |
| FR-SETUP-004 | Default `setup.path_prefix` is `/_setup`; routes honour config via parameters; path auto-added to exclusions; when `setup.locale.in_path: both` + `unlocalized: serve`, `UnlocalizedDefaultLocaleSubscriber` forces `setup.locale.default` at runtime on `*_unlocalized` setup routes |
| FR-SETUP-005 | Starting the wizard marks `setup.required` until `setup.done`; gate stays on while progress is incomplete |
| FR-SETUP-006 | Step type `bootstrap_mode` (`guided` \| `full_database`) + answer `sql_import_path`; `when_answer` filters steps |
| FR-SETUP-007 | Profile `fresh_install` includes bootstrap + conditional full SQL import; profile `full_database` deep-link; migrations + idempotent loaders always after import; `admin_user` skip_if_admin_exists |
| FR-SETUP-008 | Profiles may declare ordered `tabs` with `checker`, `template`, `runner`; legacy `steps` still work |
| FR-SETUP-009 | `advance_mode` `automatic`\|`manual` (setup + per-profile override); interactive tabs always pause |
| FR-SETUP-010 | Tab `label` / `description` are translation ids; domain default `NowoSiteBackupBundle`; `#[AsSetupTabChecker]` autoconfigures checkers |
| FR-SETUP-011 | Setup token gate page (`SetupTokenGate`): configurable illustration (`<picture>` WebP + fallback) and how-to steps (`setup.token_gate`) |

### Twig, UI & i18n

| ID | Requirement |
| --- | --- |
| FR-TWIG-001 | Twig namespace `NowoSiteBackupBundle` with app overrides first (`templates/bundles/NowoSiteBackupBundle/`) |
| FR-TWIG-002 | Twig functions for restore progress |
| FR-TWIG-003 | Panel / restore / setup Twig templates (apps may restyle via overrides) |
| FR-UI-001 | Panel and setup pages render on the UiKit page contract (REQ-UI-003, v1.21+): `View\PanelPages` / `View\SetupPages` build the view models, `View\SiteBackupView` resolves per-page templates and layouts; `_legacy_layout.html.twig` bridges 1.x layouts; panel tabs carry UiKit 2.17 icons |
| FR-I18N-001 | Translations en/es/it/fr/pt/de/nl including `setup.tab.*` |

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-INV-001 | `code-inventory.md` lists 100% of production sources under `src/` — **164/164** (PHP, config YAML, Twig views, translations; audited 2026-10-29), grouped by domain |
| SC-TEST-001 | PHPUnit Lines coverage ≥ 99% (`make coverage-check`) |
| SC-SEC-001 | Panel denied without hash; CSRF required for POSTs |
| SC-SETUP-001 | Incomplete progress detector + doctrine/chain storage covered by unit tests |
| SC-SETUP-002 | Default config exposes `setup.path_prefix: /_setup` and `progress_storage: filesystem` |
| SC-SETUP-003 | `bootstrap_mode` + `when_answer` + full SQL import path covered by unit tests |
| SC-SETUP-004 | Tabs + checker + `advance_mode` covered by unit tests; tab labels use translation ids |
| SC-QA-001 | `make phpstan` / `cs-check` clean |
| SC-RUNTIME-001 | Timeout tests: `ProcessDeadlineTest` (child process killed), `BackupArchiverTimeoutTest`, `ConsoleProcessRunnerTest::testHungCommandTimesOut`, `DatabaseDumpCommandTest::testHungDumpTimesOut` |
| SC-CFG-001 | `ConfigurationTest`: two profiles + default boot; unknown / missing default rejected; skipped while setup disabled |
| SC-OBS-001 | `SiteBackupManagerLoggingTest`, `SetupObservabilityTest`: records, levels, redaction |
| SC-DI-001 | `AttributeWiringKernelTest` (real kernel) + `SiteBackupExtensionTest` autoconfigure assertions |
| SC-WORKER-001 | Safe under FrankenPHP worker with kernel not reset between requests (see `docs/FRANKENPHP-WORKER-AUDIT.md`); DBAL setup storages covered without calling `reset()` |

## Non-goals

- Automatic live SQL import during HTTP restore
- Profiles for backup / restore state (global; REQ-CFG-001 applies to the setup wizard only)
- Orphan reaping outside Linux (no `/proc`: only the direct subprocess is stopped)
- Offsite object-storage adapters (pluggable later)
- Host-app visual theme (Beacon-style UI lives in the consuming app via Twig overrides)

## Key entities

- **BackupArtifact** — archive id (`Ymd-His-<hex>`), path, SHA-256, checksums, meta, label, actor (sidecar `*.meta.json`).
- **BackupHistoryEntry** — JSONL history line (action, actor, backup id, message).
- **RestoreProgress** / **SetupProgress** — phase, percent, message, log, timestamps.
- **Setup profile** — named ordered `tabs` / `steps` + `advance_mode`.

## Assumptions

- `tar` is on the PATH; MySQL / MariaDB dumps use `mysqldump` / `mariadb-dump` (password via `MYSQL_PWD`).
- FrameworkBundle provides autoconfiguration, the `clock` service and (optionally) Monolog `logger`.
- Operators configure `database_dump_command` and console steps (never taken from HTTP input).

## Edge cases

- DBAL unavailable with `progress_storage: doctrine` → writes fail closed; `chain` still writes filesystem when DB mirror fails.
- `incomplete_progress: true` with empty progress → detector returns false (`isIncomplete()` false).
- Soft dependency on `doctrine/dbal` (suggest); duck-typed connection — no hard composer require.

## Validation commands

```bash
make phpstan
make test-coverage
make coverage-check
make release-check
```

## Feature specifications

Incremental features extend this baseline (IDs prefixed with the spec number in [`code-inventory.md`](code-inventory.md)): [002 setup step rows](../002-setup-step-rows/spec.md), [003 durable done / cold start](../003-durable-done-cold-start/spec.md), [004 worker perf + DB dump hardening](../004-worker-perf-dump-hardening/spec.md), [005 panel login throttle](../005-panel-login-throttle/spec.md), [006 translated themed screens](../006-translated-themed-screens/spec.md).

## Links

- [SPEC-DRIVEN-DEVELOPMENT.md](../../docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [SPEC-KIT.md](../../docs/SPEC-KIT.md)
- [SETUP-WIZARD.md](../../docs/SETUP-WIZARD.md)
- [CONFIGURATION.md](../../docs/CONFIGURATION.md)
- [UPGRADING.md](../../docs/UPGRADING.md)
- [code-inventory.md](code-inventory.md)
- [SECURITY.md](../../docs/SECURITY.md)

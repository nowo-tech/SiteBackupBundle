# Site Backup Bundle — baseline product spec

**Package**: `nowo-tech/site-backup-bundle`  
**Baseline**: `001-baseline`  
**Last amended**: 2026-07-30

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
3. **Given** chain mode, **When** save succeeds, **Then** both JSON and DBAL table (`setup.progress_table`, default `nowo_site_backup_setup_progress`) are updated; payload includes `started_at` / `completed_at`.

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

## Functional requirements

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | Bundle registers DI, Twig paths, routes, commands |
| FR-CFG-001 | Strict `Configuration` tree with safe defaults |
| FR-BACKUP-001 | Create archive + checksums; optional DB dump with timeout |
| FR-BACKUP-002 | Exclusion matcher (paths / attributes) |
| FR-RESTORE-001 | Validate → stage → apply with protected paths |
| FR-HTTP-001 | Panel + setup controllers use `#[Route]` + CSRF fail-closed |
| FR-HTTP-002 | Restore/setup request subscribers gate the public site |
| FR-SEC-001 | Password gate fail-closed when hash missing; `isMisconfigured()` |
| FR-CLI-001 | Console commands for backup/restore/setup/hash |
| FR-SETUP-001 | Wizard steps, orchestrator, markers, profiles, admin provisioner |
| FR-SETUP-002 | Progress storage: `filesystem` \| `doctrine` \| `chain`; `started_at` / `completed_at`; optional DBAL table auto-create |
| FR-SETUP-003 | Detectors: marker, doctrine connect, schema empty, **incomplete progress** (toggleable) + **app-tagged** `SetupNeedDetectorInterface`; evaluator ORs enabled detectors |
| FR-SETUP-004 | Default `setup.path_prefix` is `/_setup`; routes honour config via parameters; path auto-added to exclusions |
| FR-SETUP-005 | Starting the wizard marks `setup.required` until `setup.done`; gate stays on while progress is incomplete |
| FR-SETUP-006 | Step type `bootstrap_mode` (`guided` \| `full_database`) + answer `sql_import_path`; `when_answer` filters steps |
| FR-SETUP-007 | Profile `fresh_install` includes bootstrap + conditional full SQL import; profile `full_database` deep-link; migrations + idempotent loaders always after import; `admin_user` skip_if_admin_exists |
| FR-SETUP-008 | Profiles may declare ordered `tabs` with `checker`, `template`, `runner`; legacy `steps` still work |
| FR-SETUP-009 | `advance_mode` `automatic`\|`manual` (setup + per-profile override); interactive tabs always pause |
| FR-SETUP-010 | Tab `label` / `description` are translation ids; domain default `NowoSiteBackupBundle`; `#[AsSetupTabChecker]` autoconfigures checkers |
| FR-TWIG-001 | Twig namespace `NowoSiteBackupBundle` with app overrides first (`templates/bundles/NowoSiteBackupBundle/`) |
| FR-TWIG-002 | Twig functions for restore progress |
| FR-TWIG-003 | Panel / restore / setup Twig templates (apps may restyle via overrides) |
| FR-I18N-001 | Translations en/es/it/fr/pt/de/nl including `setup.tab.*` |
| FR-EVT-001 | Domain events for backup/restore/setup lifecycle |
| FR-STORE-001 | Filesystem history + restore progress storage |
| FR-SVC-001 | `SiteBackupManager` façade |
| FR-MODEL-001 | Artifact / progress / history / `SetupProgress` models |
| FR-ATTR-001 | `#[ExcludeFromRestore]` |
| FR-DI-001 | `services.yaml` / routes attribute loader with path-prefix parameters |

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-INV-001 | `code-inventory.md` lists 100% of production sources under `src/` (PHP + Twig views) |
| SC-TEST-001 | PHPUnit Lines coverage ≥ 99% (`make coverage-check`) |
| SC-SEC-001 | Panel denied without hash; CSRF required for POSTs |
| SC-SETUP-001 | Incomplete progress detector + doctrine/chain storage covered by unit tests |
| SC-SETUP-002 | Default config exposes `setup.path_prefix: /_setup` and `progress_storage: filesystem` |
| SC-SETUP-003 | `bootstrap_mode` + `when_answer` + full SQL import path covered by unit tests |
| SC-SETUP-004 | Tabs + checker + `advance_mode` covered by unit tests; tab labels use translation ids |
| SC-QA-001 | `make phpstan` / `cs-check` clean |

## Non-goals

- Automatic live SQL import during HTTP restore
- Multi-tenant CFG profiles (REQ-CFG-001 N/A)
- Offsite object-storage adapters (pluggable later)
- Host-app visual theme (Beacon-style UI lives in the consuming app via Twig overrides)

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

## Links

- [SPEC-DRIVEN-DEVELOPMENT.md](../../docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [SPEC-KIT.md](../../docs/SPEC-KIT.md)
- [SETUP-WIZARD.md](../../docs/SETUP-WIZARD.md)
- [CONFIGURATION.md](../../docs/CONFIGURATION.md)
- [UPGRADING.md](../../docs/UPGRADING.md)
- [code-inventory.md](code-inventory.md)
- [SECURITY.md](../../docs/SECURITY.md)

# Site Backup Bundle — baseline product spec

**Package**: `nowo-tech/site-backup-bundle`  
**Baseline**: `001-baseline`  
**Last amended**: 2026-07-29

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

### US-6 — Configurable path prefixes
**Priority**: P2  
Given `setup.path_prefix` / `panel.path_prefix`, When routes are imported, Then prefixes come from container parameters (`%nowo.site_backup.setup.path_prefix%`, `%nowo.site_backup.panel.path_prefix%`) so apps can override without editing hard-coded prefixes in `routes.yaml`.

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
| FR-SETUP-003 | Detectors: marker, doctrine connect, schema empty, **incomplete progress** (toggleable); evaluator ORs enabled detectors |
| FR-SETUP-004 | Default `setup.path_prefix` is `/_setup`; routes honour config via parameters; path auto-added to exclusions |
| FR-TWIG-001 | Twig namespace `NowoSiteBackupBundle` with app overrides first (`templates/bundles/NowoSiteBackupBundle/`) |
| FR-TWIG-002 | Twig functions for restore progress |
| FR-TWIG-003 | Panel / restore / setup Twig templates (apps may restyle via overrides) |
| FR-I18N-001 | Translations en/es/it/fr/pt/de/nl |
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

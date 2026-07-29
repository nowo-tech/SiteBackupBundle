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
| FR-SETUP-001 | Wizard steps, detectors, progress storage |
| FR-TWIG-001 | Twig namespace `NowoSiteBackupBundle` with app overrides first |
| FR-TWIG-002 | Twig functions for restore progress |
| FR-I18N-001 | Translations en/es/it/fr/pt/de/nl |
| FR-EVT-001 | Domain events for backup/restore/setup lifecycle |
| FR-STORE-001 | Filesystem history + progress storage |
| FR-SVC-001 | `SiteBackupManager` façade |
| FR-MODEL-001 | Artifact / progress / history models |
| FR-ATTR-001 | `#[ExcludeFromRestore]` |
| FR-DI-001 | `services.yaml` / routes attribute loader |
| FR-MISC-001 | Other production sources listed in inventory |

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-INV-001 | `code-inventory.md` lists 100% of `src/` production files |
| SC-TEST-001 | PHPUnit Lines coverage ≥ 99% (`make coverage-check`) |
| SC-SEC-001 | Panel denied without hash; CSRF required for POSTs |
| SC-QA-001 | `make phpstan` / `cs-check` clean |

## Non-goals

- Automatic live SQL import during HTTP restore
- Multi-tenant CFG profiles (REQ-CFG-001 N/A)
- Offsite object-storage adapters (pluggable later)

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
- [code-inventory.md](code-inventory.md)
- [SECURITY.md](../../docs/SECURITY.md)

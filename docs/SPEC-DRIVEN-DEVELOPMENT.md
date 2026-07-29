# Spec-driven development

## Table of contents

- [Layers in sync](#layers-in-sync)
- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (selected)](#requirement-identifiers-selected)
- [Contributor workflow](#contributor-workflow)
- [Relationship to Engram](#relationship-to-engram)
- [See also](#see-also)

## Layers in sync

1. **Product behaviour** — integral backup, integrity verify, safe restore + loading UI, and **setup wizard** for cold DB / post-restore bootstrap. Documented in [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), and [SETUP-WIZARD.md](SETUP-WIZARD.md).
2. **Traceability anchors** — `REQ-*` in Makefiles / demos (REQ-CS-005, REQ-DEMO-010, REQ-RUNTIME-001, REQ-MAKE-001).
3. **GitHub Spec Kit baseline** — when present under `specs/001-baseline/`; see [SPEC-KIT.md](SPEC-KIT.md).

## User stories

| ID | Intent | Docs |
| --- | --- | --- |
| US-01 | Create an integral site backup with checksums | USAGE, CONFIGURATION |
| US-02 | Verify archive integrity before restore | USAGE |
| US-03 | Restore from backup without serving a broken site | USAGE |
| US-04 | Show a loading/progress UI while restore runs | USAGE |
| US-05 | Manage backups via Twig panel with optional password | USAGE, SECURITY |
| US-06 | Exclude health/API/panel paths during restore | CONFIGURATION |
| US-07 | Detect missing/empty DB and open a setup wizard | SETUP-WIZARD, `001-baseline` US-4 |
| US-08 | Create DB, cache clear, schema/migrations, minimal SQL | SETUP-WIZARD |
| US-09 | Run idempotent data-load commands from the wizard | SETUP-WIZARD |
| US-10 | Create initial super-admin + optional sample data | SETUP-WIZARD |
| US-11 | Post-restore profile: import dump then finish bootstrap | SETUP-WIZARD |
| US-12 | Durable setup progress (filesystem / doctrine / chain) + incomplete gate | SETUP-WIZARD, `001-baseline` US-5 |
| US-13 | Configurable `setup.path_prefix` (default `/_setup`) | SETUP-WIZARD, CONFIGURATION, `001-baseline` US-6 |

## Functional scope

**In scope:** backup archiver, MANIFEST integrity, restore orchestrator, restore request subscriber, **setup detectors (including incomplete progress) + step pipeline + wizard UI**, filesystem **and optional Doctrine/chain** setup progress, password gate, Twig panel, CLI, overrideable templates/translations, configurable `/_setup` path prefix.

**Non-goals:** multi-tenant profiles for backup state (REQ-CFG-001 N/A for backups; setup uses named **wizard profiles**), shipping a concrete User entity, free-form shell from the browser, coupling to a single SecurityBundle setup, shipping FrankenPHP as a runtime dependency.

## Validating the functional spec

```bash
make qa
make phpstan
make test
```

## Requirement identifiers (selected)

| ID | Meaning | Location |
| --- | --- | --- |
| REQ-CS-005 | phpstan-frankenphp require-dev + rulesets | `composer.json`, `phpstan.neon.dist` |
| REQ-MAKE-001 | Root Makefile + ensure-up | `Makefile` |
| REQ-RUNTIME-001 | `process_timeout` for tar/dump/setup console | Configuration, SETUP-WIZARD |
| REQ-TWIG-001/002 | Overrides + `NowoSiteBackupBundle` NS | `TwigPathsPass`, views |
| REQ-I18N-002/003 | en+es + domain | `Resources/translations/` |
| REQ-DEMO-002/010 | FrankenPHP demos + worker default | `demo/`, `docs/DEMO-FRANKENPHP.md` |

## Contributor workflow

Clarify → implement with tests → update docs/config → `make release-check`.

## Relationship to Engram

See [ENGRAM.md](ENGRAM.md) for AI memory; this file owns product behaviour and REQ traceability.

## See also

[USAGE](USAGE.md) · [CONFIGURATION](CONFIGURATION.md) · [SETUP-WIZARD](SETUP-WIZARD.md) · [CONTRIBUTING](CONTRIBUTING.md) · [RELEASE](RELEASE.md) · [SPEC-KIT](SPEC-KIT.md)

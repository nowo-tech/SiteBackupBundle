# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-26

First stable release of **Site Backup Bundle**.

### Added

#### Backup & restore

- Integral site backup (`.tar.gz` + SHA-256 `MANIFEST.json` + `.meta.json` sidecar).
- Configurable `backup.include_paths` / `exclude_patterns` and optional `database_dump_command`.
- Safe restore orchestrator: validate → extract to staging → apply with protected paths (`var/site-backup/`, `.env.local`, …).
- Public **restore loading page** (HTTP **503**) with progress polling (`/_site_backup/progress.json`).
- Admin panel at `/_site_backup` (password gate + CSRF): create / verify / restore / delete / history.
- CLI: `nowo:site-backup:create`, `list`, `verify`, `restore`, `hash-password`.
- Twig helpers `nowo_site_backup_is_restoring()` / `nowo_site_backup_progress()`.
- Domain events: `BackupCreatedEvent`, `BackupDeletedEvent`, `RestoreStartedEvent`, `RestoreCompletedEvent`, `RestoreFailedEvent`.
- Attribute `#[ExcludeFromRestore]` and configurable path / route / IP exclusions.

#### Setup wizard (cold start & post-restore)

- Site gate: restore loading → `/_setup` wizard → normal app.
- Detectors: marker files (`setup.required` / `setup.done`), optional Doctrine connect / empty-schema.
- Declarative profiles (`fresh_install`, `post_restore`, `minimal`) and step types: `requirements`, `database_url`, `database_create`, `cache_clear`, `migrations`, `schema_update`, `sql_file`, `console`, `admin_user`, `sample_data`, `marker`.
- Pluggable `AdminUserProvisionerInterface` (apps bind their User entity).
- After restore, marks `setup.required` with profile `post_restore` when enabled.
- CLI: `nowo:site-backup:setup`, `setup-status`, `setup-reset`.
- Setup events: `SetupStartedEvent`, `SetupStepCompletedEvent`, `SetupStepFailedEvent`, `SetupCompletedEvent`.
- Docs: [SETUP-WIZARD.md](SETUP-WIZARD.md).

#### Packaging & quality

- Symfony Flex recipe under `.symfony/recipe/`.
- FrankenPHP demo (`demo/symfony8`, default port **8056**, `FRANKENPHP_MODE=worker`).
- Spec Kit baseline under `specs/001-baseline/`.
- Translations **en / es / it / fr / pt / de / nl** (`NowoSiteBackupBundle`).
- QA: PHPUnit, PHP-CS-Fixer, PHPStan (+ FrankenPHP rulesets), Rector, Docker root compose.

### Compatibility

- PHP `>=8.2`, `<8.6` (Symfony **8.x** requires PHP **8.4+**)
- Symfony `^7.0 || ^8.0` (CI / mandatory minors: **7.4**, **8.0**, **8.1**)
- System `tar` required for archive create/extract

[Unreleased]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.0.0

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.12.0] - 2026-08-15

### Added
- **Durable setup done** (`003-durable-done-cold-start`): `DurableSetupDoneStoreInterface` + `NullDurableSetupDoneStore` (default alias). Optional `SetupDbDoneGuard` heals ephemeral markers/progress when the host store says complete. `SetupDbDoneRedirectSubscriber` (priority 3) closes the wizard when `setup.durable_done.enabled: true`.
- **Cold-start schema gate**: `ColdStartSchemaGateSubscriber` (priorities 35/34/20) redirects to the setup path when MySQL schema is unreachable; configurable `setup.cold_start.safe_path_prefixes` and `stop_propagation`. `MysqlSchemaExistenceChecker` probes DBAL or PDO credentials.
- `SetupRequestSubscriber` calls `stopPropagation()` after redirecting to the wizard.

### Documentation
- Spec `specs/003-durable-done-cold-start/`; [CONFIGURATION.md](CONFIGURATION.md), [SETUP-WIZARD.md](SETUP-WIZARD.md), [UPGRADING.md](UPGRADING.md).

## [1.11.0] - 2026-08-15

### Added
- **Setup step journal** (`002-setup-step-rows`): optional per-step DBAL table (`setup.progress_steps_table`, default `nowo_site_backup_setup_step`) upserted when `progress_storage` is `doctrine`/`chain` and `progress_step_rows: true` (default). Runtime DDL only — **not** Symfony Migrations — so early wizard steps still work before the host schema exists. Load merges completed step ids from the journal; `latestFinishedStep()` supports ops/status queries.

### Documentation
- [SETUP-WIZARD.md](SETUP-WIZARD.md) / [CONFIGURATION.md](CONFIGURATION.md) / [UPGRADING.md](UPGRADING.md) — cold-start progress storage and per-step journal.
- Spec `specs/002-setup-step-rows/` and baseline FR-SETUP-002 update.

[1.12.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.12.0

[1.11.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.11.0

## [1.10.1] - 2026-08-07

### Documentation
- **SECURITY:** host firewall / `access_control` examples for panel (`/_site_backup`) and setup (`/_setup`).

### Fixed
- **REQ-GIT-001:** removed Cursor co-author trailers from git history (CI `git-hygiene`).

[1.10.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-08-04

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed
- **REQ-UI-001-kit:** Requires **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`). Panel/setup layouts load `asset('css/nowo-ui.css', 'nowo_ui_kit')` and import UiKit macros. Extension seeds `nowo_ui_kit` from panel/setup CSS framework settings when the host has not configured UiKit.

### Documentation
- [INSTALLATION.md](INSTALLATION.md) / [UPGRADING.md](UPGRADING.md) — UiKit, Twig Extra, and Twig-CS-Fixer notes.

[1.10.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.10.0

## [1.9.1] - 2026-08-04

### Fixed
- **CI:** `destroyHarness()` removes history/progress files instead of their `dirname` (`/tmp`), which broke GHA runners.

[1.9.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-08-03

### Added

- REQ-UI-002 panel access control: `security.access_roles` (default `[ROLE_ADMIN]`), optional `security.access_checker`, and `SiteBackupAccessCheckerInterface` (`ConfigurableSiteBackupAccessChecker` / `AllowAllSiteBackupAccessChecker`).
- `security.allow_unauthenticated` (default `false`): when `false` and the panel is enabled, `symfony/security-bundle` is required (boot fails with `LogicException` otherwise).

### Changed

- Ops password gate remains an **additional** layer on top of role / access-checker checks.
- Demo sets `allow_unauthenticated: true` (password gate only; never copy to production).
- Docs / recipe / suggest text updated for the dual-layer security model ([CONFIGURATION.md](CONFIGURATION.md), [USAGE.md](USAGE.md), [SECURITY.md](SECURITY.md), [INSTALLATION.md](INSTALLATION.md)).
- CI: `actions/stale` v11; lock refresh (`rector/rector` 2.6.0, `nowo-tech/phpstan-frankenphp` 1.0.3).

### Compatibility

- PHP `>=8.2`, `<8.6`; Symfony `^7.0 || ^8.0` (CI minors **7.4**, **8.0**, **8.1**).
- Panel with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.8.1] - 2026-08-01

### Documentation

- FrankenPHP demo showcases recent features: host `setup`/`panel.layout_template`, `css_framework`, `setup.locale.in_path: both`, `#[AsSetupNeedDetector]` toggle, and profile `demo_features` (custom tab + checker + `app:demo:seed-catalog`).
- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) / [demo/README.md](../demo/README.md) / [demo/symfony8/README.md](../demo/symfony8/README.md).

## [1.8.0] - 2026-08-01

### Added

- Custom **setup-need detectors** for the site gate: `#[AsSetupNeedDetector(priority: int)]` → tag `nowo.site_backup.setup_need_detector` (same attribute pattern as tab `#[AsSetupTabChecker]`).
- `SetupNeedEvaluator` collects detectors via `tagged_iterator` (built-ins + host apps). OR semantics unchanged.

### Documentation

- Clarify **gate detector** vs wizard tab **`checker:`** / `SetupTabCheckerInterface` ([SETUP-WIZARD.md](SETUP-WIZARD.md), [CONFIGURATION.md](CONFIGURATION.md), [UPGRADING.md](UPGRADING.md)).

## [1.7.0] - 2026-07-31

### Added

- Setup **`locale`** configuration (mirrors AuthKit locale-in-path pattern): `setup.locale.in_path` (`never` \| `always` \| `both`), `.default`, `.enabled`, `.unlocalized` (`serve` \| `redirect`).
- `SetupRouteLoader` (`type: nowo_site_backup_setup`) registers wizard routes with optional `/{_locale}` prefix depending on mode.
- `SetupPathPrefixResolver` resolves the effective setup path prefix for the current request (locale-aware redirects, Twig vars, progress URL).
- `SetupUnlocalizedLocaleRedirectController` redirects bare setup URLs to the canonical `/{_locale}/…` URL when `in_path: both` + `unlocalized: redirect`.
- Enums `LocaleInPathMode` and `UnlocalizedLocaleMode` under `Nowo\SiteBackupBundle\Enum\`.
- DI parameters: `nowo.site_backup.setup.locale.in_path`, `.default`, `.enabled`, `.unlocalized`.
- Localized setup routes are auto-excluded from restore/setup gate patterns.
- `SetupRequestSubscriber` is locale-aware: skips redirect for localized setup paths; redirect target uses `SetupPathPrefixResolver`.

### Changed

- Setup routes are now registered by `SetupRouteLoader` (type `nowo_site_backup_setup`) instead of `#[Route]` attributes on `SetupWizardController`. Default behaviour (`in_path: never`) is fully backward compatible.
- `SetupWizardController` uses `SetupPathPrefixResolver` for all path prefix references (Twig vars, redirects, progress URL).

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md) / [SETUP-WIZARD.md](SETUP-WIZARD.md) / [UPGRADING.md](UPGRADING.md) for locale-in-path.

## [1.6.0] - 2026-07-30

### Added

- Root config **`css_framework`** (default `custom`) with Twig global `nowo_site_backup_css_framework` (REQ-UI-001). Accepted values: `bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`.
- Demo setup/panel layouts expose `nowo-ui-css-*` / `data-css-framework` for host theming hints.

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md) / [USAGE.md](USAGE.md): `css_framework` + host layout example; Twig override freeze guidance.
- [UPGRADING.md](UPGRADING.md) section **To 1.6.0**.

## [1.5.0] - 2026-07-30

### Added

- Panel **`layout_template`** (`panel.layout_template` / `templates.panel_layout`) so hosts brand the admin panel without forking page markup (REQ-UI-001).
- Twig globals `nowo_site_backup_setup_layout_template` and `nowo_site_backup_panel_layout_template` (pages `{% extends %}` those globals).
- Stable **`nowo-ui-*`** CSS hooks on setup and panel chrome/partials for host restyling without forking templates.

### Changed

- Setup pages extend the setup layout **global** (same as panel); prefer `setup.layout_template` / `panel.layout_template` over copying layouts.
- Docs: [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [UPGRADING.md](UPGRADING.md), [SETUP-WIZARD.md](SETUP-WIZARD.md).

## [1.4.0] - 2026-07-30

### Added

- Setup **`layout_template`** (`setup.layout_template` / `templates.setup_layout`): wizard, done, and token pages extend a host layout via block `nowo_site_backup_content`. Default layout: `@NowoSiteBackupBundle/setup/layout.html.twig`.
- Setup **reasons** banner in the vendor wizard (connection failed, empty schema, markers, incomplete progress).
- `_database_form` hides Skip and warns when Doctrine connect detection reports failure.
- Translation keys `setup.reason.*` and `setup.token.*` in all bundle locales.

### Changed

- Setup Twig pages are **content-only** (no standalone DOCTYPE); chrome lives in `setup/layout.html.twig` or the app `layout_template`.
- Docs: prefer `layout_template` over forking `wizard.html.twig` / `done` / `token` ([SETUP-WIZARD.md](SETUP-WIZARD.md), [UPGRADING.md](UPGRADING.md), [USAGE.md](USAGE.md)); inventory 108/108.

## [1.3.2] - 2026-07-30

### Fixed

- Restored Symfony component constraints to `^7.0 || ^8.0` again (CI `code-style-fix` had been committing `composer require …:^7.4` pins).
- CI auto-fix job no longer commits `composer.json` / `composer.lock`; only PHP CS Fixer changes.

### Documentation

- [UPGRADING.md](UPGRADING.md) / [RELEASE.md](RELEASE.md) for 1.3.2.

## [1.3.1] - 2026-07-30

### Added

- Setup partial `setup/_continue_form.html.twig` for generic Continuar on custom tabs (included from the wizard when no `template` is set).

### Fixed

- Restored Symfony component constraints to `^7.0 || ^8.0` (had drifted to `^7.4` only for some packages after a CI sync, breaking Symfony 8 demos).

### Documentation

- Clarified REQ-TWIG-001 / REQ-UI-001: reuse bundle Twig; prefer overrides under `templates/bundles/NowoSiteBackupBundle/`; avoid app-forked `@App` templates for custom tabs ([USAGE.md](USAGE.md), [SETUP-WIZARD.md](SETUP-WIZARD.md), [UPGRADING.md](UPGRADING.md)).
- Inventory refreshed (107/107).

## [1.3.0] - 2026-07-30

### Added

- Setup step **`bootstrap_mode`**: choose **guided** (create admin) or **full database** dump import; answer `sql_import_path`; step filter `when_answer`.
- Default `fresh_install` profile: bootstrap choice → optional SQL import when `full_database` → migrations → `admin_user` (`skip_if_admin_exists`) → marker.
- Profile **`full_database`** for deep-link `?profile=full_database`.
- Starting the wizard marks `setup.required` so the site gate stays on until `setup.done` (FR-SETUP-005).
- Setup **`profiles.*.tabs`**: ordered YAML tabs with optional `checker` (`SetupTabCheckerInterface`), `template`, `runner`, and i18n `label` / `description`; legacy `steps` still work.
- `setup.advance_mode` / per-profile override: `automatic` (default) or `manual` (one auto tab per Continuar); CLI forces automatic.
- `#[AsSetupTabChecker]` autoconfigures checker services (tag `nowo.site_backup.setup_tab_checker`).
- Built-in type **`custom`** for app-owned tab bodies; translation keys `setup.tab.*` / `setup.check.*` in all bundle locales.

### Documentation

- Spec US-7 / US-8 / US-9; [SETUP-WIZARD.md](SETUP-WIZARD.md) / [UPGRADING.md](UPGRADING.md) / [CONFIGURATION.md](CONFIGURATION.md) updated; inventory 106/106.

## [1.2.0] - 2026-07-29

### Added

- Setup progress **Doctrine DBAL** storage + **`chain`** mode (`setup.progress_storage`: `filesystem` \| `doctrine` \| `chain`; load prefers DB so wiping `var/` keeps the current step).
- `IncompleteSetupProgressDetector` (`setup.detectors.incomplete_progress`, default `true`) — site gate while phase is `running` / `waiting_input` / `failed`.
- Progress fields `started_at` / `completed_at` (JSON + DB columns); `setup-status` CLI shows them.

### Fixed

- Restored Symfony component constraints to `^7.0 || ^8.0` on `main` (had drifted to `^7.4` only for some packages after 1.1.0).
- Route loaders use `%nowo.site_backup.panel.path_prefix%` / `%nowo.site_backup.setup.path_prefix%` so config overrides apply to imported routes.

### Documentation

- [SETUP-WIZARD.md](SETUP-WIZARD.md) / [CONFIGURATION.md](CONFIGURATION.md) / [UPGRADING.md](UPGRADING.md) updated for `progress_storage` and incomplete detector.
- Spec baseline amended (US-4–US-6); inventory refreshed (97/97).

## [1.1.0] - 2026-07-29

### Security

- Panel **fail-closed** when `password_protection` is true but `password_hash` is empty (`isMisconfigured()`).
- CSRF on panel/setup POSTs **fail-closed** if no CSRF token manager is available (require `symfony/security-csrf`).

### Added

- `make check-open-prs`, `demo-smoke`; `release-check` gates open PRs.
- `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- Spec Kit `.specify/` + full `code-inventory.md` (94/94); `docs/COVERAGE.md`.
- Release security checklist item for REQ-SEC-004.
- Expanded PHPUnit coverage toward the ≥99% Lines gate.

### Changed

- Coverage gate **≥ 99%** Lines; README reports measured **99.4%**.
- `backup.include_paths: []` or `["."]` now means **entire project** (minus `exclude_patterns`), with clean relative paths (no `./` prefix). Omitting the key still uses the selective defaults.
- Restore page `default_message` default is translation id `restore.page.message` (domain `NowoSiteBackupBundle`).
- Symfony component constraints aligned to `^7.0 || ^8.0` (was partially pinned to `^7.4`).

### Documentation

- [UPGRADING.md](UPGRADING.md) **To 1.1.0**; SECURITY / CONFIGURATION / USAGE updated for fail-closed and include_paths.

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

[Unreleased]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.9.0...HEAD
[1.9.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.8.1...v1.9.0
[1.8.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.3.2...v1.4.0
[1.3.2]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.0.0

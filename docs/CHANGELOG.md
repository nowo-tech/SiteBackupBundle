# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.25.0] - 2026-10-29](#1250---2026-10-29)
- [[1.24.1] - 2026-10-28](#1241---2026-10-28)
- [[1.24.0] - 2026-10-28](#1240---2026-10-28)
- [[1.23.0] - 2026-10-28](#1230---2026-10-28)
- [[1.22.2] - 2026-10-24](#1222---2026-10-24)
- [[1.22.1] - 2026-10-21](#1221---2026-10-21)
- [[1.22.0] - 2026-10-21](#1220---2026-10-21)
- [[1.21.3] - 2026-10-19](#1213---2026-10-19)
- [[1.21.2] - 2026-10-18](#1212---2026-10-18)
- [[1.21.1] - 2026-10-18](#1211---2026-10-18)
- [[1.21.0] - 2026-10-18](#1210---2026-10-18)
- [[1.20.0] - 2026-10-15](#1200---2026-10-15)
- [[1.19.2] - 2026-10-13](#1192---2026-10-13)
- [[1.19.1] - 2026-10-13](#1191---2026-10-13)
- [[1.19.0] - 2026-10-13](#1190---2026-10-13)
- [[1.18.1] - 2026-10-13](#1181---2026-10-13)
- [[1.18.0] - 2026-10-13](#1180---2026-10-13)
- [[1.17.0] - 2026-10-12](#1170---2026-10-12)
- [[1.16.0] - 2026-10-09](#1160---2026-10-09)
- [[1.15.1] - 2026-10-09](#1151---2026-10-09)
- [[1.15.0] - 2026-10-07](#1150---2026-10-07)
- [[1.14.3] - 2026-10-01](#1143---2026-10-01)
- [[1.14.2] - 2026-09-28](#1142---2026-09-28)
- [[1.14.1] - 2026-09-27](#1141---2026-09-27)
- [[1.14.0] - 2026-09-25](#1140---2026-09-25)
- [[1.13.8] - 2026-08-24](#1138---2026-08-24)
- [[1.13.7] - 2026-08-21](#1137---2026-08-21)
- [[1.13.6] - 2026-08-20](#1136---2026-08-20)
- [[1.13.5] - 2026-08-20](#1135---2026-08-20)
- [[1.13.4] - 2026-08-20](#1134---2026-08-20)
- [[1.13.3] - 2026-08-20](#1133---2026-08-20)
- [[1.13.2] - 2026-08-19](#1132---2026-08-19)
- [[1.13.1] - 2026-08-18](#1131---2026-08-18)
- [[1.13.0] - 2026-08-15](#1130---2026-08-15)
- [[1.12.0] - 2026-08-15](#1120---2026-08-15)
- [[1.11.0] - 2026-08-15](#1110---2026-08-15)
- [[1.10.1] - 2026-08-07](#1101---2026-08-07)
- [[1.10.0] - 2026-08-04](#1100---2026-08-04)
- [[1.9.1] - 2026-08-04](#191---2026-08-04)
- [[1.9.0] - 2026-08-03](#190---2026-08-03)
- [[1.8.1] - 2026-08-01](#181---2026-08-01)
- [[1.8.0] - 2026-08-01](#180---2026-08-01)
- [[1.7.0] - 2026-07-31](#170---2026-07-31)
- [[1.6.0] - 2026-07-30](#160---2026-07-30)
- [[1.5.0] - 2026-07-30](#150---2026-07-30)
- [[1.4.0] - 2026-07-30](#140---2026-07-30)
- [[1.3.2] - 2026-07-30](#132---2026-07-30)
- [[1.3.1] - 2026-07-30](#131---2026-07-30)
- [[1.3.0] - 2026-07-30](#130---2026-07-30)
- [[1.2.0] - 2026-07-29](#120---2026-07-29)
- [[1.1.0] - 2026-07-29](#110---2026-07-29)
- [[1.0.0] - 2026-07-26](#100---2026-07-26)

## [Unreleased]

## [1.25.0] - 2026-10-29

SiteBackup: process deadlines that kill the whole tree, PSR-3 logging with secret redaction, injected clock, strict setup profile config See [UPGRADING](UPGRADING.md#to-1250).

### Added

- **Logging (REQ-OBS-001)**: backup create / delete / restore and setup runs log start, success and failure through
  `Psr\Log\LoggerInterface` on the Monolog channel `site_backup` (structured context: action, backup id, profile,
  step). Actors, labels, credentials and the dump command are never logged; error messages are redacted
  (`SecretRedactor`). Panel login attempts are logged (success info, failure notice, throttled warning) without the
  client IP. A misconfigured setup tab `checker:` id is logged as a warning instead of being ignored silently.
- **Configuration docs**: `docs/CONFIGURATION.md` gains a table of contents and the *Profiles (REQ-CFG-001)* and
  *Timeouts (REQ-RUNTIME-001)* sections.

### Changed

- **Subprocess deadlines (REQ-RUNTIME-001)**: `tar`, the database dump, `nowo:site-backup:db-dump` and console setup
  steps run under `process_timeout` as both hard and idle timeout. For `tar`, the dump shell and console steps
  (`ProcessDeadline`) the whole process tree is killed on expiry (descendants first, so `mysqldump` under the dump
  shell does not survive as an orphan). A timed-out dump
  reports `Database dump timed out after N seconds (process_timeout).` without repeating the command line; `db-dump`
  prints a timeout message and exits 1. A failed or timed-out `tar` no longer leaves a partial archive in
  `storage_dir`.
- **Demo**: both FrankenPHP Caddyfiles set the timeout hierarchy (`process_timeout` 300 s < PHP
  `max_execution_time` 360 s < Caddy `write` 400 s; `max_wait_time` 30 s), documented in `docs/DEMO-FRANKENPHP.md`.
- **Clock (REQ-DI-001)**: time-dependent services (`BackupArchiver`, `SiteBackupManager`, `RestoreOrchestrator`,
  `SetupOrchestrator`, `SetupDbDoneGuard`, `SetupMarkerManager`, `WorkerRestartSignal`, DBAL setup storages) take an optional `Psr\Clock\ClockInterface` (framework `clock` service; default `NativeClock`).
  `symfony/clock` and `psr/log` are now explicit dependencies.
- **Attribute wiring (REQ-SF-003)**: built-in setup-need detectors use `#[AsSetupNeedDetector(priority: …)]`, the
  time-limit listener `#[AsEventListener]`, the controllers `#[AsController]`, and the event subscribers are tagged by
  autoconfigure — no manual `addTag()` left except the two request listeners whose priority comes from configuration.

### Fixed

- **`setup.default_profile` is validated (REQ-CFG-001)**: a name that is not a key of `setup.profiles` now fails the
  container compile with the path and the defined profiles, instead of a runtime "Unknown setup profile" error in the
  wizard.

### Documentation

- Spec Kit baseline refreshed (`specs/001-baseline/`): inventory **164/164** production files (adds
  `CsrfProtectionPass`, `SetupTokenGate`, `ProcessDeadline`, `SecretRedactor`), domain-grouped functional
  requirements, last audited 2026-10-29.

## [1.24.1] - 2026-10-28

Dependency update

### Changed

- **Dependencies updated** within their constraints: NowoUiKitBundle 2.17.1 in the development lock, PHPStan 2.3.3, Rector 2.8.1. No change in the bundle's behaviour or configuration.

## [1.24.0] - 2026-10-28

Tab icons (NowoUiKitBundle 2.17)

### Added

- **Tab icons**: the panel tabs (Backups, History) carry an icon (UiKit 2.17 `icon`, Tabler names), so they follow the
  host's tab style (`data-tab-style` on `<html>`: text, icon and text, or icon only). Without that attribute the tabs
  look as before. Icons need NowoUiKitBundle **2.17** or later; older 2.x versions ignore them.

## [1.23.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-1230).

### Security

- CSRF fails closed also when only form CSRF is disabled: new `CsrfProtectionPass` makes the container refuse to compile while the panel (`web_ui.enabled`) or the setup wizard (`setup.enabled`) is registered and `security.csrf.token_manager` or `form.type_extension.csrf` is missing, or `framework.form.csrf_protection` is false. The controllers already denied mutations without a token manager, but with `framework.form.csrf_protection: false` the token manager exists while the panel / wizard forms skipped token validation, so backup / restore / delete and setup steps accepted tokenless cross-site POSTs. See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#threat-model).

### Added

- Developer tooling: `make check-no-raw-html-form` fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Demo: the force-setup flag buttons (home page and guest layout gate escape) render through a Symfony Form Type (`CsrfOnlyType`, CSRF token id `demo_force_setup`) with `form_start` / child loop / `form_end`, and the POST handlers validate with `handleRequest` + `isValid()` instead of a hand-rolled `_csrf_token` input (REQ-TWIG-005).

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain with `ensure-up` first (then the Cursor co-author, open-PR and Twig `extra` gates, composer-sync, cs-fix, cs-check, rector-dry, phpstan, igor, translations, coverage-check and the demo release-check).
- Developer tooling: `composer test-coverage` runs PHPUnit with `memory_limit=1G` so the HTML coverage report no longer runs out of memory at the 128M default (`make test-coverage` / `coverage-check` read the % again).

## [1.22.2] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-1222).

### Changed

- Setup wizard partials (`setup/_admin_form`, `_bootstrap_form`, `_database_form`, `_continue_form`, `_sample_form`) render the form body through the child loop (`{% for child in form %}` + `not child.rendered` + `form_row(child)`) instead of `form_row(form.<field>)` + `form_rest`; `sql_import_path` and `database_url` keep their custom options and render first (REQ-TWIG-003 rule 3).

## [1.22.1] - 2026-10-21

Setup-token gate WebP source is opt-in (webp: true or a path), so PNG-only hosts get no broken image. See [UPGRADING](UPGRADING.md#to-1221).

### Fixed

- `setup.token_gate.illustration.webp` is opt-in: `null` (default) emits no WebP `<source>` (1.22.0 derived `<src>.webp` without knowing it exists, so PNG/JPEG-only hosts showed a broken image); `true` derives the sibling, a string is the WebP path.

## [1.22.0] - 2026-10-21

Setup-token gate illustration and configurable how-to steps (hosts drop their copied token page), dependency refresh, php-cs-fixer ParallelConfig(2). See [UPGRADING](UPGRADING.md#to-1220).

### Added

- Setup-token gate presentation options (`setup.token_gate`, 1.22): an optional **illustration** above the auth panel
  (`illustration`: asset path shorthand or `{ src, webp, width, height, alt }`, rendered as `<picture>` with a WebP
  source and a PNG/JPEG fallback `<img>` with intrinsic width/height, sized by a CSP-nonce `<style>`), configurable
  **how-to steps** of the `<details>` disclosure (`howto_steps`: translation keys or translated texts, `[]` hides it) and
  their `translation_domain`. New `View\SetupTokenGate` (immutable, worker safe) passed to `setup/token.html.twig` as
  `tokenGate`; new translation key `setup.token.illustration_alt` (7 locales). Hosts that copied the token page only to
  add art and copy can delete it (see UPGRADING).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).

### Documentation

- Documentation: wave-1 REQ compliance — canonical issue templates, CODEOWNERS header, README documentation list order (PSR/Templates under Additional documentation), CONTRIBUTING Cursor Agent queue settings (REQ-IDE-006), DEMO-FRANKENPHP dev/production start, timeout hierarchy and troubleshooting, commented demo `.env.example`, Spec Kit manual (layers, incremental features), categorized Spec Kit inventory (160/160), tables of contents on long docs.

## [1.21.3] - 2026-10-19

Docs: per-page overrides extend @!NowoSiteBackup (the documented namespace did not resolve). See [UPGRADING](UPGRADING.md#to-1213).

### Fixed

- **Docs:** per-page overrides (`web_ui.templates.<page>`) extend the bundle original with `{% extends '@!NowoSiteBackup/…' %}`. USAGE, TEMPLATES and the `web_ui.templates` config info showed `@!NowoSiteBackupBundle/…`, which is not a registered Twig namespace (Symfony names a bundle's `!` namespace without the `Bundle` suffix; the `@NowoSiteBackupBundle` alias added by the bundle has no `!` variant), so a copied override failed with "There are no registered paths for namespace". A kernel test now resolves the documented namespace.

## [1.21.2] - 2026-10-18

Setup locales accept %parameters% and env vars; int env placeholders for the cold-start MySQL port are kept. See [UPGRADING](UPGRADING.md#to-1212).

### Fixed

- `setup.locale.enabled` given as a container parameter or env var (`'%kernel.enabled_locales%'`, `'%app.locales%'`,
  `'%env(json:APP_LOCALES)%'`) no longer breaks the container build ("Expected array, but got string"): `prepend()`
  processed the whole raw host config, where the parameter is still a string (and `kernel.enabled_locales` is not
  defined yet), and the node only accepted a literal list. `prepend()` now processes only `css_framework` and
  `panel.extra_classes`, the node accepts a list or a parameter / env placeholder, and an env var is read at runtime
  by the setup routes, gates, exclusion matcher and time-limit subscriber (the derived `/{locale}{prefix}` paths are
  built by factories). See [CONFIGURATION](CONFIGURATION.md#setup-locales-from-a-parameter-or-env-var).
- `setup.cold_start.mysql_port` given as an env var (`'%env(int:DATABASE_PORT)%'`) was silently replaced by `3306`;
  the placeholder is now kept and resolved at runtime.

## [1.21.1] - 2026-10-18

Requires UiKit ^2.2 (wizard on the guest layout, progress partial, scripts partial); setup placeholders translated once. See [UPGRADING](UPGRADING.md#to-1211).

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.2** (default-theme polish: table header hierarchy, striped rows, subtle
  badges, solid destructive confirm button with a close button). See [UPGRADING](UPGRADING.md#unreleased).
- Setup wizard and "done" page: `WizardPage` uses UiKit 2.2 `guest: true` (layout `nowo_ui_kit.layouts.guest`)
  instead of resolving the guest layout in the bundle; `web_ui.guest_layout_template` is still passed as the layout
  override. `SiteBackupView::guestLayout()` is deprecated (kept for host-built wizard view models).
- Setup wizard progress bar renders through the UiKit `progress` partial (slots `progress` / `progress_meter`,
  `data-testid="nowo-sbb-setup-progress"`) instead of the bundle's own `<progress class="nowo-sbb-progress">`; the
  step message is the progress label.
- `_messages.html.twig` passes the last POST result to the UiKit `flashes` partial through `flash_messages` (the
  2.0 `flashes` input is deprecated in UiKit 2.2).
- Demo: the host layout prints the UiKit runtimes through the UiKit `scripts` partial (the hand-kept list lacked
  `modal`, `tabs` and `theme`).
- README / docs screenshots re-captured on UiKit 2.2 (`docs/images/demo/*.webp`, REQ-DEMO-013).

### Fixed

- **Missing / double translations (profiler):** the example placeholders of the setup forms (`DatabaseUrlType`,
  `_database_form`, `_bootstrap_form`) were literals looked up in the bundle domain by the form theme; they are now
  catalogue keys `setup.database.url_placeholder` / `setup.bootstrap.sql_placeholder` (7 locales). The wizard
  translated the controller error (already translated), literal step output ("Migrations applied.") and exception
  messages again: only key-shaped progress messages / errors are looked up now, the rest is printed as given. A
  contract test renders every page with a `DataCollectorTranslator` and asserts no missing message.

## [1.21.0] - 2026-10-18

Panel, sign-in, setup wizard and confirm pages on the UiKit 2.1 standard page contract (REQ-UI-003): list, form, confirm, auth and wizard scaffolds, type-to-confirm restore, no-JS fallbacks; deprecated `panel.*` / `setup.*` / `templates.*` keys mapped to `web_ui.*`. See [UPGRADING](UPGRADING.md#to-1210).

### Added

- **UiKit 2.1 page contract (REQ-UI-003).** The panel and setup pages extend UiKit scaffolds and are themed once in
  `nowo_ui_kit` for every Nowo bundle: archives and history → `page/list`, new create-backup page → `page/form`
  (`GET {path_prefix}/create`), new no-JS restore / delete confirmations → `page/confirm`
  (`GET {path_prefix}/restore/{id}`, `GET {path_prefix}/delete/{id}`), panel sign-in and setup token → `page/auth`
  (guest layout), setup wizard and done → `page/wizard` (guest layout, final "Done" step). View models are built by
  `View\PanelPages` / `View\SetupPages` and rendered by `View\SiteBackupView` (no database service: the wizard renders
  before the app has a schema or users). See [TEMPLATES.md](TEMPLATES.md).
- **Type-to-confirm restore.** Restoring an archive asks to type its id in the UiKit confirm dialog (and on the no-JS
  confirm page); delete uses the UiKit confirm dialog. Both keep their CSRF forms.
- Canonical keys `web_ui.enabled`, `web_ui.path_prefix`, `web_ui.layout_template` (admin pages, null = UiKit),
  `web_ui.guest_layout_template` (sign-in + setup, null = UiKit) and `web_ui.templates.<page>`.
- Panel area navigation (Backups | History) as `PageHeader::tabs`, published to the UiKit area-nav registry as area
  `site_backup` (`SiteBackupView` implements `AreaNavProviderInterface`).
- Contract tests on UiKit `PageContractTestCase` (English and German catalogues), controller flow tests (panel, and the
  setup wizard without any database), demo Playwright e2e + WebP screenshots (`make -C demo/symfony8 test-e2e`,
  `demo-screenshots`).

### Changed

- Requires `nowo-tech/ui-kit-bundle` `^2.1`.
- Pages render inside `nowo_ui_kit.layouts.admin` / `.guest` instead of the bundle's standalone layouts; restore status
  and POST results render through the UiKit flash slots; row actions are UiKit `Action`s (`nowo_ui_kit.row_actions_display`).
- Form types carry their labels / help / placeholders (`translation_domain: NowoSiteBackupBundle`).
- Without the deprecated root `css_framework`, the bundle no longer seeds `nowo_ui_kit.css_framework: custom` /
  `icon_set` (UiKit defaults apply).
- A failed create (CSRF / archiver error) re-renders the create form with HTTP 422.

### Deprecated

- `panel.enabled`, `panel.path_prefix`, `panel.layout_template`, `setup.layout_template`, `templates.*` (aliases of the
  `web_ui` keys; `templates.setup_admin|setup_sample|setup_database` have no effect), root `css_framework`,
  `panel.row_actions_display` (use `nowo_ui_kit`), `panel.extra_classes` (bridged into
  `nowo_ui_kit.theme.bundles.NowoSiteBackupBundle.classes`), the Twig globals `nowo_site_backup_*_layout_template`,
  `nowo_site_backup_css_framework`, `nowo_site_backup_row_actions_display`, `nowo_site_backup_panel_classes`, and the
  1.x layout bridges `panel/_legacy_layout.html.twig` / `setup/_legacy_layout.html.twig`. Removed in 2.0.

### Removed

- Templates `panel/layout.html.twig`, `panel/_flashes.html.twig`, `setup/layout.html.twig`, `setup/_styles.html.twig`,
  `setup/admin.html.twig`, `setup/database.html.twig`, `setup/sample_data.html.twig`, the `data-nowo-confirm` listener
  and the inline `<style>` blocks of the panel / setup pages (the restore loading page keeps its own).

### Fixed

- The setup and restore gates no longer redirect the Symfony toolbar / profiler (`/_wdt/`, `/_profiler/`): the toolbar
  of every gated page embedded the wizard page.
- Demo: the panel password hash (`password`) was invalid.

## [1.20.0] - 2026-10-15

### Added

- **Production guard: hash shape, extra secrets, DSN credentials.** `security_guard.check_password_hash_shape` (default `true`) refuses a panel `password_hash` that `password_get_info()` does not recognise — typically a bcrypt hash cut by Docker Compose `$` interpolation, which used to lock the panel silently. `security_guard.extra_secrets` checks more app secrets (min length, documented placeholders, optional `required`); `security_guard.dsn_credentials` requires a password (userinfo, `?auth=` or `password_fallback`) and refuses documented default credentials (`guest:guest`, …) in Redis / AMQP DSNs. Apps can drop their host copies of these checks (Nowo REQ-SETUP-004 / REQ-ENV-007).

## [1.19.2] - 2026-10-13

### Fixed

- `SetupRouteLoader` threw `SiteBackup setup routes already loaded.` when the router was rebuilt in the same process (FrankenPHP worker, `bin/console` / dev server `--watch` reloading the route cache), because the loader service remembered its first `load()` call. The loader is now stateless: every call returns a fresh, identical collection of setup routes.
- `RestoreOrchestrator` kept its heartbeat throttle timestamp across restores in a long-lived worker. It now implements `ResetInterface` and starts each restore with a fresh throttle. `DoctrineDbalSetupProgressStorage::reset()` also resets its step journal's schema memo.

### Changed

- `PanelRouteLoader`: behaviour-neutral Rector rewrite (`__DIR__ . '/../…'` path) and an Igor note on the locally imported collection.

### Dependencies

- Dev lock refreshed by `composer-sync` (`nowo-tech/ui-kit-bundle` 1.16.0, `nowo-tech/phpstan-frankenphp` 1.3.0).

## [1.19.1] - 2026-10-13

### Fixed

- A very large restore still running could be judged abandoned by `restore.stale_after_seconds` (default 2 × `process_timeout`), because archive hashing, tar extraction and file apply wrote no progress: a second restore could then start on top of it. These steps now write progress heartbeats (refresh `updated_at`) at most every 5 s, or every third of `stale_after_seconds` when that is smaller, so staleness is measured from the last heartbeat. `BackupArchiver::verifyIntegrity()` / `extractTo()` accept an optional heartbeat callable (archive hashed in 8 MiB chunks, tar polled while running); `RestoreOrchestrator` accepts an optional `clock` closure.

## [1.19.0] - 2026-10-13

### Changed

- **Requirements:** Symfony floor raised to `^7.4 || ^8.0` for every `symfony/*` dependency (`symfony/rate-limiter` already needed `^7.3`, and the CI "7.0" cell actually installed the latest 7.x). CI matrix drops the `7.0` cell; README / INSTALLATION aligned.
- docs/CONFIGURATION.md documents every configuration key (including setup profile step / tab keys).

### Added

- `restore.stale_after_seconds` (default `null` = 2 × `process_timeout`, `0` = never): an active restore whose progress was not updated for that long is treated as abandoned. `RestoreOrchestrator::isRestoreStale()`.
- `RestoreOrchestrator::MESSAGE_*` translation ids (`restore.message.*`) translated in en/es/it/fr/pt/de/nl.

### Fixed

- Backups no longer nest previous archives or runtime state when `backup.storage_dir`, `restore.progress_file`, `storage.history_file` or `setup.progress_file` point inside the project but outside `var/site-backup/`: those paths (resolved relative to the project dir) are excluded from archives, and restores never overwrite them (`BackupArchiver` `$runtimeStatePaths`, `RuntimeStatePaths`).
- A worker that died mid-restore left `active: true` and the site answered 503 forever; stale progress is now ignored by the site gate, `progress.json` reports `active: false`, and a new restore may start (logging the abandoned one).
- `panel.enabled: false` no longer leaves the panel routes registered against the removed controller (HTTP 500): `routes.yaml` imports the panel through `PanelRouteLoader` (type `nowo_site_backup_panel`), which registers no route when the panel is disabled (404).
- Restore loading page reloads as soon as the restore is no longer active (completed, failed, cleared, abandoned), not only on `completed`.
- Restore progress messages were English literals, so `|trans` did nothing and `default_message` was never shown: the orchestrator now writes `restore.message.*` translation ids; the loading page renders `default_message` as its lead text and the translated step below; panel `progress.json` returns the translated message.
- Locale codes are `preg_quote`d when building the `/{locale}/_setup` exclusion regex and the setup route `_locale` requirement.

## [1.18.1] - 2026-10-13

### Security

- **`POST /_setup/api/advance` closed after setup**: it answers 404 once setup is completed (same gate as the wizard page), so `{"profile":"minimal"}` can no longer create an extra admin and `{"profile":"full_database"}` can no longer re-import `last-restore-dump.sql`. The payload `profile` is only honoured before a run started; afterwards the stored profile is pinned. Responses no longer include `answers`.
- **`GET /_setup/api/progress`** requires the setup token (when configured) and no longer returns `answers` (admin email, DATABASE_URL, SQL path).
- **DATABASE_URL step**: the value is always written single-quoted to `.env.local` (Symfony Dotenv no longer expands `$VAR` / `$(cmd)` or mangles passwords containing `$` / `\`), the existing line is replaced literally (no regex backreferences), and URLs containing `'`, line breaks or control characters are rejected (percent-encode them).
- **`sql_import_path`** (bootstrap mode / `sql_file` step) is confined to existing `.sql` files under `var/site-backup/` (real path; absolute paths elsewhere, `../` and symlink escapes are rejected). SQL import errors report the file name and SQLSTATE only, never the dump contents.
- **Restore `progress.json`**: callers that are not authorised for the panel get `active` / `phase` / `percent` / `message` only (no log, error or backup id).
- Backup archives are `chmod 0600`; `var/site-backup/last-restore-dump.sql` is `0600` and deleted after the setup `sql_file` step imports it.
- Docs: `setup.setup_token` is mandatory on internet-facing hosts (SECURITY.md, CONFIGURATION.md).

## [1.18.0] - 2026-10-13

### Added

- **Translated panel, setup and restore screens** (spec 006): every user-facing string goes through the `NowoSiteBackupBundle` domain in all 7 locales (`en`, `es`, `fr`, `it`, `pt`, `de`, `nl`) — panel login (heading, notices, label, button, "Invalid password."), dashboard (sections, columns, actions, confirms, logout, phase label), history, flash / error messages (`panel.flash.*`, `panel.error.*`, 403 "Access denied."), setup page titles (`setup.page_title.*`), admin form labels, setup controller errors (`setup.error.*`), token gate and restore phase (`restore.phase.*`). 69 new keys; `<html lang>` follows the request locale. `SetupWizardController` takes an optional `$translator` (last argument, wired to `translator`); both controllers fall back to English without one.
- **Setup token gate with a paste form**: `setup/token.html.twig` renders `tokenForm` (`Form\Setup\SetupTokenType`, GET, field `token`, no CSRF) plus a collapsible how-to (`setup.setup_token`, `?token=…`, `X-Setup-Token`). Templates without `tokenForm` keep the text-only page.
- **`--nowo-ui-*` token theming**: panel layout CSS, the new `setup/_styles.html.twig` partial and the setup layout read UiKit tokens with inline fallbacks; the setup dark palette is the overridable block `nowo_site_backup_theme_tokens`. Legacy `--nowo-sbb-*` variables are aliases of the tokens.
- **Chrome blocks** so hosts stop forking pages: panel layout (`nowo_site_backup_html_lang`, `_html_class`, `_body_class`, `_panel_styles`, `_panel_brand`, `_panel_nav`), login (`nowo_site_backup_login_eyebrow`, `_heading`, `_intro`, `_notice`, `_form`, `_after`), index (`nowo_site_backup_create_section`, `_restore_section`, `_archives_section`, `_row_actions`, `_logout`, `_confirm_script`), history (`nowo_site_backup_history_heading`, `_history_row`), setup layout (`nowo_site_backup_theme_tokens`, `nowo_site_backup_logo`, lang / class blocks), token (`nowo_site_backup_token_art`, `_title`, `_lead`, `_form`, `_howto`).
- **`panel.row_actions_display`** (`text` default \| `icon` \| `icon_text` \| `kit`) and **`panel.extra_classes`** (`card`, `table`, `button`, `input`); Twig globals `nowo_site_backup_row_actions_display` / `nowo_site_backup_panel_classes`.
- Reusable partials for host shells: `panel/_flashes.html.twig` (panel `error` / `success`) and `setup/_styles.html.twig`.
- Tests: functional render tests with real Twig + Form + Translation bridges (`tests/Unit/Twig/TranslatedTemplatesRenderTest.php`), catalogue test (deep key parity + every literal key used by templates / controllers exists).

### Changed

- Panel templates use the UiKit macros for `css_framework` (`ui.btn`, `ui.input`, `ui.table_wrap`, `ui.table`, `ui.flash`, `ui.empty`, `ui.row_actions`, `ui.action`); legacy classes (`nowo-ui-btn`, `card`, `inline`, `danger`, `nowo-ui-flash--err`, …) are kept. Tables are wrapped in `nowo-ui-table-wrap`; "Clear status" / "Logout" use the secondary button style.
- `.scripts/validate-translations.php` (`make validate-translations`) checks deep key parity, not only top-level sections.
- Translations: replaced English copies in `fr`, `it`, `pt`, `de`, `nl` (bootstrap, reasons, token) and outdated `db_connection_hint` / `db_connection_form` texts.
- **`nowo-tech/ui-kit-bundle` constraint `^1.6`** (row-action kinds / icons used by the panel).

### Fixed

- **Panel row / logout / clear forms posted to the wrong URL**: `PanelActionType` used Symfony's reserved `action` option for the hidden field value, so `<form action="verify">` (also `restore`, `delete`, `logout`, `clear_restore`) was rendered and the browser posted outside the panel. The value is now the `panel_action` option; forms post back to the panel URL.

## [1.17.0] - 2026-10-12

### Added

- **`security.login_throttle`** (on by default: `enabled: true`, `limit: 5`, `interval: '15 minutes'`, `policy: fixed_window`, `limiter: null`, `cache_pool: null`): per-client-IP rate limit of panel password attempts. `Security\ThrottledSiteBackupAccessGate` (service `nowo_site_backup.access_gate`, target of the `SiteBackupAccessGateInterface` alias) wraps the default password gate or the custom `security.access_gate`; it only throttles while `isProtectionEnabled()` is true, resets the limiter on success and throws `TooManyRequestsHttpException` with `Retry-After` when exhausted. The built-in limiter is `nowo_site_backup.login_limiter` (`RateLimiterFactory`, `CacheStorage` on `cache_pool` ?? `cache.rate_limiter` ?? `cache.app`, optional `lock.factory`); `limiter` plugs a host `RateLimiterFactoryInterface` service instead. `DependencyInjection\Compiler\LoginThrottleStoragePass` fails at compile time with an actionable message when no pool exists.
- Panel login renders the throttle as the login page with **HTTP 429**, `Retry-After` and the translated `panel.login.too_many_attempts` message (all 7 locales; template variable `throttled`). Also applies to host decorators that throw `TooManyRequestsHttpException`.
- **Session fixation protection:** after a successful panel login the session id is rotated (`$session->migrate(true)`), also when `login_throttle.enabled: false` or with a custom gate.

### Changed

- **`symfony/rate-limiter` (`^7.3 || ^8.0`) is now a hard requirement** (needed by the default-on throttle; `RateLimiterFactoryInterface` exists since 7.3).
- The `SiteBackupAccessGateInterface` alias now points to `nowo_site_backup.access_gate` instead of `PasswordSiteBackupAccessGate` / the custom gate id (both remain registered and are injected as the inner gate).
- `SiteBackupPanelController` takes an optional `$translator` (last argument).
- Igor: annotated four pre-existing false positives (persistent I/O / per-request locale) with `@igor-ignore`.
- Tooling: Rector skips `DependencyInjection/Configuration.php` (its scope analysis never finished, so `rector-dry` timed out); two Rector fixes applied (`Command::INVALID`, redundant string cast). Lock refresh: UiKitBundle 1.12.0. Demo `config/reference.php` regenerated.

## [1.16.0] - 2026-10-09

### Added

- **`setup.worker_memo`** (`schema_probe_ttl`, default `60`; `progress_ddl_ttl`, default `3600`): per-worker TTL memos (`Setup\Memo\WorkerTtlMemo`, deliberately not `ResetInterface`) so FrankenPHP workers stop probing `information_schema` and running `CREATE TABLE IF NOT EXISTS` on every request. `MemoizedSchemaExistenceChecker` wraps the cold-start `MysqlSchemaExistenceChecker`; `DoctrineConnectDetector` / `DoctrineSchemaEmptyDetector` reuse a healthy answer; `DoctrineDbalSetupProgressStorage` / `DoctrineDbalSetupStepJournal` remember the DDL across `kernel.reset`. Only positive answers are cached; a dropped table is still re-created on the first failing query. `0` restores the previous behaviour.
- **`bump_time_limit`** (default `false`) + **`bump_time_limit_seconds`** (default `null` = `process_timeout`; `0` = unlimited): `LongRequestTimeLimitSubscriber` calls `set_time_limit()` on main requests under the setup prefix (incl. `/{locale}` variants) and the panel prefix, so migrate / dump / restore subprocesses are not killed mid-pipe by a short prod `max_execution_time`.
- **`nowo:site-backup:db-dump`**: built-in `backup.database_dump_command` — `mysqldump` from `DATABASE_URL` (or `--url`), password via `MYSQL_PWD` (never argv), `--single-transaction --no-tablespaces`, no `--routines` (restore replays through PDO), optional `--skip-ssl-verify-server-cert` (MariaDB client), `--binary`, repeatable `-o` options. Pure argv builder: `Database\MysqlDumpCommandFactory`.
- **`security_guard`** (opt-in, default `enabled: false`): `ProductionSecretsGuardSubscriber` fails closed outside `local_environments` (default `dev`, `test`) on HTTP requests and console commands when the setup token, panel password hash or `APP_SECRET` are empty, listed placeholders or (APP_SECRET) shorter than 16 characters. Skips `cache:clear`, `cache:warmup`, `assets:install`, `nowo:site-backup:hash-password`.

### Changed

- **CSP:** inline script and style tags in the restore page, setup and panel layouts, wizard and panel index carry `nonce` from the request attribute `csp_nonce` when present (nowo-tech kit convention). Panel restore / delete confirms use `data-nowo-confirm` + one delegated nonce'd listener instead of `onsubmit="return confirm(…)"`. Inline `style=""` attributes were replaced by classes (wizard progress bar is now a `<progress class="bar">` element).
- Docs: `setup.reopen_when_detector_requires` documents its cost (one detector pass per request).

## [1.15.1] - 2026-10-09

### Fixed

- Tests: `DetectorsTest` no longer uses an anonymous `readonly` class (PHP 8.3+), so the suite parses on PHP 8.2.

### Changed

- Dependencies (lock refresh): UiKitBundle 1.9.1; dev PHPStan 2.3.1 (+ phpunit/symfony extensions 2.1), PHPUnit 10.5.66, Rector 2.7.0, `nowo-tech/phpstan-frankenphp` 1.2.3.
- Demo (Symfony 8): regenerated `config/reference.php`.

## [1.15.0] - 2026-10-07

### Added

- **`setup.reopen_when_detector_requires`** (default `false`, BC): when `true`, `short_circuit_when_done` no longer hides detectors. If `setup.done` or the durable store says complete but a tagged `SetupNeedDetectorInterface` still reports setup required (e.g. platform catalogs wiped), `SetupNeedEvaluator` clears `setup.done`, calls `DurableSetupDoneStoreInterface::clearDone()` and resets wizard progress so the wizard can run again. Costs one detector pass per request.
- **`SetupWizardReopener`** (public service): kit-side helper (`reopen()`, `resetProgress()`) so hosts no longer copy the clear-done / reset-progress logic into their own subscribers.
- **`DurableSetupDoneStoreInterface::clearDone(): void`** (SPI). `NullDurableSetupDoneStore` implements it as a no-op.

### Changed

- **BC note (SPI):** custom `DurableSetupDoneStoreInterface` implementations must add `clearDone()` (no-op is acceptable if the host does not want the bundle to clear its durable flag).

### Fixed

- Tests: avoid PHP 8.4-only `new Foo()->method()` chaining in `UnlocalizedDefaultLocaleSubscriberTest` so PHP 8.2/8.3 CI jobs parse the suite.

## [1.14.3] - 2026-10-01

### Fixed

- **`setup.locale.unlocalized: serve`:** `UnlocalizedDefaultLocaleSubscriber` forces `setup.locale.default` at request time on SiteBackup `*_unlocalized` routes (after Symfony `LocaleListener`), so bare `/setup` stays correct when the compiled route `_locale` default was warmed with a different value (Docker image build vs runtime env). Registered only when setup is enabled, `in_path: both`, and `unlocalized: serve`.

[1.14.3]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.14.3

## [1.14.2] - 2026-09-28

### Security

- Empty `security.access_roles` is fail-closed (deny) unless `allow_unauthenticated` or a custom `access_checker` is set.

## [1.14.1] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.14.2]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.14.2
[1.14.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.14.1

## [1.14.0] - 2026-09-25

### Added

- **Worker restart signal (FrankenPHP worker mode):** `Worker\WorkerRestartSignal` writes `var/site-backup/worker-restart.required` and dispatches `Event\WorkerRestartRequiredEvent` after a restore applied files, after `cache_clear`, and after `database_url` wrote `.env.local`. Command `nowo:site-backup:worker-restart` shows the signal (exit code `3` when a restart is required) and clears it with `--clear`.
- `ConditionalAnswerStep::getInner()`.
- Audit document [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) (scenario B: kernel not reset between requests).

### Fixed

- **Worker mode (scenario B):** `DoctrineDbalSetupProgressStorage` and `DoctrineDbalSetupStepJournal` no longer trust a stale "schema ensured" flag for the life of the worker. When a query fails after the table was created earlier in the process (database dropped / recreated), the table is re-created and the query retried once. Both services implement `ResetInterface` (`kernel.reset`); correctness does not depend on the resetter.
- Cold-start PDO fallback sets `PDO::ATTR_TIMEOUT` (5 s).
- PHPStan findings cleared (level 8).

### Documentation

- [UPGRADING.md](UPGRADING.md) **From 1.13.8 to 1.14.0**; [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) links the worker audit.

## [1.13.8] - 2026-08-24

### Changed

- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.13.8]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.8

## [1.13.7] - 2026-08-21

### Added

- **`setup.short_circuit_when_done`** (default `true`): `SetupNeedEvaluator` skips all need detectors when the `setup.done` marker exists or `DurableSetupDoneStoreInterface::isDone()` is true. Avoids repeated Doctrine / host catalog probes on every request after setup. Set `false` only if a host detector must re-open the gate after done.

[1.13.7]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.7

## [1.13.6] - 2026-08-20

### Fixed

- **CI:** schema-checker coverage tests no longer mock `Doctrine\DBAL\Connection` (not a required package), so PHPUnit succeeds without `doctrine/dbal` in the QA image (REQ-CI-003).
- **Tests:** duck-typed DBAL/PDO probes, wizard form branches (`bootstrap` / `sample` / `database_url`), and FakeDbal `fetchOne` so Clover statements stay ≥ 99% (REQ-TEST-003).

[1.13.6]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.6

## [1.13.5] - 2026-08-20

### Changed

- **Tests:** coverage-completion cases for schema checker, setup journal, DI, and panel forms (REQ-TEST-003).
- **Tests:** extract `FakeDbalConnection` / `FakeDbalResult` / `FakeDurableSetupDoneStore` so coverage tests can autoload and subclass them.

[1.13.5]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.5

## [1.13.4] - 2026-08-20

### Security

- **Flex recipe:** ship `security_nowo_site_backup.yaml` (`access_control` for `/_site_backup` and `/_setup`) and prod hardening (`setup.enabled: false`, `allow_unauthenticated: false`). Prefer **`^1.13.4`** so Flex installs the files (landed on `main` via #15).

## [1.13.3] - 2026-08-20

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.13.2] - 2026-08-19

### Changed

- **Tests:** cover cold-start schema checker and setup redirect subscriber paths (REQ-TEST-003).

[1.13.2]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.2

## [1.13.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.13.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.1

## [1.13.0] - 2026-08-15

### Added
- **`progress_storage: cache` / `cache_doctrine`**: PSR-6 pool progress (`progress_cache_pool`, default `cache.app`) for cold-start without `var/` JSON. `cache_doctrine` prefers DBAL on load and soft-fails Doctrine writes until the schema exists; invalidates cache progress when app tables disappear after migrations.
- **`setup.cold_start.require_application_tables`** (default `true`): empty MySQL schemas (only `nowo_site_backup_*` runtime tables) stay cold-start until host migrations create application tables.

### Fixed
- **Optional `database_url` Skip**: when the step is optional, Skip stays available and the field is not `required` even if detectors report `database connection failed` (missing/empty schema). Skip button uses `formnovalidate`.

### Documentation
- [SETUP-WIZARD.md](SETUP-WIZARD.md) / [CONFIGURATION.md](CONFIGURATION.md) / [UPGRADING.md](UPGRADING.md).

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

[1.13.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.0

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

[Unreleased]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.25.0...HEAD
[1.23.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.22.2...v1.23.0
[1.22.2]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.22.1...v1.22.2
[1.22.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.22.0...v1.22.1
[1.22.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.3...v1.22.0
[1.21.3]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.2...v1.21.3
[1.21.2]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.1...v1.21.2
[1.21.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.0...v1.21.1
[1.21.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.20.0...v1.21.0
[1.20.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.19.2...v1.20.0
[1.19.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.19.0...v1.19.1
[1.19.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.18.1...v1.19.0
[1.18.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.18.0...v1.18.1
[1.18.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.15.1...v1.16.0
[1.15.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.15.0...v1.15.1
[1.15.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.14.3...v1.15.0
[1.14.3]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.14.2...v1.14.3
[1.14.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.13.8...v1.14.0
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

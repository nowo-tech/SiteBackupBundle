# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.19.0](#to-1190)
- [To 1.18.1](#to-1181)
- [To 1.18.0](#to-1180)
- [To 1.17.0](#to-1170)
- [To 1.16.0](#to-1160)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
- [To 1.14.2](#to-1142)
- [To 1.14.1](#to-1141)
- [From 1.13.8 to 1.14.0](#from-1138-to-1140)
- [From 1.13.7 to 1.13.8](#from-1137-to-1138)
- [To 1.13.7](#to-1137)
- [To 1.13.6](#to-1136)
- [To 1.13.5](#to-1135)
- [To 1.13.4](#to-1134)
- [To 1.13.3](#to-1133)
- [To 1.13.2](#to-1132)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.0](#to-1110)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
  - [UiKit composition (REQ-UI-001-kit)](#uikit-composition-req-ui-001-kit)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)
- [To 1.9.0](#to-190)
  - [Requirements](#requirements)
  - [Install / update](#install--update)
  - [Behaviour change (panel roles)](#behaviour-change-panel-roles)
  - [New optional config](#new-optional-config)
  - [Breaking changes](#breaking-changes)
- [To 1.8.1](#to-181)
  - [Install / update](#install--update-1)
  - [Behaviour](#behaviour)
  - [Migration notes](#migration-notes)
- [To 1.8.0](#to-180)
  - [Install / update](#install--update-2)
  - [Behaviour](#behaviour-1)
  - [Migration notes](#migration-notes-1)
- [To 1.7.0](#to-170)
  - [Install / update](#install--update-3)
  - [Behaviour](#behaviour-2)
  - [Migration notes](#migration-notes-2)
- [To 1.6.0](#to-160)
  - [Install / update](#install--update-4)
  - [Behaviour](#behaviour-3)
  - [Migration notes](#migration-notes-3)
- [To 1.5.0](#to-150)
  - [Install / update](#install--update-5)
  - [Behaviour](#behaviour-4)
  - [Migration notes](#migration-notes-4)
- [To 1.4.0](#to-140)
  - [Install / update](#install--update-6)
  - [Behaviour](#behaviour-5)
  - [Migration notes](#migration-notes-5)
- [To 1.3.2](#to-132)
  - [Install / update](#install--update-7)
  - [Behaviour](#behaviour-6)
  - [Migration notes](#migration-notes-6)
- [To 1.3.1](#to-131)
  - [Install / update](#install--update-8)
  - [Behaviour](#behaviour-7)
  - [Migration notes](#migration-notes-7)
- [To 1.3.0](#to-130)
  - [Install / update](#install--update-9)
  - [Behaviour](#behaviour-8)
  - [Optional: YAML tabs + checker](#optional-yaml-tabs--checker)
  - [Optional: always gate until done (new installs)](#optional-always-gate-until-done-new-installs)
  - [Migration notes](#migration-notes-8)
- [To 1.2.0](#to-120)
  - [Install / update](#install--update-10)
  - [Behaviour](#behaviour-9)
  - [Optional: survive wiping `var/`](#optional-survive-wiping-var)
  - [Migration notes](#migration-notes-9)
  - [Breaking / notable changes](#breaking--notable-changes)
- [To 1.1.0](#to-110)
  - [Install / update](#install--update-11)
  - [Behaviour / security](#behaviour--security)
  - [Migration notes](#migration-notes-10)
  - [Breaking / notable changes](#breaking--notable-changes-1)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements-1)
  - [Install](#install)
  - [Suggested first-time config](#suggested-first-time-config)
  - [Behaviour notes (first install)](#behaviour-notes-first-install)
  - [After installing](#after-installing)
  - [Breaking changes](#breaking-changes-1)

## Unreleased

- **Restore heartbeats.** A running restore now refreshes its progress `updated_at` during archive hashing, extraction and file apply (at most every 5 s, or every third of `restore.stale_after_seconds` when smaller). `stale_after_seconds` therefore measures time since the last heartbeat, so a long but healthy restore is no longer treated as abandoned; there is no need to raise it for very large archives. No configuration change required. Custom `RestoreProgressStorageInterface` implementations receive more `save()` calls during a restore.

## To 1.19.0

From **1.18.1** — runtime state never archived/restored, abandoned restores expire, disabled panel 404, translated progress, Symfony >=7.4.

```bash
composer update nowo-tech/site-backup-bundle
```

- **Symfony 7.4+ required.** `composer.json` now requires `^7.4 || ^8.0` (Symfony 7.0–7.3 are no longer installable). Upgrade the host to Symfony 7.4 LTS or 8.x first.
- **Restore progress messages are translation ids.** `RestoreProgress::getMessage()` now returns ids such as `restore.message.applying` (domain `NowoSiteBackupBundle`) instead of English sentences. Translate them (`|trans({}, 'NowoSiteBackupBundle')`) if you render the progress yourself; the panel `progress.json` already returns the translated text. Custom `restore/page.html.twig` overrides: `defaultMessage` is now meant as the lead text and `progress.message` as the current step.
- **Abandoned restores expire.** With the default `restore.stale_after_seconds: null`, a restore whose progress is not updated for 2 × `process_timeout` seconds stops blocking the site and new restores. Set `0` to keep the previous behaviour, or a larger value if a single step (e.g. hashing a very large archive) can run longer without progress updates.
- **Runtime state is never archived or restored.** If you relied on archives containing a custom `storage_dir`, `restore.progress_file`, `storage.history_file` or `setup.progress_file` inside the project, those paths are now skipped on backup and on restore.
- **Panel routes come from a loader.** Keep importing `@NowoSiteBackupBundle/Resources/config/routes.yaml`; the `nowo_site_backup_panel` entry is now `type: nowo_site_backup_panel`. Route names and paths are unchanged. If you imported `SiteBackupPanelController.php` yourself with `type: attribute`, switch to the bundle `routes.yaml` (or `resource: . / type: nowo_site_backup_panel`) so `panel.enabled: false` yields 404s.

## To 1.18.1

From **1.18.0** — security - setup API closed after completion, hardened setup inputs.

```bash
composer update nowo-tech/site-backup-bundle
```

- **Set `setup.setup_token` on every internet-facing host.**
- `/_setup/api/advance` returns 404 once setup is completed and ignores the payload `profile` after a run has started; `/_setup/api/progress` now requires the setup token. Neither API returns `answers` anymore.
- `sql_import_path` must be a `.sql` file inside `var/site-backup/`.
- `DATABASE_URL` is always written single-quoted to `.env.local`; URLs containing `'` or control characters are rejected (percent-encode them, e.g. `%27`).
- Anonymous/unauthorised `progress.json` responses only contain `active`, `phase`, `percent` and `message`. Archives and the restore dump are written with mode 0600, and `last-restore-dump.sql` is deleted after the setup `sql_file` step imports it.

## To 1.18.0

From **1.17.0** — no breaking configuration changes.

```bash
composer update nowo-tech/site-backup-bundle   # requires nowo-tech/ui-kit-bundle ^1.6
php bin/console cache:clear
```

- **Translations:** panel, setup and restore screens are now fully translated (domain `NowoSiteBackupBundle`, 7 locales). Visible English text now follows the request locale. Controller messages (`error` / `success` template variables) are translated before rendering; tests asserting English strings with a non-English locale must be updated.
- **Fix — panel forms:** before 1.18 the Verify / Restore / Delete / Logout / Clear status forms rendered `<form action="verify">` etc. (Symfony's `action` option reused for the hidden field) and posted outside the panel. If you build `PanelActionType` yourself, rename the option `action` to `panel_action`. Template forks that rebuilt these forms (e.g. with another form helper) only to work around this can be removed.
- **Theming:** the bundle layouts read `--nowo-ui-*` tokens. If you restyled the setup screen by setting `--nowo-sbb-*` on `:root`, set the matching `--nowo-ui-*` tokens instead (override block `nowo_site_backup_theme_tokens`) or set `--nowo-sbb-*` on `.nowo-site-backup-setup`. Minor default shifts: panel radius / danger / success colours now come from `nowo-ui.css`; panel tables are wrapped in `<div class="nowo-ui-table-wrap">`; "Clear status" / "Logout" are secondary buttons; row actions carry `nowo-ui-action` classes.
- **Delete forks:** with the new blocks, `panel.row_actions_display`, `panel.extra_classes` and the `_flashes` / `_styles` partials, copies of `panel/login`, `panel/index`, `panel/history` and `setup/token` can usually be deleted or shrunk to an `{% extends '@NowoSiteBackupBundle/…' %}` with a few blocks — see [USAGE.md](USAGE.md#translations-theming-and-chrome-blocks-v118).
- **Host panel shells** (`panel.layout_template`) that do not extend the bundle layout should `{% include '@NowoSiteBackupBundle/panel/_flashes.html.twig' %}` so login errors ("Invalid password.", throttle) and action results are visible.

## To 1.17.0

From **1.16.0** — no breaking configuration changes. The panel login throttle is **on by default**.

```bash
composer update nowo-tech/site-backup-bundle   # pulls symfony/rate-limiter
php bin/console cache:clear
```

- **New hard dependency:** `symfony/rate-limiter` `^7.3 || ^8.0`. With FrameworkBundle it also enables `framework.rate_limiter` and the `cache.rate_limiter` pool, which the built-in limiter uses (fallback `cache.app`). If neither pool exists the container fails to compile with a message naming `security.login_throttle.cache_pool` / `limiter` / `enabled: false`.
- **Default behaviour:** 5 password attempts per client IP per 15 minutes (only when the password gate is enabled). Over the limit the login page answers HTTP 429 with `Retry-After`. A successful login resets the counter and rotates the session id. With several app servers, point `cache_pool` to a shared pool (e.g. Redis); behind a proxy configure `framework.trusted_proxies`. Tests that post many wrong passwords can raise `limit` under `when@test` or set `enabled: false`.
- **Hosts that already decorate `SiteBackupAccessGateInterface`** with their own throttle + `migrate(true)` keep working (the decorator now wraps the bundle wrapper; attempts are then counted twice, by both limiters). Remove the decorator, or keep your limiter and plug it in:

```yaml
nowo_site_backup:
    security:
        login_throttle:
            limiter: limiter.site_backup_login   # existing framework.rate_limiter.site_backup_login
```

  Then the decorator class (and, if you drop `limiter:`, the `framework.rate_limiter.site_backup_login` entry and its tests) can be deleted.
- **Container wiring:** the `SiteBackupAccessGateInterface` alias now targets `nowo_site_backup.access_gate` (`ThrottledSiteBackupAccessGate`). Code that fetched `PasswordSiteBackupAccessGate` directly bypasses the throttle; inject the interface instead.
- **Template overrides:** `panel/login.html.twig` receives `throttled` (bool); the message is in `error` (rendered by the panel layout flash block).

## To 1.16.0

From **1.15.1** — no breaking changes; new features are opt-in except the worker memos.

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

- **Worker memos (on by default):** `setup.worker_memo.schema_probe_ttl: 60` reuses a positive cold-start / Doctrine detector probe per worker; `progress_ddl_ttl: 3600` keeps the progress-table DDL off the request path. If you drop the database under a running worker and rely on the gate re-opening *immediately*, set `schema_probe_ttl: 0` (or restart workers). Set both to `0` for the exact 1.15 behaviour.
- **Template overrides:** if you copied `panel/index.html.twig`, replace `onsubmit: "return confirm(…)"` with `'data-nowo-confirm': '…'` and keep the bundle's small listener script (or your own). If you copied `setup/wizard.html.twig`, the progress bar is now `<progress class="bar" max="100" value="…">`; the old `.bar > i` CSS still exists for overrides. Hosts with a nonce-based CSP should set `$request->attributes->set('csp_nonce', $nonce)`.
- **Opt-in:** replace a hand-written `mysqldump` line with the built-in command, raise the time limit for setup / panel requests, and enable the production guard:

```yaml
nowo_site_backup:
    bump_time_limit: true
    backup:
        database_dump_command: 'php %kernel.project_dir%/bin/console nowo:site-backup:db-dump --no-debug'
    security_guard:
        enabled: true
        forbidden_setup_tokens: ['app-local-setup']        # values documented in .env.dist
        forbidden_password_hashes: ['$2y$12$…']
```

- Hosts that ported `MemoizedSchemaExistenceChecker`, `SetupAdvanceTimeLimitSubscriber`, `SiteBackupSecurityDefaultsGuard` or a `bin/site-backup-db-dump` script can drop them. Keep any app-specific checks (e.g. Redis password) in the host. Remove a host decorator of `MysqlSchemaExistenceChecker` or set `schema_probe_ttl: 0` to avoid double memoization.

## To 1.15.1

From **1.15.0** — dependency refresh and a test-only PHP 8.2 fix. No breaking changes. No application upgrade steps.

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.15.0

From **1.14.3** — `setup.done` short-circuit can re-open the wizard when detectors still require setup.

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

- **SPI (action required for custom stores):** `DurableSetupDoneStoreInterface` gains `clearDone(): void`. Add it to your implementation (clear the durable flag, or no-op to keep it).
- **Opt-in:** `setup.reopen_when_detector_requires: true` re-opens the wizard (clears `setup.done`, durable `clearDone()`, resets progress) when `short_circuit_when_done` would skip detectors but a tagged detector still requires setup. Default `false` keeps 1.14 behaviour.
- Hosts with a custom "re-open after catalog wipe" subscriber can inject `Nowo\SiteBackupBundle\Setup\SetupWizardReopener` and call `reopen()`; state stored outside SiteBackup (e.g. an instance-settings flag not behind the durable store) must still be cleared by the host.

```yaml
nowo_site_backup:
    setup:
        short_circuit_when_done: true
        reopen_when_detector_requires: true
```

## To 1.14.2

From **1.14.1** — `access_roles` fail-closed.

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` denies the admin panel. Set roles / custom checker, or demo-only `allow_unauthenticated`.

## To 1.14.1

From **1.14.0** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## From 1.13.8 to 1.14.0

**FrankenPHP worker mode (kernel not reset between requests)** — non-breaking.

- `DoctrineDbalSetupProgressStorage` / `DoctrineDbalSetupStepJournal` self-heal a stale schema memo and implement `ResetInterface` (safe under scenario B: no `kernel.reset`).
- After a restore that applied files, or setup `cache_clear` / `database_url` that wrote `.env.local`, the bundle writes `var/site-backup/worker-restart.required` and dispatches `WorkerRestartRequiredEvent`. Restart workers (Caddy admin `POST /frankenphp/workers/restart` or container restart), then `bin/console nowo:site-backup:worker-restart --clear`. Optional: listen to the event to automate the restart.
- `RestoreOrchestrator` / `SetupOrchestrator` accept optional last constructor arg `?WorkerRestartSignal` (wired by the bundle; BC default `null`).
- Cold-start PDO fallback uses a 5 s connect timeout.
- Audit: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

## From 1.13.7 to 1.13.8

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.13.7

**Site gate performance:** `setup.short_circuit_when_done` defaults to `true`. After `setup.done` (or a durable store `isDone()`), detectors are not consulted. If a custom `#[AsSetupNeedDetector]` must still run after done, set:

```yaml
nowo_site_backup:
    setup:
        short_circuit_when_done: false
```

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

## To 1.13.6

Patch release: CI/tests for optional DBAL schema probe. **No application upgrade steps.**

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.13.5

Patch release with test-only changes. **No application upgrade steps.**

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.13.4

From **1.13.3** — Review Flex recipe `security_nowo_site_backup.yaml` and prod `setup.enabled: false` after install/update.

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.13.3

No application upgrade steps.

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.13.2

Patch release with test-only changes. **No application upgrade steps.**

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.13.1

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

## To 1.13.0

**Cold-start empty schema:** with `setup.cold_start.require_application_tables: true` (default), an empty MySQL database (after `database_create`, before migrations) stays cold-start. Set `false` only if you intentionally treat `SELECT 1` as enough.

**Progress without `var/` JSON:** prefer Redis/cache during cold-start:

```yaml
nowo_site_backup:
    setup:
        progress_storage: cache_doctrine   # or cache
        progress_cache_pool: cache.app
        # progress_cache_key / progress_cache_ttl optional
```

**Optional DATABASE_URL:** Skip works when the step is `optional: true` even if Doctrine cannot connect yet. No host form overrides required.

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

## To 1.12.0

Optional **durable setup done** and **cold-start schema gate** (see [SiteBackupBundle#9](https://github.com/nowo-tech/SiteBackupBundle/issues/9)):

```yaml
nowo_site_backup:
    setup:
        durable_done:
            enabled: true
            redirect_target: '/'
        cold_start:
            enabled: true
            stop_propagation: true
            # safe_path_prefixes: [...]  # defaults include /health/, /_wdt, …
            mysql_host: '%env(MYSQL_HOST)%'
            mysql_database: '%env(MYSQL_DATABASE)%'
```

**Durable done:** replace the `DurableSetupDoneStoreInterface` service alias in the host app with an implementation backed by your database row (e.g. `instance_settings.setup_completed_at`). Default alias remains `NullDurableSetupDoneStore` (BC).

**Cold start:** when enabled, requests redirect to the setup path until MySQL schema is reachable; probe uses DBAL when available, else `setup.cold_start.mysql_*`.

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

## To 1.11.0

Optional **per-step setup journal** when using `progress_storage: doctrine` or `chain`:

```yaml
nowo_site_backup:
    setup:
        progress_storage: chain
        progress_step_rows: true   # default
        # progress_steps_table: nowo_site_backup_setup_step
```

- Tables are created with **runtime DDL** on first DB write — **do not** add a host Doctrine Migration for them (wizard steps run before migrations exist).
- Disable with `progress_step_rows: false` if you only want the singleton progress row.
- See [SETUP-WIZARD.md](SETUP-WIZARD.md) § Progress storage.

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

## To 1.10.1

Patch release: documentation and git-hygiene only. No config or API changes from **1.10.0**.

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.10.0

From **1.9.x** — UiKit composition, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
php bin/console assets:install
```

### UiKit composition (REQ-UI-001-kit)

Panel/setup UI depends on **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`).

1. The package is pulled transitively; run `assets:install`.
2. Layouts load `asset('css/nowo-ui.css', 'nowo_ui_kit')`.
3. Optional: set `nowo_ui_kit.css_framework` / `icon_set` in the host. If unset, SiteBackup seeds those keys from panel/setup CSS settings.

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

## To 1.9.0

Minor release: REQ-UI-002 role-based panel access (`access_roles` / `access_checker`). Default panel security is stricter. The ops password gate remains an **additional** layer.

### Requirements

- **PHP** `>=8.2` and `<8.6`. Symfony **8.x** still needs **PHP 8.4+**.
- **Symfony** `^7.0 || ^8.0` (CI minors: **7.4**, **8.0**, **8.1**).
- **SecurityBundle** when the panel is enabled and `security.allow_unauthenticated` is `false` (default).

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.9
php bin/console cache:clear
```

### Behaviour change (panel roles)

| Topic | Before | 1.9.0 |
| --- | --- | --- |
| Default panel auth | Ops password gate only | Symfony roles (`ROLE_ADMIN` by default) **plus** optional password gate |
| Apps without SecurityBundle | Panel could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |

**Demos / trusted local kernels** without SecurityBundle:

```yaml
nowo_site_backup:
    security:
        allow_unauthenticated: true   # never in production
```

**Production** (recommended): keep `allow_unauthenticated: false`, ensure SecurityBundle is installed, and grant at least one of `access_roles` (or provide a custom `access_checker`).

### New optional config

```yaml
nowo_site_backup:
    security:
        access_roles: [ROLE_ADMIN]
        access_checker: null
        allow_unauthenticated: false
```

### Breaking changes

Apps that enabled the panel without SecurityBundle (or without a matching `access_roles` grant) must either install/configure SecurityBundle roles or set `allow_unauthenticated: true` for non-production use.

## To 1.8.1

Demo-only refresh (FrankenPHP Symfony 8). **No bundle API migration** from **1.8.0**.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.8.1
php bin/console cache:clear
```

### Behaviour

- Bundle runtime unchanged from 1.8.0 (`#[AsSetupNeedDetector]`, locale-in-path, layouts).
- Demo wires host layouts, `css_framework`, `locale.in_path: both`, force-setup detector toggle, and profile `demo_features`.

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Host app config | — | No change required |
| Local demo | Older homepage links | See [demo/README.md](../demo/README.md) |

## To 1.8.0

Pluggable **setup-need detectors** for the site gate. **Non-breaking** — built-in detectors keep the same toggles under `setup.detectors.*`.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.8.0
php bin/console cache:clear
```

### Behaviour

- Host apps register `#[AsSetupNeedDetector(priority: 50)]` + `SetupNeedDetectorInterface` (mirror of `#[AsSetupTabChecker]` for tabs).
- Aggregated by `SetupNeedEvaluator` via tag `nowo.site_backup.setup_need_detector`.
- **Not** the same as profile tab `checker:` (`SetupTabCheckerInterface` / `#[AsSetupTabChecker]`), which only affects a wizard tab.

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| App-specific “setup required?” | Host `kernel.request` subscriber + often `markRequired()` | Prefer a custom detector (no sticky `setup.required` marker) |
| Tab completeness / seed sync | `tabs[].checker` | Unchanged |

```php
use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;

#[AsSetupNeedDetector(priority: 50)]
final class PlatformCatalogsSetupNeedDetector implements SetupNeedDetectorInterface
{
    public function isSetupRequired(): bool { /* … */ }

    public function getReason(): string { return 'platform catalogs missing'; }
}
```

If an older host wrote `var/site-backup/setup.required` from a catalog check, delete that marker once after upgrading so only live detectors decide the gate.

## To 1.7.0

Optional **locale-in-path** for the setup wizard (same model as AuthKit). **Non-breaking** — default `setup.locale.in_path: never` keeps bare `/_setup` URLs.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.7.0
php bin/console cache:clear
```

### Behaviour

| `setup.locale.in_path` | URLs |
| --- | --- |
| `never` (default) | `/_setup`, `/_setup/done`, … |
| `always` | `/{_locale}/_setup`, … |
| `both` | Localized + bare; bare uses `unlocalized: redirect` (default) or `serve` |

- Route names stay `nowo_site_backup_setup*` (bare aliases use `_unlocalized` suffix when `both`).
- Site gate redirects via `SetupPathPrefixResolver` (respects current locale when `always`/`both`).
- Localized setup prefixes are auto-added to restore exclusions.

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Setup route loading | Attribute controller + YAML `prefix` | `SetupRouteLoader` (`type: nowo_site_backup_setup`) |
| Locale in wizard URL | Not supported | Optional `setup.locale` |
| Host apps with `/{locale}/…` | Manual redirects / forks | Prefer `in_path: always` or `both` |

```yaml
nowo_site_backup:
    setup:
        path_prefix: '/_setup'
        locale:
            in_path: both              # never | always | both
            default: en
            enabled: [en, es]
            unlocalized: redirect      # serve | redirect (when both)
```

## To 1.6.0

Optional host CSS stack hint for setup/panel Web UI (REQ-UI-001). **Non-breaking** — default remains `custom` (semantic `nowo-ui-*`).

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.6.0
php bin/console cache:clear
```

### Behaviour

- New root key `css_framework` → Twig global `nowo_site_backup_css_framework`.
- Demo layouts add `nowo-ui-css-{{ framework }}` / `data-css-framework` on `<html>`.
- No change to `setup.layout_template` / `panel.layout_template` from **1.5.0**.

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Host CSS stack | Implicit / CSS-only | Optional `css_framework: bootstrap5` (etc.) alongside layout templates |
| Default | — | `custom` (unchanged look for existing installs) |

```yaml
nowo_site_backup:
    css_framework: bootstrap5
    panel:
        layout_template: 'kit/site_backup_panel_layout.html.twig'
    setup:
        layout_template: 'kit/site_backup_setup_layout.html.twig'
```

## To 1.5.0

Panel (and setup) integrate with a host layout via config + Twig globals — avoid forking page templates (REQ-TWIG-001 / REQ-UI-001).

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.5.0
php bin/console cache:clear
```

### Behaviour

- Panel pages `{% extends nowo_site_backup_panel_layout_template %}` and fill `nowo_site_backup_panel_content` / `nowo_ui_content`.
- Setup pages `{% extends nowo_site_backup_setup_layout_template %}` (global; still driven by `setup.layout_template` when set).
- Default chrome remains the bundle standalone layouts.
- Prefer CSS on `.nowo-ui-*` / `.nowo-site-backup-*` over copying forms or pages.

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Panel branding | Fork `panel/layout.html.twig` or full pages | Set `panel.layout_template` to a thin host shell |
| Setup branding (1.4) | View var `layout_template` | Twig global `nowo_site_backup_setup_layout_template` |
| Restyle buttons/tables | Inline / fork | Classes `nowo-ui-btn`, `nowo-ui-table`, … |

Example panel shell:

```twig
{# templates/kit/site_backup_panel_layout.html.twig #}
{% extends 'layouts/admin.html.twig' %}
{% block body %}
    {% block nowo_ui_content %}
        {% block nowo_site_backup_panel_content %}{% endblock %}
    {% endblock %}
{% endblock %}
```

```yaml
nowo_site_backup:
    panel:
        layout_template: 'kit/site_backup_panel_layout.html.twig'
    setup:
        layout_template: 'kit/site_backup_setup_layout.html.twig'
```

## To 1.4.0

Setup pages use a **host `layout_template`** so apps brand the shell without forking wizard/done/token markup.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.4.0
php bin/console cache:clear
```

### Behaviour

- `wizard.html.twig`, `done.html.twig`, and `token.html.twig` fill `nowo_site_backup_content` inside the configured setup layout.
- Default layout remains the bundle dark standalone theme (`@NowoSiteBackupBundle/setup/layout.html.twig`).
- Detector reasons render in the vendor wizard; database form skips are hidden when the connection failed.

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Branding setup UI | Copy/fork `setup/wizard.html.twig` (full HTML) | Set `setup.layout_template` to a thin host shell |
| Full HTML overrides of wizard/done/token | Worked as drop-in | Must either keep forking **or** switch to `layout_template` + delete forks |

See **To 1.5.0** for panel `layout_template` and Twig layout globals.

## To 1.3.2

CI no longer rewrites Symfony constraints to `^7.4` when applying CS Fixer on `main`.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.3.2
php bin/console cache:clear
```

### Behaviour

- Declared `symfony/*` requirements remain `^7.0 || ^8.0` (Symfony 7 and 8 hosts).
- No setup/wizard behaviour changes from 1.3.1.

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Some `symfony/*` requires after CI style commits | Drifted to `^7.4` | Stays `^7.0 \|\| ^8.0` |

## To 1.3.1

Twig reusability guidance (REQ-TWIG-001 / REQ-UI-001) and a shared Continuar partial.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.3.1
php bin/console cache:clear
```

### Behaviour

- No breaking config changes from 1.3.0.
- Custom tabs without `template` render `@NowoSiteBackupBundle/setup/_continue_form.html.twig`.
- Prefer keeping wizard UI in the bundle (or thin overrides under `templates/bundles/NowoSiteBackupBundle/`) so package upgrades do not force redoing screens; put app logic in `checker` / `runner`.
- Symfony component constraints again `^7.0 || ^8.0` (Symfony 8 hosts).

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Custom tab Continuar UI | Inline in `wizard.html.twig` | Partial `_continue_form.html.twig` (overridable) |
| Docs examples for `template` | Often `@App/...` | Prefer omit `template` or bundle logical names |
| Some `symfony/*` requires | `^7.4` only (CI drift) | `^7.0 \|\| ^8.0` |

## To 1.3.0

Bootstrap choice (guided vs full SQL dump), YAML tabs + checkers, advance mode, and stricter mid-wizard site gate.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.3
php bin/console cache:clear
```

### Behaviour

- `fresh_install` now starts with **`bootstrap_mode`**: guided admin path or full database import.
- Full dump defaults: `var/site-backup/full-import.sql` or `var/site-backup/last-restore-dump.sql` (override with form field / answer `sql_import_path`).
- After import, **migrations** (and any app `console` / idempotent loaders you add) still run; **`admin_user`** skips when users already exist (`skip_if_admin_exists: true`).
- Starting the wizard writes **`setup.required`** until the `marker` step writes **`setup.done`** — the public site stays gated mid-setup.
- Cold-start apps that must lock the site until setup finishes should set `setup.require_done_marker: true` (unchanged default `false` for BC when adding the bundle to an existing app).
- Prefer **`profiles.*.tabs`** for the wizard flow (built-in + `custom` with `checker` / `template` / `runner`). Existing **`steps`** profiles keep working.
- **`advance_mode`**: `automatic` (default, BC) chains auto tabs; `manual` pauses after each auto tab until Continuar. Interactive tabs always pause. CLI `nowo:site-backup:setup` always chains (automatic).
- Tab `label` / `description` are translation ids (domain `NowoSiteBackupBundle` by default, or `label_domain`).

### Optional: YAML tabs + checker

Prefer **bundle UI** (omit `template`, or use `@NowoSiteBackupBundle/setup/_continue_form.html.twig`). Put app logic in the **checker** / **runner**, not in a forked `@App` Twig page — so upgrading the bundle keeps working without redoing screens (REQ-TWIG-001 / REQ-UI-001).

```yaml
nowo_site_backup:
    setup:
        advance_mode: automatic
        profiles:
            fresh_install:
                advance_mode: manual
                tabs:
                    - { type: requirements, label: setup.tab.requirements }
                    - id: menus
                      type: custom
                      label: setup.tab.custom
                      description: setup.check.needs_input
                      checker: App\Setup\Checker\MenusReadyChecker
                      runner: { type: console, command: 'app:menus:sync' }
                    - { type: marker, write_done: true }
```

```php
use Nowo\SiteBackupBundle\Attribute\AsSetupTabChecker;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerInterface;

#[AsSetupTabChecker]
final class MenusReadyChecker implements SetupTabCheckerInterface { /* … */ }
```

If you must restyle Continuar, override `templates/bundles/NowoSiteBackupBundle/setup/_continue_form.html.twig` instead of inventing `@App/setup/menus.html.twig`.

### Optional: always gate until done (new installs)

```yaml
nowo_site_backup:
    setup:
        require_done_marker: true
        detectors:
            incomplete_progress: true
```

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| `fresh_install` steps | requirements → … → migrations → admin → marker | + `bootstrap_mode` + conditional `sql_file` |
| Custom profiles overriding defaults | Unchanged if you set `setup.profiles` explicitly | Re-add `bootstrap_mode` / `when_answer` if you want the new UX |
| Mid-wizard gate | Incomplete progress / `setup.required` | Also marks `setup.required` on wizard start |
| Flow declaration | `steps` only | Optional `tabs` (+ checkers); `steps` still valid |
| Advance between auto steps | Always chain until input | `advance_mode: manual` pauses per auto tab (UI) |

## To 1.2.0

Durable setup progress (optional DBAL / chain storage) and an incomplete-progress site gate.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.2
php bin/console cache:clear
```

### Behaviour

- Default storage remains **`filesystem`** (`var/site-backup/setup-progress.json`). No config change required for existing apps.
- `setup.detectors.incomplete_progress` defaults to **`true`**: if progress phase is `running`, `waiting_input`, or `failed` (and `setup.done` is absent), the site gate sends visitors to `/_setup`. Disable with `incomplete_progress: false` if you do not want mid-wizard gating.
- `setup.progress_storage: doctrine` or `chain` needs a working Doctrine DBAL default connection (`doctrine.dbal.default_connection`). Soft dependency — see `composer suggest` for `doctrine/dbal`.

### Optional: survive wiping `var/`

```yaml
nowo_site_backup:
    setup:
        progress_storage: chain   # filesystem | doctrine | chain
        # progress_table: nowo_site_backup_setup_progress
        detectors:
            incomplete_progress: true
```

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Setup progress storage | JSON file only | Optional `doctrine` / `chain` (`progress_storage`) |
| Mid-wizard after `var/` wipe | Gate lost if markers/JSON gone | With `chain`/`doctrine` + incomplete detector, gate resumes from DB |
| Progress timestamps | Not persisted | `started_at` / `completed_at` in JSON + DB; shown by `setup-status` |
| Default `setup.path_prefix` | `/_setup` | **`/_setup`** (override with `path_prefix: '/_setup'` if you must keep the old URL) |
| Route prefixes | Hard-coded in `routes.yaml` | `%nowo.site_backup.*.path_prefix%` parameters |

### Breaking / notable changes

- **Default wizard URL** is `/_setup` (was `/_setup`). Update firewalls, bookmarks, and reverse-proxy rules; or set `setup.path_prefix: '/_setup'` to keep the previous path.
- New detector default (`incomplete_progress: true`) only affects apps that already have unfinished progress state on disk/DB.
- Composer `require` for `symfony/config`, `dependency-injection`, `http-foundation`, `http-kernel`, and `security-core` is aligned again to `^7.0 || ^8.0` (same as the rest of the bundle).
- Imported routes honour `panel.path_prefix` / `setup.path_prefix` via container parameters (defaults `/_site_backup` and `/_setup`).

## To 1.1.0

Security hardening, full-tree include_paths option, restore message i18n default, and coverage gate ≥99%.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.1
php bin/console cache:clear
```

### Behaviour / security

- With `security.password_protection: true` (default), **`password_hash` must be set** or the panel gate reports misconfigured / denies access (fail-closed). Generate with `php bin/console nowo:site-backup:hash-password`.
- Panel and setup POSTs require a working CSRF token manager (`symfony/security-csrf` is already a hard dependency).

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| `backup.include_paths: []` or `["."]` | Empty / path-as-written | **Entire project** minus `exclude_patterns` |
| Omit `include_paths` | Selective defaults | Unchanged (same selective defaults) |
| `restore.default_message` default | English literal | Translation id `restore.page.message` |

If you relied on an explicit empty `include_paths` list meaning “use defaults”, **remove the key** instead of setting `[]`.

### Breaking / notable changes

- Explicit `include_paths: []` / `["."]` semantics change as above.
- Default restore loading message is now a translation key (override with a literal string still works if you set `default_message` yourself).

## To 1.0.0

First public release. No prior Packagist versions — install fresh.

### Requirements

- **PHP** `>=8.2` and `<8.6`. Symfony **8.x** requires **PHP 8.4+**.
- **Symfony** `^7.0 || ^8.0` (CI minors: **7.4**, **8.0**, **8.1**).
- System **`tar`** available in the runtime for backup create/extract.
- **Twig** for the restore loading page, admin panel, and setup wizard.

### Install

```bash
composer require nowo-tech/site-backup-bundle:^1.0
```

With Flex, the recipe registers the bundle and copies config. Without Flex, see [INSTALLATION.md](INSTALLATION.md).

```yaml
# config/routes.yaml
nowo_site_backup:
    resource: '@NowoSiteBackupBundle/Resources/config/routes.yaml'
```

This imports both `/_site_backup` (panel) and `/_setup` (wizard).

### Suggested first-time config

```yaml
nowo_site_backup:
    security:
        password_hash: '%env(SITE_BACKUP_PASSWORD_HASH)%'
    setup:
        # Bind your app User creator (required for admin_user steps)
        admin_provisioner: App\Setup\AdminUserProvisioner
        # Optional: force wizard until setup.done exists (fresh clones)
        # require_done_marker: true
        detectors:
            marker: true
            # doctrine_connect: true
            # doctrine_schema_empty: true
```

Generate a panel password hash:

```bash
php bin/console nowo:site-backup:hash-password
```

### Behaviour notes (first install)

| Topic | Default |
| --- | --- |
| Restore loading page | On when a restore is active (`enabled: true`) |
| Setup wizard gate | On when `setup.required` exists; `require_done_marker` defaults to **false** so existing apps are not locked after `composer require` |
| After successful restore | Writes `setup.required` with profile `post_restore` (`setup.trigger_after_restore: true`) |
| DB dump on restore | Copied to `var/site-backup/last-restore-dump.sql`; import via setup `sql_file` step or ops |

### After installing

1. `php bin/console cache:clear`
2. Open `/_site_backup` and create a test backup (or use CLI `nowo:site-backup:create`).
3. Optionally mark setup and walk the wizard:  
   `php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y`
4. Read [USAGE.md](USAGE.md) and [SETUP-WIZARD.md](SETUP-WIZARD.md).

### Breaking changes

None — initial release.

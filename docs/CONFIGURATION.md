# Configuration

Root key: `nowo_site_backup`.

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `true` | Master switch for the restore loading page |
| `default_message` | `restore.page.message` | Translation id (domain `NowoSiteBackupBundle`) or literal for the public loading page. v1.19+: always rendered as the page's lead text; the current step (`restore.message.*` translation ids written by the orchestrator) is shown below it. JSON responses use the step message and fall back to `default_message` |
| `status_code` | `503` | HTTP status while restore is active |
| `subscriber_priority` | `31` | After router (exclusions / attributes) |
| `process_timeout` | `600` | Seconds for `tar` / dump processes (REQ-RUNTIME-001) |
| `bump_time_limit` | `false` | (v1.16+) `set_time_limit()` on main requests under `panel.path_prefix` and `setup.path_prefix` (incl. `/{locale}` variants) so a short prod `max_execution_time` / FrankenPHP worker timer does not kill dump, restore or migrate subprocesses mid-pipe. Other requests keep the host limit |
| `bump_time_limit_seconds` | `null` | Value for `set_time_limit()` when `bump_time_limit` is on; `null` = `process_timeout`, `0` = unlimited |
| `security_guard.enabled` | `false` | (v1.16+) Opt-in production safety guard (`ProductionSecretsGuardSubscriber`): outside `local_environments`, main HTTP requests and console commands fail with a `RuntimeException` while secrets are empty / placeholders |
| `security_guard.local_environments` | `[dev, test]` | `kernel.environment` values where the guard is skipped (so `staging`, `preprod`, … are checked) |
| `security_guard.require_setup_token` / `security_guard.forbidden_setup_tokens` | `true` / `[]` | Non-empty `setup.setup_token` (when setup is enabled) and not one of the listed documented values |
| `security_guard.require_panel_password` / `security_guard.forbidden_password_hashes` | `true` / `[]` | Non-empty `security.password_hash` (when panel + `password_protection` are on and no custom `access_gate`) and not a listed hash |
| `security_guard.check_app_secret` / `security_guard.app_secret` / `security_guard.forbidden_app_secrets` / `security_guard.app_secret_min_length` | `true` / `null` (= `%env(default::APP_SECRET)%`) / Symfony & common placeholders / `16` | APP_SECRET must be set, not a placeholder, long enough |
| `security_guard.skip_console_commands` | `cache:clear`, `cache:warmup`, `assets:install`, `nowo:site-backup:hash-password` | Image builds warm the cache without runtime secrets; `hash-password` generates the hash |
| `css_framework` | `custom` | Host CSS stack hint (REQ-UI-001): `bootstrap5`, `bootstrap`, `bootstrap4`, `tabler`, `tailwind`, `foundation`, `custom`, `none`. Twig global `nowo_site_backup_css_framework`. Demo uses semantic `nowo-ui-*` (`custom`). |
| `backup.include_paths` | config, public, templates, … | Relative to `kernel.project_dir`. **`[]` or `["."]` = entire project** (minus `exclude_patterns`). Omitting the key keeps the selective defaults. |
| `backup.exclude_patterns` | cache/log/vendor/… | `fnmatch` against relative paths |
| `backup.storage_dir` | `%kernel.project_dir%/var/site-backup/archives` | Archives + `.meta.json`. v1.19+: wherever it lives inside the project it is never packed into an archive (no nested archives) nor overwritten by a restore |
| `backup.database_dump_command` | `null` | Shell command writing SQL to stdout. Built-in: `php %kernel.project_dir%/bin/console nowo:site-backup:db-dump` (see [USAGE.md](USAGE.md#built-in-database-dumper)) |
| `restore.progress_file` | `%kernel.project_dir%/var/site-backup/restore-progress.json` | Polled by the loading UI. v1.19+: excluded from archives and never overwritten by a restore, also outside `var/site-backup/` |
| `restore.protected_paths` | `.env.local`, `var/site-backup` | Never overwritten on apply (in addition to `var/site-backup/` and, v1.19+, the configured `backup.storage_dir`, `restore.progress_file`, `storage.history_file` and `setup.progress_file`) |
| `restore.stale_after_seconds` | `null` (= 2 × `process_timeout`) | (v1.19+) An *active* restore whose progress was not updated for this many seconds is treated as abandoned (worker killed / crashed mid-restore): the loading page (and its 503) stops being served, `progress.json` reports `active: false` and a new restore may start (its log records the abandoned one). `0` = never (pre-1.19: a dead worker kept the site in 503 until the progress file was edited). Since the next release a running restore writes heartbeats during archive hashing, extraction and file apply (at most every 5 s, or every third of this value when smaller), so this is the time since the last heartbeat, not the length of a single step |
| `storage.history_file` | `%kernel.project_dir%/var/site-backup/history.jsonl` | Backup / restore / delete history (JSON lines) shown on the panel history page. v1.19+: excluded from archives and never overwritten by a restore |
| `exclusions.paths` | `[]` | Exact request paths (`getPathInfo()`) that are never replaced by the loading page while a restore runs |
| `exclusions.path_prefixes` | `[]` | Path prefixes never replaced by the loading page. `panel.path_prefix` and `setup.path_prefix` are always added |
| `exclusions.routes` | `[]` | Route names (`_route`) never replaced by the loading page (see also the `#[ExcludeFromRestore]` attribute) |
| `exclusions.patterns` | `[]` | Path patterns: a regex when it starts with `#` or `~`, otherwise an `fnmatch` glob. With `setup.locale.in_path` ≠ `never` a `/{locale}{setup.path_prefix}` regex is added (locales are `preg_quote`d) |
| `exclusions.ips` | `[]` | Client IPs / CIDR ranges (`IpUtils::checkIp`) that keep reaching the application during a restore (ops checks) |
| `panel.enabled` | `true` | Register the backup panel (controller + routes). v1.19+: with `false` the imported `routes.yaml` registers no panel route (404) instead of routes pointing at the removed controller (500). The restore loading page keeps working (it falls back to its meta refresh) |
| `panel.path_prefix` | `/_site_backup` | Auto-excluded from loading page; prefix of the panel routes (`/`, `/progress.json`, `/history`) |
| `panel.layout_template` | `null` (bundle `panel/layout.html.twig`) | Host Twig shell; Twig global `nowo_site_backup_panel_layout_template`; blocks `nowo_ui_content` / `nowo_site_backup_panel_content` |
| `templates.panel_layout` | `@NowoSiteBackupBundle/panel/layout.html.twig` | Same as `panel.layout_template` when set |
| `panel.row_actions_display` | `text` | v1.18+: archive row actions (Verify / Restore / Delete): `text` (pre-1.18 markup), `icon`, `icon_text`, or `kit` (follow UiKit `row_actions_display`, global `nowo_ui_kit_row_actions_display`). Twig global `nowo_site_backup_row_actions_display` |
| `panel.extra_classes.card` / `panel.extra_classes.table` / `panel.extra_classes.button` / `panel.extra_classes.input` | `''` | v1.18+: classes appended to panel cards, tables, buttons and inputs (login, index, history). Twig global `nowo_site_backup_panel_classes` |
| `security.access_roles` | `[ROLE_ADMIN]` | REQ-UI-002: at least one role grants panel access when `allow_unauthenticated` is false |
| `security.access_checker` | `null` | Optional service id implementing `SiteBackupAccessCheckerInterface` |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only: skip Symfony role check (password gate may still apply). Requires SecurityBundle when `false` and panel is enabled |
| `security.password_protection` | `true` | Ops password gate (additional to role check). Fail-closed without `password_hash` when true |
| `security.password_hash` | `null` | Prefer env `SITE_BACKUP_PASSWORD_HASH` |
| `security.access_gate` | `null` | Custom `SiteBackupAccessGateInterface` service (still wrapped by the login throttle / session rotation below) |
| `security.login_throttle.enabled` | `true` | (v1.17+) Per-client-IP rate limit of panel password attempts. Only while the gate reports `isProtectionEnabled()`. Exceeded → login page re-rendered with HTTP **429** + `Retry-After` and translated `panel.login.too_many_attempts`. Reset on a successful login. The session id is always rotated (`migrate(true)`) after a successful login, even when `enabled: false` |
| `security.login_throttle.limit` | `5` | Attempts per client IP per window (min `1`) |
| `security.login_throttle.interval` | `15 minutes` | Window, relative date format; validated at config time |
| `security.login_throttle.policy` | `fixed_window` | `fixed_window` \| `sliding_window` |
| `security.login_throttle.limiter` | `null` | Host `RateLimiterFactoryInterface` service id (e.g. `limiter.site_backup_login` from `framework.rate_limiter`). When set, `limit` / `interval` / `policy` / `cache_pool` are ignored |
| `security.login_throttle.cache_pool` | `null` | PSR-6 pool for the built-in limiter (`nowo_site_backup.login_limiter`). `null` = `cache.rate_limiter`, else `cache.app`; none available → `LogicException` at container compile time. Use a shared pool (Redis) with several app servers |
| `templates.restore_page` | `@NowoSiteBackupBundle/restore/page.html.twig` | Public restore loading page (variables `progress`, `defaultMessage`, `progressUrl`) |
| `templates.panel_index` / `templates.panel_login` / `templates.panel_history` | `@NowoSiteBackupBundle/panel/{index,login,history}.html.twig` | Panel pages |
| `templates.setup_wizard` / `templates.setup_admin` / `templates.setup_sample` / `templates.setup_database` / `templates.setup_done` / `templates.setup_token` | `@NowoSiteBackupBundle/setup/{wizard,admin,sample_data,database,done,token}.html.twig` | Setup wizard pages (layout: `setup.layout_template` / `templates.setup_layout`) |
| `setup.setup_token` | `null` | Shared secret for `/_setup` (`?token=` or `X-Setup-Token`), also required by `/_setup/api/progress` and `/_setup/api/advance`. **Mandatory on any internet-facing host**: with `null` anyone who reaches the host before setup completes can drive the wizard (DATABASE_URL, admin user, SQL import). Enforce it with `security_guard.enabled: true` |
| `setup.enabled` | `true` | Register the setup wizard (controller, routes, request gate). `false` = no `/_setup` routes |
| `setup.brand_name` | `Site Setup` | Brand shown in the wizard title / header |
| `setup.path_prefix` | `/_setup` | Wizard URL prefix; drives imported setup routes + site-gate exclusions |
| `setup.locale.in_path` | `never` | `never` \| `always` \| `both` — locale prefix on setup URLs (AuthKit-style) |
| `setup.locale.default` | `en` | Default `{_locale}` for localized setup routes |
| `setup.locale.enabled` | `[en]` | Allowed locale codes |
| `setup.locale.unlocalized` | `redirect` | When `in_path: both`: `serve` or `redirect` bare `/_setup`. With `serve`, `UnlocalizedDefaultLocaleSubscriber` re-applies `setup.locale.default` each request (stale route-cache / image-vs-runtime env). |
| `setup.layout_template` | `null` (bundle `setup/layout.html.twig`) | Host Twig shell; Twig global `nowo_site_backup_setup_layout_template`; blocks `nowo_ui_content` / `nowo_site_backup_content` |
| `templates.setup_layout` | `@NowoSiteBackupBundle/setup/layout.html.twig` | Same as `setup.layout_template` when set |
| `setup.progress_storage` | `filesystem` | `filesystem` (JSON `setup.progress_file`) \| `doctrine` (DBAL `progress_table`) \| `chain` (JSON + DB, prefer DB on load) \| `cache` (PSR-6 pool) \| `cache_doctrine` (cache + DB, prefer DB on load) |
| `setup.progress_table` | `nowo_site_backup_setup_progress` | DBAL table for doctrine/chain (runtime DDL — not Symfony Migrations) |
| `setup.progress_step_rows` | `true` | Upsert per-step journal when doctrine/chain (disabled automatically for filesystem) |
| `setup.progress_steps_table` | `nowo_site_backup_setup_step` | Per-step journal table (`profile` + `step_id` PK) |
| `setup.progress_cache_pool` | `cache.app` | PSR-6 pool service id when `progress_storage` is `cache` / `cache_doctrine` (e.g. a Redis pool shared by several app servers) |
| `setup.progress_cache_key` | `nowo_site_backup.setup_progress` | Cache item key for `cache` / `cache_doctrine` |
| `setup.progress_cache_ttl` | `86400` | Cache item TTL in seconds (min `60`) |
| `setup.required_marker_file` | `%kernel.project_dir%/var/site-backup/setup.required` | Marker that forces the wizard (written after a restore when `trigger_after_restore` is on) |
| `setup.done_marker_file` | `%kernel.project_dir%/var/site-backup/setup.done` | Marker written by the `marker` step (`write_done: true`) |
| `setup.require_done_marker` | `false` | Missing `setup.done` forces the wizard |
| `setup.short_circuit_when_done` | `true` | Skip all need detectors when `setup.done` exists or durable store `isDone()` (perf; set `false` to re-evaluate host detectors after done) |
| `setup.reopen_when_detector_requires` | `false` | (v1.15+) With `short_circuit_when_done`, still run detectors; if one requires setup, clear `setup.done` + durable `clearDone()` + reset progress so the wizard re-opens. **Cost:** one detector pass on every request (the built-in Doctrine detectors reuse a healthy answer for `worker_memo.schema_probe_ttl`; host detectors must stay cheap) |
| `setup.worker_memo.schema_probe_ttl` | `60` | (v1.16+) Seconds a **positive** answer is reused per worker (survives `kernel.reset`): cold-start `schemaExists()`, `doctrine_connect` (DB reachable), `doctrine_schema_empty` (tables present). Negative answers are never cached. `0` = probe every time |
| `setup.worker_memo.progress_ddl_ttl` | `3600` | (v1.16+) Seconds the Doctrine progress / step-journal `CREATE TABLE IF NOT EXISTS` is remembered across requests (a dropped table is still re-created on the first failing query). `0` = once per request (pre-1.16) |
| `setup.durable_done.enabled` | `false` | Register `SetupDbDoneRedirectSubscriber`; host replaces `DurableSetupDoneStoreInterface` alias |
| `setup.durable_done.redirect_target` | `/` | Redirect when durable done closes the wizard |
| `setup.cold_start.enabled` | `false` | Register cold-start schema gate subscriber + checker |
| `setup.cold_start.stop_propagation` | `true` | Stop `kernel.request` propagation after redirect / on safe paths |
| `setup.cold_start.safe_path_prefixes` | `/health/`, `/api/`, `/_wdt`, … | Allowed without redirect when schema missing |
| `setup.cold_start.require_application_tables` | `true` | An empty MySQL schema (only `nowo_site_backup_*` tables) stays cold-start until migrations create application tables |
| `setup.cold_start.mysql_host` / `setup.cold_start.mysql_port` / `setup.cold_start.mysql_user` / `setup.cold_start.mysql_password` / `setup.cold_start.mysql_database` | `null` / `3306` / `null` / `null` / `null` | Optional PDO fallback when DBAL is unavailable |
| `setup.progress_file` | `%kernel.project_dir%/var/site-backup/setup-progress.json` | JSON progress when filesystem/chain. v1.19+: excluded from archives and never overwritten by a restore |
| `setup.detectors.incomplete_progress` | `true` | Gate when progress phase is running/waiting/failed |
| `setup.detectors.marker` | `true` | `MarkerFileDetector`: `setup.required` present (or `setup.done` missing with `require_done_marker`) |
| `setup.detectors.doctrine_connect` | `false` | Require setup while the DBAL connection cannot connect |
| `setup.detectors.doctrine_schema_empty` | `false` | Require setup while the database has no application tables |
| `setup.php_binary` | `php` | PHP CLI used by console-based steps (`database_create`, `migrations`, `cache_clear`, `console`, `sample_data`). Use a real CLI binary next to a static FrankenPHP build |
| `setup.admin_provisioner` | `null` | Service id implementing `AdminUserProvisionerInterface` (`admin_user` step); `null` = `NullAdminUserProvisioner` |
| `setup.trigger_after_restore` | `true` | After a successful restore write `setup.required` with `post_restore_profile` so the wizard finishes the DB bootstrap |
| `setup.post_restore_profile` | `post_restore` | Profile used after a restore |
| `setup.default_profile` | `fresh_install` | Profile used when none is requested / stored |
| Custom setup-need detectors | — | `#[AsSetupNeedDetector]` + `SetupNeedDetectorInterface` → tag `nowo.site_backup.setup_need_detector`. Distinct from tab `checker:` / `SetupTabCheckerInterface`. |
| `setup.advance_mode` | `automatic` | `automatic` \| `manual` — chain auto tabs vs one Continuar per auto tab (CLI always chains) |
| `setup.profiles.*.advance_mode` | inherit | Optional per-profile override |
| `setup.profiles` / `setup.profiles.*.steps` | `fresh_install`, `post_restore`, `full_database`, `minimal` | Profiles keyed by name; `steps` is the legacy ordered step list (ignored when `tabs` is non-empty). Keys below |
| `setup.profiles.*.tabs` | `[]` | Preferred ordered flow (checker/runner; optional `template` — prefer bundle `@NowoSiteBackupBundle/...` or omit); when non-empty, ignores `steps`. Keys below |
| `setup.profiles.fresh_install` | includes `bootstrap_mode` + optional full SQL | Guided vs full dump; see [SETUP-WIZARD.md](SETUP-WIZARD.md) |

### Setup step / tab keys

Each entry of `setup.profiles.*.steps` and `setup.profiles.*.tabs` (and the nested `tabs.*.runner`) accepts:

| Key | Applies to | Notes |
| --- | --- | --- |
| `type` | all | Required for steps / tabs (`runner.type` optional): `requirements`, `bootstrap_mode`, `database_url`, `database_create`, `cache_clear`, `schema_update`, `migrations`, `sql_file`, `console`, `admin_user`, `sample_data`, `marker`, or `custom` (tab with `checker` / `runner`) |
| `id` / `label` | all | Step id (default `<type>_<index>`) and label / translation id (default per type) |
| `command` | `console` | Console command (string or list of arguments) |
| `commands` | `sample_data` | List of console commands |
| `when` | `sample_data` | `opt_in` (default: the operator chooses load / skip); any other value loads without asking |
| `paths` | `sql_file`, `bootstrap_mode` | SQL files (relative to the project dir) |
| `if_exists` | `sql_file` | Skip silently when no file exists (default `false`) |
| `roles` | `admin_user` | Roles of the created admin (default `[ROLE_SUPER_ADMIN]`) |
| `skip_if_admin_exists` | `admin_user` | Default `true` |
| `extensions` / `writable` / `require_tar` | `requirements` | PHP extensions (default `[json, pdo]`), writable dirs (default `[var]`), require `tar` (default `true`) |
| `optional` | `database_url` | Default `true` |
| `write_done` | `marker` | Write `setup.done` (default `true`) |
| `when_answer` | all | Map answer key => required value; the step is skipped unless all match |
| `checker` | tabs | Service id / FQCN implementing `SetupTabCheckerInterface` |
| `template` | tabs | Twig template for a custom `waiting_input` UI |
| `label_domain` / `description` | tabs | Translation domain (default `NowoSiteBackupBundle`) and subtitle translation id |
| `runner` | tabs | Nested step config (same keys) executed by a `custom` tab |

Profiles (`default_profile` / `profiles`) are **not** used for backup state: backup state is global (REQ-CFG-001 N/A for backups).

**Setup wizard profiles** (`setup.profiles.*`) are documented in [SETUP-WIZARD.md](SETUP-WIZARD.md) (`fresh_install`, `post_restore`, `minimal`, custom).

## Example

```yaml
nowo_site_backup:
    setup:
        durable_done:
            enabled: true
            redirect_target: '/'
        cold_start:
            enabled: true
            mysql_host: '%env(MYSQL_HOST)%'
            mysql_port: 3306
            mysql_user: '%env(MYSQL_USER)%'
            mysql_password: '%env(MYSQL_PASSWORD)%'
            mysql_database: '%env(MYSQL_DATABASE)%'
    process_timeout: 900
    css_framework: bootstrap5   # or: tailwind | foundation | custom | tabler | …
    backup:
        # Selective paths (default behaviour when the key is omitted):
        include_paths: [config, public/uploads, templates, src, composer.json]
        # Or back up the whole project and only skip noise:
        # include_paths: []
        # exclude_patterns: [tmp/*, .pnpm-store/*, .phpunit.cache/*, var/*]
        # Built-in dumper (DATABASE_URL, password via MYSQL_PWD):
        database_dump_command: 'php %kernel.project_dir%/bin/console nowo:site-backup:db-dump --no-debug'
    bump_time_limit: true          # setup + panel requests get set_time_limit(process_timeout)
    security_guard:
        enabled: true
        forbidden_setup_tokens: ['app-local-setup']           # the value in your .env.dist
        forbidden_password_hashes: ['$2y$12$…local-dev-hash…']
    restore:
        protected_paths: ['.env.local', 'var/site-backup', 'config/secrets']
    exclusions:
        paths: ['/health']
        path_prefixes: ['/api/health']
```

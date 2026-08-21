# Upgrading

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

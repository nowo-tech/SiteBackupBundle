# Usage

## Table of contents

- [Create an integral backup](#create-an-integral-backup)
- [Verify integrity](#verify-integrity)
- [Restore without breaking the site](#restore-without-breaking-the-site)
  - [Exclude routes during restore](#exclude-routes-during-restore)
- [Admin panel](#admin-panel)
- [Setup wizard](#setup-wizard)
- [Twig helpers](#twig-helpers)
- [Template overrides (REQ-TWIG-001)](#template-overrides-req-twig-001)
  - [Translations, theming and chrome blocks (v1.18+)](#translations-theming-and-chrome-blocks-v118)
- [Built-in database dumper](#built-in-database-dumper)
- [Database import note](#database-import-note)

## Create an integral backup

```bash
php bin/console nowo:site-backup:create -l "before-release"
```

The archive contains:

1. Files under configured `include_paths` (minus excludes)
2. Optional `database/dump.sql` when `database_dump_command` is set
3. `MANIFEST.json` with SHA-256 per file

Sidecar metadata: `var/site-backup/archives/<id>.meta.json`.

## Verify integrity

```bash
php bin/console nowo:site-backup:verify <id>
```

Checks archive SHA-256 and every MANIFEST entry after a temp extract.

## Restore without breaking the site

```bash
php bin/console nowo:site-backup:restore <id> -y
```

Flow:

1. Mark restore **active** (progress JSON) → visitors see the **loading page** (HTTP 503)
2. Verify integrity
3. Extract to a staging directory
4. Apply files with near-atomic replace; skip `protected_paths` and `var/site-backup/`
5. Copy DB dump (if any) to `var/site-backup/last-restore-dump.sql` for a controlled import
6. Mark restore **completed**

The loading page is rendered from **bundle Twig templates** and polls `/_site_backup/progress.json`, so a mid-restore filesystem swap does not blank the UI.

### Exclude routes during restore

```php
use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;

#[ExcludeFromRestore]
class HealthController
{
}
```

Or configure `exclusions.paths` / `path_prefixes` / `routes` / `ips`.

## Admin panel

Open `/_site_backup`. By default the panel requires Symfony Security (`access_roles: [ROLE_ADMIN]`) unless `security.allow_unauthenticated: true` (demos only). The ops **password gate** is an **additional** layer when `password_protection` is true.

```yaml
nowo_site_backup:
    security:
        access_roles: [ROLE_ADMIN]
        access_checker: null
        allow_unauthenticated: false
        password_protection: true
        password_hash: '%env(SITE_BACKUP_PASSWORD_HASH)%'
        login_throttle:            # v1.17+, defaults shown
            enabled: true
            limit: 5
            interval: '15 minutes'
            policy: fixed_window
            limiter: null          # or a host limiter, e.g. limiter.site_backup_login
            cache_pool: null       # null = cache.rate_limiter, else cache.app
```

Create, verify, restore, delete, and view history from the UI.

### Login throttle and session rotation

Every `SiteBackupAccessGateInterface` (the default password gate or your `security.access_gate`) is wrapped by `ThrottledSiteBackupAccessGate` (service `nowo_site_backup.access_gate`, the target of the interface alias):

- while `isProtectionEnabled()` is true, each password attempt consumes one token of a per-client-IP limiter; once exhausted the gate throws `TooManyRequestsHttpException` and the panel re-renders the login page with HTTP **429**, a `Retry-After` header and the translated `panel.login.too_many_attempts` message (the template also gets `throttled: true`);
- a successful login resets the limiter for that IP and rotates the session id (`migrate(true)`).

To reuse a limiter you already declare under `framework.rate_limiter`, set `login_throttle.limiter: limiter.<name>`. Host decorators of `SiteBackupAccessGateInterface` keep working (they decorate the wrapper); a decorator that only throttles and migrates the session can be removed.

## Setup wizard

When `var/site-backup/setup.required` exists (written automatically after restore, or via CLI), visitors are redirected to `/_setup`.

```bash
php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y
php bin/console nowo:site-backup:setup --profile=fresh_install \
  --admin-email=admin@example.com --admin-password='change-me'
php bin/console nowo:site-backup:setup-status
```

Bind your app’s admin provisioner:

```yaml
nowo_site_backup:
    setup:
        admin_provisioner: App\Setup\AdminUserProvisioner
```

See [SETUP-WIZARD.md](SETUP-WIZARD.md) for profiles, step types, and security.

## Twig helpers

Panel and setup bundle templates now render POST actions via Symfony Forms (`form_start`, `form_widget`, `form_end`) instead of raw HTML `<form>` tags. When you override these templates, keep using Symfony Form Types and preserve the legacy flat field names (`action`, `backup_id`, `label`, `database_url`, ...) for controller compatibility.


```twig
{% if nowo_site_backup_is_restoring() %}
  {# optional banner when not fully intercepted #}
{% endif %}
```

## Template overrides (REQ-TWIG-001)

Bundle templates are designed to be **reused as-is** across projects. Prefer updating the package and keeping `@NowoSiteBackupBundle/...` rather than copying screens into the app (REQ-UI-001: integrate without forking pages).

For setup / panel branding, set **`setup.layout_template`** and/or **`panel.layout_template`** to a host shell (Twig globals `nowo_site_backup_setup_layout_template` / `nowo_site_backup_panel_layout_template`). Do not fork `wizard` / `done` / `token` / panel pages. Restyle with `.nowo-ui-*` / `.nowo-site-backup-*` CSS.

**`css_framework` (REQ-UI-001):** set root key `css_framework` to match the host stack (`bootstrap5`, `tailwind`, `foundation`, `custom`, `tabler`, …). Twig global: `nowo_site_backup_css_framework`. Default standalone layouts use semantic `nowo-ui-*` markup (`custom`); when you point `layout_template` at a host/Bootstrap shell, set `css_framework` accordingly so host CSS and any future macros/partials align. Example:

```yaml
nowo_site_backup:
    css_framework: bootstrap5
    panel:
        layout_template: 'kit/site_backup_panel_layout.html.twig'
    setup:
        layout_template: 'kit/site_backup_setup_layout.html.twig'
```

Place overrides under `templates/bundles/NowoSiteBackupBundle/` with the **same relative path** as under `src/Resources/views/` only when necessary. The override **always wins** and **freezes** that `<subpath>` (vendor updates for that file are hidden until you delete or merge the override). Prefer `layout_template` / `css_framework` / CSS on `nowo-ui-*` for upgrade-safe customisation.

| Subpath | Role |
| --- | --- |
| `restore/page.html.twig` | Public loading page |
| `panel/layout.html.twig` | Default panel chrome (or `panel.layout_template`) |
| `panel/index.html.twig` | Dashboard content |
| `panel/login.html.twig` | Login content |
| `panel/history.html.twig` | History content |
| `setup/layout.html.twig` | Default setup chrome (or `setup.layout_template`) |
| `setup/wizard.html.twig` | Setup wizard content (`setup_body` block) |
| `setup/_bootstrap_form.html.twig` | Bootstrap mode form |
| `setup/_admin_form.html.twig` | Admin user step |
| `setup/_database_form.html.twig` | DATABASE_URL step |
| `setup/_sample_form.html.twig` | Sample data opt-in |
| `setup/_continue_form.html.twig` | Generic Continuar (custom tabs) |
| `setup/done.html.twig` | Setup finished |
| `setup/token.html.twig` | Optional setup token gate |

For setup tabs, put domain logic in YAML `checker` / `runner`; keep Twig in the bundle or in a thin override of a **partial** — see [SETUP-WIZARD.md](SETUP-WIZARD.md).

### Translations, theming and chrome blocks (v1.18+)

**Translations.** Every user-facing string of the panel (login, dashboard, history, flash / error messages, 403), the setup screens (wizard, token gate, done, page titles, form labels) and the restore page (phase label) goes through the `NowoSiteBackupBundle` domain, shipped in `en`, `es`, `fr`, `it`, `pt`, `de`, `nl`. `<html lang>` follows the request locale. Override wording with `translations/NowoSiteBackupBundle.<locale>.yaml` in the host (e.g. `panel.login.heading`, `setup.token.lead`) instead of forking templates. Controller messages fall back to English when no translator service exists.

**Theming with `--nowo-ui-*` tokens.** The standalone panel / setup layouts and `setup/_styles.html.twig` only read the UiKit tokens (`--nowo-ui-primary`, `--nowo-ui-primary-contrast`, `--nowo-ui-surface`, `--nowo-ui-surface-muted`, `--nowo-ui-border`, `--nowo-ui-border-strong`, `--nowo-ui-text`, `--nowo-ui-muted`, `--nowo-ui-danger`, `--nowo-ui-danger-bg`, `--nowo-ui-success-bg`, `--nowo-ui-success-text`, `--nowo-ui-radius`) with inline fallbacks. The setup layout's default dark palette is a token block (`nowo_site_backup_theme_tokens`); override it with your tokens (or empty it to inherit the host / `nowo-ui.css` values):

```twig
{# templates/setup/site_backup_setup.html.twig — set as setup.layout_template #}
{% extends '@NowoSiteBackupBundle/setup/layout.html.twig' %}
{% block nowo_site_backup_theme_tokens %}
    <style nonce="{{ app.request.attributes.get('csp_nonce') }}">
        :root { --nowo-ui-primary: #0f766e; --nowo-ui-primary-contrast: #fff; --nowo-ui-surface: #fff; --nowo-ui-surface-muted: #f5f5f4; --nowo-ui-text: #1c1917; --nowo-ui-muted: #57534e; --nowo-ui-border: #e7e5e4; }
    </style>
{% endblock %}
{% block nowo_site_backup_logo %}<img src="{{ asset('logo.svg') }}" alt="" width="20" height="20"> {% endblock %}
```

A host shell that does **not** extend the bundle layouts can still reuse the bundle component CSS and messages: `{% include '@NowoSiteBackupBundle/setup/_styles.html.twig' %}` (wizard forms, steps, progress bar, token form) and `{% include '@NowoSiteBackupBundle/panel/_flashes.html.twig' %}` (panel `error` / `success`, e.g. "Invalid password.", throttle message).

**Panel markup config.** `panel.row_actions_display` (`text` | `icon` | `icon_text` | `kit`) renders archive actions as UiKit `nowo-ui-action` buttons with icons; `panel.extra_classes` appends host classes to cards / tables / buttons / inputs. Buttons, inputs, tables, flashes and empty states use the UiKit macros for `css_framework`.

**Chrome blocks** (override in a template that `extends` the bundle one, and point `templates.*` at it — no copy of the markup):

| Template | Blocks |
| --- | --- |
| `panel/layout.html.twig` | `nowo_site_backup_html_lang`, `nowo_site_backup_html_class`, `nowo_site_backup_body_class`, `nowo_site_backup_panel_styles`, `nowo_site_backup_panel_brand`, `nowo_site_backup_panel_nav` (+ existing `nowo_ui_*`) |
| `panel/login.html.twig` | `nowo_site_backup_login_eyebrow`, `_heading`, `_intro`, `_notice`, `_form`, `_after` |
| `panel/index.html.twig` | `nowo_site_backup_create_section`, `_restore_section`, `_archives_section`, `_row_actions` (per row, `b`), `_logout`, `_confirm_script` |
| `panel/history.html.twig` | `nowo_site_backup_history_heading`, `nowo_site_backup_history_row` (per row, `h`) |
| `setup/layout.html.twig` | `nowo_site_backup_theme_tokens`, `nowo_site_backup_styles`, `nowo_site_backup_brand`, `nowo_site_backup_logo`, `nowo_site_backup_html_lang`, `nowo_site_backup_html_class`, `nowo_site_backup_body_class` |
| `setup/token.html.twig` | `nowo_site_backup_token_art`, `_title`, `_lead`, `_form` (`tokenForm`, GET `?token=`), `_howto` |

Example — branded panel login without a fork (`templates.panel_login: 'site_backup/login.html.twig'`):

```twig
{% extends '@NowoSiteBackupBundle/panel/login.html.twig' %}
{% block nowo_site_backup_login_eyebrow %}<p class="eyebrow">Ops</p>{% endblock %}
{% block nowo_site_backup_login_intro %}<p>{{ 'site_backup.login_intro'|trans }}</p>{% endblock %}
```


## Built-in database dumper

`nowo:site-backup:db-dump` (v1.16+) streams a MySQL / MariaDB dump of `DATABASE_URL` to stdout, so `backup.database_dump_command` does not need a hand-written `mysqldump` line:

```yaml
nowo_site_backup:
    backup:
        database_dump_command: 'php %kernel.project_dir%/bin/console nowo:site-backup:db-dump --no-debug'
        # MariaDB client against a MySQL server with a self-signed certificate on a private network:
        # database_dump_command: 'php %kernel.project_dir%/bin/console nowo:site-backup:db-dump --no-debug --skip-ssl-verify-server-cert'
```

- **Connection** comes from `DATABASE_URL` (the URL Doctrine uses), or `--url=`. Supported schemes: `mysql`, `mysql2`, `mariadb`, `pdo-mysql`; `?unix_socket=` becomes `--socket=`.
- **Password** travels in the `MYSQL_PWD` environment variable, never in argv (not visible in `ps`).
- **Flags:** `--single-transaction` (consistent InnoDB snapshot, no table locks) and `--no-tablespaces` (the app user usually lacks the `PROCESS` privilege; not needed to restore one schema).
- **No `--routines`:** the setup wizard / restore replays the file through PDO, which cannot parse `DELIMITER` blocks. If your schema has stored routines, write your own dump command and restore it with the `mysql` client.
- **`--skip-ssl-verify-server-cert`** is a **MariaDB client** option (Debian/Alpine images ship `mariadb-client`, whose `mysqldump` is MariaDB's). It keeps TLS but skips CA verification — use it only on a private network. The MariaDB 11+ client verifies server certificates by default, so a MySQL 8 server with its auto-generated self-signed certificate fails with `SSL certificate validation failure` without it. The Oracle MySQL client does not know this flag (use `-o=--ssl-mode=REQUIRED` instead).
- **Client / server mismatch:** the MariaDB client dumping a MySQL 8 server works for plain tables (`--no-tablespaces` avoids the `PROCESS` privilege error). The opposite mismatch — Oracle `mysqldump` 8 against MariaDB or MySQL 5.7 — needs `-o=--column-statistics=0`.
- `--binary=mariadb-dump` picks another binary; `-o=<option>` (repeatable) appends raw mysqldump options. Only SQL goes to stdout; diagnostics go to stderr. The exit code of mysqldump is propagated. Timeout: `process_timeout`.
- Use an absolute console path: `BackupArchiver` runs the command with the PHP process working directory (under FrankenPHP that is often `public/`).

## Database import note

Automatic SQL import during HTTP **restore** is **not** performed by default (it would risk locking/breaking the live connection). The dump is copied to `var/site-backup/last-restore-dump.sql`.

For cold bootstrap (no DB / empty schema / after restore), use the **Setup Wizard** — see [SETUP-WIZARD.md](SETUP-WIZARD.md) — which can import that dump, run migrations, seed roles, create a super-admin, and optionally load sample data through a declarative, idempotent pipeline.

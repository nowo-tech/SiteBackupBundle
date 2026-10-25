# Usage

## Table of contents

- [Create an integral backup](#create-an-integral-backup)
- [Verify integrity](#verify-integrity)
- [Restore without breaking the site](#restore-without-breaking-the-site)
  - [Exclude routes during restore](#exclude-routes-during-restore)
- [Admin panel](#admin-panel)
  - [Login throttle and session rotation](#login-throttle-and-session-rotation)
- [Setup wizard](#setup-wizard)
- [Twig helpers](#twig-helpers)
- [Web UI on the UiKit page contract (REQ-UI-003, 1.21+)](#web-ui-on-the-uikit-page-contract-req-ui-003-121)
  - [Overriding a page](#overriding-a-page)
  - [Translations](#translations)
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

Every UI form (bundle views and the demo) is a PHP Symfony Form Type rendered with `form_start`, the REQ-TWIG-003 child loop (`{% for child in form %}{% if not child.rendered %}{{ form_row(child) }}{% endif %}{% endfor %}`) and `form_end`; submit buttons may stay outside the loop. Host overrides and demos must not reintroduce raw `<form>` / `<input>` / `<textarea>` / `<select>` markup — `make check-no-raw-html-form` (part of `release-check`) fails when they appear (REQ-TWIG-005).


```twig
{% if nowo_site_backup_is_restoring() %}
  {# optional banner when not fully intercepted #}
{% endif %}
```

## Web UI on the UiKit page contract (REQ-UI-003, 1.21+)

The panel and setup pages are **UiKit page scaffolds** (`@NowoUiKit/page/{list,form,confirm,auth,wizard}`) rendered
inside your layouts. Theme them **once for every Nowo bundle** in `nowo_ui_kit` instead of per bundle:

```yaml
nowo_ui_kit:
    layouts:
        admin: 'layouts/admin.html.twig'   # panel: archives, history, create, confirm pages
        guest: 'layouts/guest.html.twig'   # panel sign-in, setup wizard, setup token
    theme:
        tokens: { primary: '#0f766e', radius: '0.5rem' }
        classes: { table: 'data-table' }
        bundles:
            NowoSiteBackupBundle: { classes: { card: 'shadow-sm' } }   # only for this bundle
```

- A host layout (or a one-file bridge into your base layout) renders the UiKit canonical blocks — see UiKit
  `docs/PAGE_CONTRACT.md#example-host-integration` or this bundle's `demo/symfony8/templates/layouts/`. Load
  `nowo-ui.css`, `nowo_ui_tokens()` and the UiKit scripts (`nowo-ui-confirm.js` drives the restore / delete dialogs).
- **The guest layout renders the setup wizard before the application has a database, a schema or users**: keep it
  free of database-backed Twig (current user menus, DB-driven navigation). UiKit's own guest layout qualifies.
- `nowo_site_backup.web_ui.layout_template` / `guest_layout_template` give these pages another layout than the UiKit
  ones (null by default).
- Page → scaffold, data blocks, view-model fields and per-page overrides: [TEMPLATES.md](TEMPLATES.md).
- Destructive actions keep CSRF: **restore** is type-to-confirm (type the backup id in the UiKit confirm dialog),
  **delete** opens the confirm dialog; without JavaScript the row actions link to full-page confirmations
  (`GET {path_prefix}/restore/{id}`, `GET {path_prefix}/delete/{id}`).
- The panel area navigation (Backups | History) is published to the UiKit area-nav registry as area `site_backup`
  (`nowo_ui_area_nav('site_backup', {current: path(…)})`, DashboardMenu).

### Overriding a page

Prefer theming UiKit (slots, partials, scaffold overrides in `templates/bundles/NowoUiKitBundle/page/`) — it applies to
every bundle. For a genuinely bundle-specific change point `web_ui.templates.<page>` at a thin template:

```yaml
nowo_site_backup:
    web_ui:
        templates:
            history: 'site_backup/history.html.twig'
```

```twig
{# templates/site_backup/history.html.twig #}
{% extends '@!NowoSiteBackup/panel/history.html.twig' %}
{% block nowo_ui_list_after %}<p>{{ 'site_backup.history_retention'|trans }}</p>{% endblock %}
```

The public **restore loading page** (`restore/page.html.twig`, `web_ui.templates.restore_page`) stays a self-contained
HTML document: it is served to every URL while the restore overwrites templates, config and assets.

### Translations

Every user-facing string of the panel, the setup screens and the restore page goes through the `NowoSiteBackupBundle`
domain (`en`, `es`, `fr`, `it`, `pt`, `de`, `nl`); form labels / help / placeholders are keys of the same domain in the
form types. Override wording with `translations/NowoSiteBackupBundle.<locale>.yaml` (e.g. `panel.login.heading`,
`setup.token.lead`) instead of forking templates. Controller messages fall back to English without a translator.

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

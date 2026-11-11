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
- [Backup options, formats and table rules (1.28+)](#backup-options-formats-and-table-rules-128)
  - [Host cron recipe](#host-cron-recipe)
  - [Messenger recipe](#messenger-recipe)
  - [Retention](#retention)
  - [Large databases](#large-databases)
  - [Restore drill (1.29+)](#restore-drill-129)
- [Built-in database dumper](#built-in-database-dumper)
- [Database import note](#database-import-note)
- [Standard host and DDD host](#standard-host-and-ddd-host)
  - [Standard host](#standard-host)
  - [DDD host](#ddd-host)

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

Bind your app’s admin provisioner (a service implementing `Setup\AdminAccountProvisionerInterface`, 1.35+):

```yaml
nowo_site_backup:
    setup:
        admin_provisioner: App\Setup\AdminUserProvisioner
```

```php
use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserData;

final readonly class AdminUserProvisioner implements AdminAccountProvisionerInterface
{
    public function __construct(private UserRepository $users, private UserPasswordHasherInterface $hasher) {}

    public function adminExists(): bool
    {
        return $this->users->count([]) > 0;
    }

    public function createAdminAccount(AdminUserData $admin): void
    {
        $user = new User($admin->email, $admin->roles);
        $user->setPassword($this->hasher->hashPassword($user, $admin->plainPassword));
        $this->users->save($user);
    }
}
```

Host step types: `#[AsSetupStep(type: '…')]` on a `SetupStepInterface` service (1.35+, see
[SETUP-WIZARD.md](SETUP-WIZARD.md#host-step-types-135)).

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

## Backup options, formats and table rules (1.28+)

`SiteBackupManagerInterface::createBackup()` (implemented by `SiteBackupManager`) takes a `BackupOptions` (use named arguments). The 1.27 call
`createBackup($label, $createdBy)` keeps working and uses `defaultOptions()` (`backup.default_format`,
`backup.compression_level`, configured paths and dump).

```php
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;

$artifact = $manager->createBackup(new BackupOptions(
    includeFiles: false,                                    // database only
    format: ArchiveFormat::Zip,                             // tar.gz | zip | sql.gz
    compressionLevel: 6,
    tableRules: [
        'event_log'          => TableRule::StructureOnly,  // CREATE TABLE without rows
        'messenger_messages' => TableRule::StructureOnly,
        'cache_items'        => TableRule::Skip,           // not in the dump at all
    ],
    label: 'Nightly',
    createdBy: 'scheduler',
    heartbeat: static fn (BackupProgress $p) => $lock->refresh(),   // every ~2 s and on each phase
));
```

- **No HTTP / session access** inside: call it from a controller, a console command or a Messenger handler. Options
  cannot be serialised; put ids in messages and build the options in the handler.
- **Formats.** `tar.gz` and `zip` hold the files, `database/dump.sql` and `MANIFEST.json` (SHA-256 per file). `sql.gz`
  holds the SQL only; the SHA-256 of the SQL is in the sidecar (`plaintext_sha256`). `zip` needs ext-zip.
  `nowo:site-backup:verify`, the panel and `nowo:site-backup:restore` handle all three.
- **Table rules** need `backup.database.dumper: builtin`. They are checked against the live schema before anything is
  dumped:
  - an unknown table, a view, a table in `backup.database.required_tables` and the migrations table are refused;
  - `Skip` is refused for a table that a foreign key of another table references, or that a view reads
    (`allowUnsafeTableRules: true` turns these two into warnings);
  - `StructureOnly` on a referenced table only warns (`$artifact->getWarnings()`): the referencing rows point at
    missing ids after a restore.

  **Prefer structure only.** After a restore into an empty database, a skipped table is missing (and migrations will
  not recreate it, because they are recorded as done). After a restore over an existing database, a skipped table
  keeps its current rows, mixing two points in time.
- **Secrets never enter an archive** (`.env*`, `config/secrets/**`, `var/secrets/**`, `config/jwt/**`, `*.key`,
  `*.pem`, …; see [CONFIGURATION.md](CONFIGURATION.md#secrets-floor-128)). Skipped paths are counted in the warnings.
  Keep keys and passwords in your deploy / key escrow.
- **Events:** `BackupStartedEvent` → `BackupCreatedEvent` or `BackupFailedEvent` (same `getOperationId()`). See
  `SiteBackupEvents`.
- **Concurrency:** one backup / delete / restore at a time (`BackupAlreadyRunningException`, exit code 75 for
  `nowo:site-backup:create`).

### Host cron recipe

```bash
php bin/console nowo:site-backup:create --no-files --format=sql.gz --structure-only=event_log --json
```

Options: `--format`, `--level`, `--no-database`, `--no-files`, `--path` (repeatable), `--skip-table`,
`--structure-only`, `--allow-unsafe-table-rules`, `--json`, `--label`, `--actor`.

### Messenger recipe

The message carries a plan id; the handler loads its settings, builds `BackupOptions` and calls `createBackup()`.
Turn Messenger's own retry off for that message when the host has its own retry policy; catch
`BackupAlreadyRunningException` and re-dispatch with a delay.

### Retention

`backup.retention.keep_last` / `max_age_days` prune `storage_dir` after each successful backup. For remote copies,
call `RetentionPlanner::plan($items, new RetentionPolicy(keepLast: 7), $justCreatedId, $now)`: a pure function that
never returns the backup just created or the newest successful one, and returns nothing when the run failed. It also
supports daily / weekly / monthly buckets (`keepDaily`, `keepWeekly`, `keepMonthly`).

### Large databases

- `backup.database.dumper: builtin` writes the dump straight to the staging file (no PHP in the pipe).
- `backup.work_dir` must have room for the staging copy and the archive (about 2× the archive); avoid a tmpfs.
- Restore: use `importer: client` on the `sql_file` step, or `nowo:site-backup:db-import <file> --force`; both stream
  the dump through the `mysql` client.

### Restore drill (1.29+)

`nowo:site-backup:drill <id> [--schema=nowo_backup_drill]` loads the backup's dump into a throwaway schema, compares row
counts and `CHECKSUM TABLE` values with the `table_stats` recorded at backup time (`recordTableStats: true`), and always
drops the schema. Exit 0 when the drill passes, 1 when tables differ, 2 on errors.

- **Use a restricted account: `backup.drill.database_url`.** Grant it only on the drill schema(s), for example:

  ```sql
  CREATE USER 'drill'@'%' IDENTIFIED BY '…';
  CREATE DATABASE nowo_backup_drill_home;  -- the database named in the URL
  GRANT ALL ON `nowo\_backup\_drill%`.* TO 'drill'@'%';  -- covers it and the drill schemas
  ```

  The dump is checked first (statements that switch or create databases, and statements aimed at `other_db.table`,
  are refused) and the client runs with `--one-database`, but that scan is best effort: names qualified inside a
  statement body (views, triggers, routines, sub-queries) are not parsed. Only the restricted account guarantees that
  a hostile dump cannot write to live data.
- **It is required for archives from elsewhere (EO-R1).** Drilling an imported archive (`importArchive()`, meta
  `imported_from`) without `backup.drill.database_url` is refused. Archives this site created itself can still be
  drilled with the live account.
- **The database named in the URL must already exist**: the client connects to it before it creates the drill
  schema, and a missing one fails with `ERROR 1049`. Point the URL at an existing database the account may use
  (`mysql://drill:…@db/nowo_backup_drill_home` above); the drill schema itself must not exist (it is created, never
  reused).

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

## Standard host and DDD host

SiteBackupBundle is infrastructure: an operational bounded context (backup, restore, setup wizard) with its own small
model (`BackupArtifact`, `BackupOptions`, `BackupHistoryEntry`, `RestoreProgress`, `SetupProgress`) and its own storage
(files under `var/site-backup`, optional DBAL tables `nowo_site_backup_setup_progress` and
`nowo_site_backup_setup_step` that it creates itself). It has no Doctrine ORM mapping and never references your
entities; the database is backed up physically (`mysqldump` or the built-in dumper on `DATABASE_URL`).

### Standard host

Zero config beyond what the sections above describe: install, import the routes, set `security.password_hash` and
`security.access_roles`, bind `setup.admin_provisioner`. Inject `Service\SiteBackupManagerInterface` in a controller
or command to create backups. Hosts written before 1.35 on `SiteBackupAccessGateInterface`, `ActorResolverInterface`
or `AdminUserProvisionerInterface` keep working unchanged; the container build reports one deprecation per such
service (see [UPGRADING](UPGRADING.md#unreleased)).

### DDD host

Nothing in your domain needs to know about the bundle. Put the code that talks to it in your infrastructure layer and
implement only the framework-free ports; none of their signatures carries a Symfony or Doctrine type:

| Port (config key) | Use it to |
|---|---|
| `Security\PanelAccessGateInterface` (`security.access_gate`) | the ops gate of the panel: `isAuthenticated()`, `authenticate()`, `logout()` take a `Security\PanelRequestContext` (client IP, user identifier, `PanelSessionInterface` session); always wrapped by the login throttle and the session id rotation |
| `Security\PanelActorResolverInterface` (`security.actor_resolver`) | the actor recorded for panel create / delete / restore, from a `PanelRequestContext` |
| `Setup\AdminAccountProvisionerInterface` (`setup.admin_provisioner`) | create your User aggregate from a typed `Setup\AdminUserData` (email, sensitive plain password, roles) |
| `Setup\SetupStepInterface` + `#[AsSetupStep(type: …)]` | your own setup step types |
| `setup.dbal_connection` | a separate connection for the bundle's setup tables and probes |

```php
use Nowo\SiteBackupBundle\Security\PanelAccessGateInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;

final readonly class OpsVaultGate implements PanelAccessGateInterface
{
    public function __construct(private OpsSecretVerifier $verifier) {} // your application port

    public function isAuthenticated(PanelRequestContext $context): bool
    {
        return $context->session?->get('ops_ok') === true;
    }

    public function authenticate(PanelRequestContext $context, #[\SensitiveParameter] string $password): bool
    {
        if ($context->session === null || !$this->verifier->verify($password)) {
            return false; // deny by default
        }
        $context->session->set('ops_ok', true);

        return true;
    }

    public function logout(PanelRequestContext $context): void
    {
        $context->session?->remove('ops_ok');
    }

    public function isProtectionEnabled(): bool { return true; }

    public function isMisconfigured(): bool { return !$this->verifier->isConfigured(); }
}
```

- *Triggering backups from your application layer*: define your own port and implement it with
  `Service\SiteBackupManagerInterface` (autowired to the `final` `SiteBackupManager`); double the interface in your
  infrastructure tests. A host alias or decorator of the interface also drives the restore lock page and the setup
  subscriber, so it must delegate `isRestoreActive()` / `getRestoreProgress()` unchanged:

  ```php
  // Application layer
  interface SiteSnapshotter { public function snapshot(string $reason): string; }

  // Infrastructure layer
  final class SiteBackupSnapshotter implements SiteSnapshotter
  {
      public function __construct(private SiteBackupManagerInterface $manager) {}

      public function snapshot(string $reason): string
      {
          return $this->manager->createBackup($reason, 'app')->getId();
      }
  }
  ```

  For Messenger, send ids/labels in the message and build `BackupOptions` in the handler (`BackupOptions` refuses
  serialisation on purpose).

- *Reacting to backups*: subscribe to the events in `Nowo\SiteBackupBundle\Event\SiteBackupEvents`
  (`BackupCreatedEvent`, `BackupFailedEvent`, `RestoreCompletedEvent`, `RestoreFailedEvent`, `RemoteBackupUploadedEvent`,
  `SetupCompletedEvent`, …) and translate them into your own notifications or audit records.
  Failure events carry a redacted, capped message (`BackupFailedEvent::getMessage()`, `RestoreFailedEvent::getError()`,
  `SetupStepFailedEvent::getError()`): forward that one. `RestoreFailedEvent::getException()` is the raw throwable for
  in-memory use only; it may quote the database password, so never persist or forward it.

- *Other ports*: `Security\SiteBackupAccessCheckerInterface` (`security.access_checker`, panel authorisation, receives
  the token user or `null`); `Storage\BackupHistoryStorageInterface`, `Storage\RestoreProgressStorageInterface`,
  `Setup\Storage\SetupProgressStorageInterface`, `Setup\DurableSetupDoneStoreInterface` (the bundle's own state);
  `Remote\BackupRemoteTargetInterface` (or `backup.remote.flysystem_storage`), `Crypto\ArchiveEncryptorInterface`;
  `Setup\SetupNeedDetectorInterface` with `#[AsSetupNeedDetector]`, `Setup\SetupTabCheckerInterface` with
  `#[AsSetupTabChecker]`.
- Mark controllers that must stay reachable during a restore with `#[ExcludeFromRestore]`.
- Gates, resolvers, provisioners and steps must be stateless (FrankenPHP worker mode, kernel reset false): keep the
  authenticated flag in the session, never in a property.
- The test suite boots such a host (`tests/Integration/Kernel/DddHostKernel.php`, adapters under
  `tests/Support/Ports/` with no Symfony import) and checks its gate answers exactly like a standard host's.

**Known limitations.** Database dump/import supports MySQL/MariaDB only.

# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.35.0](#to-1350)
  - [Deprecations (removed in 2.0)](#deprecations-removed-in-20)
  - [Migration](#migration)
- [To 1.34.1](#to-1341)
- [To 1.34.0](#to-1340)
- [To 1.33.0](#to-1330)
  - [To 1.33.0](#to-1330-1)
- [To 1.32.0](#to-1320)
- [To 1.31.0](#to-1310)
- [To 1.30.0](#to-1300)
- [To 1.29.1](#to-1291)
- [To 1.29.0](#to-1290)
- [To 1.28.0](#to-1280)
- [To 1.27.0](#to-1270)
- [To 1.26.0](#to-1260)
- [To 1.25.1](#to-1251)
- [To 1.25.0](#to-1250)
- [To 1.24.1](#to-1241)
- [To 1.24.0](#to-1240)
- [To 1.23.0](#to-1230)
  - [Panel and setup wizard require form CSRF protection at compile time](#panel-and-setup-wizard-require-form-csrf-protection-at-compile-time)
- [To 1.22.2](#to-1222)
- [To 1.22.1](#to-1221)
- [To 1.22.0](#to-1220)
- [To 1.22.0](#to-1220-1)
- [To 1.21.3](#to-1213)
- [To 1.21.2](#to-1212)
- [To 1.21.1](#to-1211)
- [To 1.21.0](#to-1210)
  - [Template contract](#template-contract)
  - [Configuration](#configuration)
- [To 1.20.0](#to-1200)
- [To 1.19.2](#to-1192)
- [To 1.19.1](#to-1191)
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

## To 1.35.0

From **1.34.1**:

```bash
composer update nowo-tech/site-backup-bundle
```

Target: **1.35.0**. From **1.34.x**: `composer update nowo-tech/site-backup-bundle`. No breaking change: hosts on the
old ports keep working; the new ports and options are opt-in, and every default is unchanged.

### Deprecations (removed in 2.0)

| Deprecated (1.35.0) | Replacement |
|---------------------|-------------|
| `Security\SiteBackupAccessGateInterface` (`isAuthenticated(Request)`, `authenticate(Request, string)`, `logout(Request)`) | `Security\PanelAccessGateInterface` (same methods with `PanelRequestContext`) |
| `Security\ActorResolverInterface::resolveActor(Request)` | `Security\PanelActorResolverInterface::resolveActor(PanelRequestContext)` |
| `Security\ActorResolverInterface::PANEL` | `Security\PanelActorResolverInterface::DEFAULT_ACTOR` (same value, `panel`) |
| `Setup\AdminUserProvisionerInterface::createAdmin(array $data)` | `Setup\AdminAccountProvisionerInterface::createAdminAccount(AdminUserData $admin)` |

Each configured host service (`security.access_gate`, `security.actor_resolver`, `setup.admin_provisioner`) that
implements only an old port triggers one deprecation when the container is built.

### Migration

1. **Custom access gate** (`security.access_gate`). Implement `PanelAccessGateInterface` and read the session from the
   context instead of the request; keep denying when there is no session or no credentials:

   ```php
   // before
   public function isAuthenticated(Request $request): bool
   {
       return $request->hasSession() && $request->getSession()->get('ops_ok') === true;
   }

   // after
   public function isAuthenticated(PanelRequestContext $context): bool
   {
       return $context->session?->get('ops_ok') === true;
   }
   ```

   `$context->clientIp` and `$context->userIdentifier` replace `$request->getClientIp()` and the token storage. The
   bundle still wraps your gate with the login throttle and rotates the session id after a successful login
   (`PanelSessionInterface::regenerateId()`). During the migration a class may implement both interfaces with a
   `Request|PanelRequestContext` parameter: the new port is called.
2. **Custom actor resolver** (`security.actor_resolver`). Implement `PanelActorResolverInterface` and read
   `$context->userIdentifier`. The panel still normalises the returned actor.
3. **Admin provisioner** (`setup.admin_provisioner`). Implement `AdminAccountProvisionerInterface`:
   `$data['email']` → `$admin->email`, `$data['password']` → `$admin->plainPassword()`, `$data['roles']` →
   `$admin->roles`. `adminExists()` is unchanged.
4. **Optional:** register your own step types with `#[AsSetupStep(type: '…')]` instead of wrapping commands in
   `console` steps; implement `InitialInstallOnlyStepInterface` on steps that create users, rewrite secrets or
   replace data. The built-in type names are reserved.
5. **Optional:** `setup.dbal_connection: doctrine.dbal.ops_connection` moves the setup tables and probes to another
   connection.

## To 1.34.1

From **1.34.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.34.0

From **1.33.0**: `composer update nowo-tech/site-backup-bundle`. No configuration change.

- **`RestoreFailedEvent::getError()` is redacted.** It returns the message with credentials masked (`***`) and cut at
  500 characters, the same text the `restore_failed` history entry, the restore progress and the panel now show. A
  listener that parsed the raw message can use the new `getException()` (the original throwable) in memory, but must
  not persist, serialize, forward (chat, e-mail, ticket, error tracker) or render it: it may contain the database
  password. `getExceptionClass()` is the safe way to branch on the failure type.
- **`SetupStepFailedEvent::getError()`** and the setup progress `error` / `message` are redacted the same way.
- **Old entries are not rewritten.** History entries and a restore / setup progress file written by 1.0–1.33 may still
  hold a raw message. Review the history file (`storage.history_file`, default `var/site-backup/history.jsonl`) for
  `restore_failed` lines, clear the restore status from the panel (or `SiteBackupManagerInterface::clearRestoreStatus()`)
  and rotate any credential that appears there.

## To 1.33.0

From **1.32.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

### To 1.33.0

Additive; nothing to change unless one of these applies:

- **Type-hint the interface.** Prefer `Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface` over the `final`
  `SiteBackupManager` in host services; the class is unchanged and still autowirable.
- **Custom access checker: review its non-null branch.** `canAccess()` now receives the user of the security token (it
  always got `null`), so the branch of your checker for a non-null user runs for the first time, for **every** logged-in
  user, admins or not. The panel can create, delete and **restore** backups (a restore overwrites the database and the
  files), so make sure that branch checks roles or permissions and does not treat "a user is logged in" as "allowed".
  Anonymous requests and apps without SecurityBundle still pass `null`.
- **Real actor for panel actions.** Set `security.actor_resolver: Nowo\SiteBackupBundle\Security\SecurityTokenActorResolver`
  to record the user identifier instead of `panel`. It becomes personal data in the history file, the events, the
  restore progress log, the backup sidecar / manifest `created_by` **and the metadata of remote copies** (remote
  publisher). Every resolved actor, from any resolver, is normalised: control characters (tab and newline included) and
  format characters are dropped, it is cut at 128 characters, and `panel` is recorded when nothing is left.

## To 1.32.0

From **1.31.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

Nothing to change for hosts that keep the defaults.

- **Re-opened wizard (IN-I1):** after `SetupWizardReopener::reopen()` (a `reopen: true` detector, `reopen_when_detector_requires`, or a host subscriber that calls it) the wizard no longer offers `admin_user`, `database_url`, `bootstrap_mode` or `sql_file` until it completes. If a reopen relied on one of them (for example to create a new admin), do that from the console, or run `php bin/console nowo:site-backup:setup-reset --initial -y` first to get a full initial install. Mark host steps that create users, rewrite secrets or replace data with `InitialInstallOnlyStepInterface`. On multi-node hosts keep `setup.done` and `setup.reopened` on shared or persistent storage: the marker is a local file, so a new node or a `var/` wipe during a reopen looks like an initial install. Its default path follows `done_marker_file`; hosts that set `done_marker_file` per test worker get a per-worker marker too. `console` and `sample_data` steps (for example a seed that creates demo identities) still run on a reopen: wrap them in a host step that implements `InitialInstallOnlyStepInterface` if they must not.
- **`reopen_when_detector_requires: true` (IN-L2):** the flag now re-opens only from the site gate on a main `GET` / `HEAD`; the wizard, `SetupDbDoneGuard`, the commands and `getReasons()` no longer re-open a done instance, a throwing detector is logged instead of answering 500, and `doctrine_connect` / `doctrine_schema_empty` no longer re-open once setup is done (a database outage does not reopen a live wizard). A host that counted on a database error to reopen must use its own detector, which must not fail true.

## To 1.31.0

From **1.30.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

Nothing to do: `setup.gate` defaults to `all` and no detector is tagged `reopen` by default, so the gate behaves as in
1.30. A host that redirected only guests and admins, or re-opened the wizard from its own `kernel.request` subscriber
when one signal came back (e.g. wiped catalogs), can drop that subscriber:

```yaml
nowo_site_backup:
    setup:
        gate: guests_and_admins       # signed-in users without gate_admin_role keep browsing
        gate_admin_role: ROLE_ADMIN
```

```php
#[AsSetupNeedDetector(priority: 60, reopen: true)]   // re-opens a done wizard when it reports a need
```

With `guests_and_admins` the gate runs after the firewall (priority 7): `access_control` decisions come first on those
requests. Review [SECURITY](SECURITY.md#gate-audience-setupgate-131).

**Before you tag a detector `reopen: true`:** it must return `false` when it cannot decide. Do **not** mark a detector
`reopen: true` while it returns `true` on errors (for example `catch (Throwable) { return true; }` as a "cold start"
guess): it would let any request during a database error reopen the wizard of a live site. Beacon's
`PlatformCatalogsSetupNeedDetector` does that today; change its `catch` to `return false` (or let it throw: a throwing
reopen detector counts as "not required" and is logged) before tagging it. The reopen pass runs only on a main
`GET` / `HEAD` outside the setup, panel and excluded paths, and a wizard step must clear the condition, or the wizard
reopens after every completion. Check `gate_admin_role` against the roles your admins really have: it is not
validated.

## To 1.30.0

From **1.29.1**:

```bash
composer update nowo-tech/site-backup-bundle
```

No configuration change, and valid zip backups extract exactly as before into a fresh directory, which is what every
flow of the bundle uses.

**Security fix for zip archives.** Versions 1.28.0, 1.29.0 and 1.29.1 checked `backup.extract_limits` on the sizes a zip
declares, not on what it writes, so a small zip could fill the disk under `backup.work_dir` (the system temp dir by
default) during verify, import, restore or a drill. Now the limits hold on the bytes written, and `UnsafeArchiveException`
can be raised during extraction as well as before it; the partial output is removed.

What you may notice:

- A zip that used to pass with a false declared size and more bytes than the limits allow is now refused.
- On PHP < 8.4, a plaintext zip with a wrong CRC or a damaged entry now fails with `zip extract failed.`, as it already
  did on 8.4+, instead of reaching the MANIFEST check.
- **Calling `BackupArchiver::extractTo()` yourself on a zip:** a target that already exists, or a link on the way to one,
  now fails with `zip extract failed.`. It used to overwrite, and to write through links. Extract into a fresh directory.
- **Passing a password with a plaintext archive:** `extractTo()`, `verifyIntegrity()`, `verifyBackup()`, `verify <id>` and
  the drill now refuse it (`CorruptArchiveException`, code `NOT_ENCRYPTED`), as import and `verify --file` already did.
  Drop the password, or pass `allowPlaintext` / `--allow-plaintext`. The drill has no such option: drop the password.
- `verifyIntegrity()` returns only the SHA-256 or signature error when one of those fails. It no longer extracts, so it
  does not add MANIFEST errors to it.
- An AES-256 zip with data after its end record, or a comment holding `PK\x05\x06`, is refused. The bundle never writes
  either.

**Security fix for AES-256 zips (1.29.1 only).** The authentication added in 1.29.1 could be turned off by bytes planted
in the zip comment, without the password. Until you upgrade, do not rely on it for an archive you cannot trust. Check the
archive with `--sha256` or a signature first, as below. A `sha256` you stored in a sidecar next to the archive proves
nothing if the same person can write both.

Until you can upgrade:

- Only import or verify zip archives you made yourself. Check them first with `nowo:site-backup:verify --file=<zip>
  --sha256=<known hash>`, or `--require-signature` with a signed sidecar (or set `backup.signing.required` for import and
  verify `--file`): those checks run before anything is extracted. Before this release, `verify <id>` and the panel
  extract even when the SHA-256 or the signature fails, so they are no protection for a zip planted in `storage_dir`.
- Do not pass untrusted uploads to `SiteBackupManager::importArchive()` or `verifyFile()`. The bundle itself has no upload
  route.
- Put `backup.work_dir` on its own size-limited file system or quota, so that a bomb cannot starve the rest of the host.
- Keep `storage_dir` writable by the application user only.

## To 1.29.1

From **1.28.0** or **1.29.0** (1.29.0 was never tagged; read "To 1.29.0" below for the 1.29 changes).

No configuration change. Encrypted zips are now authenticated entry by entry before extraction on every PHP version
(PHP < 8.4 used to ignore libzip's HMAC errors): an archive that was modified after it was written now fails verify /
import / restore with an integrity error instead of passing.

## To 1.29.0

Additive. Archive passwords: `zip` → AES-256 (ext-zip), `tar.gz` / `sql.gz` → age (`BackupOptions(password:
ArchivePassword::fromString(…), keyId: '…')`). The bundle never stores a password: keep them in your key ring, apart
from the backups. An encrypted archive is restored by importing it first (`importArchive()` /
`nowo:site-backup:import --password-env=…`), which stores a plaintext copy (`0600`) that is deleted after the restore
(`--keep-after-restore` to keep it). For remote copies implement `BackupRemoteTargetInterface` and use
`RemoteBackupPublisher` (see [REMOTE-TARGETS.md](REMOTE-TARGETS.md)).

Passwords on `tar.gz` / `sql.gz` need **age ≥ 1.3 with `age-plugin-batchpass` in the same directory** in the PHP image
(see [ENCRYPTION.md](ENCRYPTION.md#age-targz--sqlgz--image-requirement)); set `backup.encryption.age.binary` to its
absolute path. Without it those backups fail with a clear `FormatUnavailableException` (zip AES needs nothing more).
Optional: `backup.signing.*` (Ed25519; **once a key is configured, signatures are required** in verify / import /
prune unless `backup.signing.required: false`), `backup.remote.flysystem_storage`, `recordTableStats` +
`nowo:site-backup:drill` (with `backup.drill.database_url` pointing at an account limited to the drill schemas, whose
URL database already exists; **required to drill imported archives**, see [USAGE.md](USAGE.md#restore-drill-129)).
Set `backup.encryption.age.binary` to an absolute path: a bare name is resolved on PHP's PATH once and logged, and a
relative or world-writable location is refused. Enable signing for remote targets that other writers can reach
(unsigned sidecars can steer prune).

Behaviour to know: with a password, import and `verify --file` refuse archives that are not encrypted (exit 5;
`--allow-plaintext` to accept); `publish()` refuses a key that already exists; `prune()` ignores sidecars dated in the
future, sidecars whose id / size / sha256 do not match the object, and unsigned sidecars when signatures are required;
the drill refuses dumps that switch database (`USE`, `CREATE DATABASE`, `--databases` output); `max_work_factor`
defaults to 20.

## To 1.28.0

From **1.27.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

Everything new is opt-in, except the **secrets floor** (owner decision: no secret ever goes into an archive).

**Behaviour changes to check:**

- `.env*`, `config/secrets/**`, `var/secrets/**`, `config/jwt/**` and every `*.key` / `*.pem` (and the other floor
  paths listed in `CONFIGURATION.md`) are never archived and never written by a restore, whatever `include_paths` says.
  Only the committed `.env.dist` / `.env.example` at the project root are kept. A restore of an older archive that
  contains `.env` or vault keys skips them (logged as "Skipped secret path"). Keep `.env.local`, the vault decrypt
  keys and encryption keys in your deploy / key escrow, not in backups.
- The default `include_paths` no longer lists `.env`. If you list it (or another floor path) explicitly, remove it:
  the container build triggers a deprecation and the file is skipped.
- Staging moved from the system temp dir to `backup.work_dir` (`%kernel.project_dir%/var/site-backup/work`, `0700`).
  Point it at a large disk if `var/` is small; avoid a tmpfs.
- Backups, deletes and restores take an operation lock. With several app containers on one database, install
  `symfony/lock` with a shared store (Redis, DBAL) so `lock.factory` is used; otherwise the lock is an `flock()` in
  `storage_dir` (one host).
- `backup.preflight_disk_check` (on by default) refuses a backup when free space is below about three times the last
  archive. Set it to `false` to keep the 1.27 behaviour.
- A failed dump now throws `DumpFailedException` (still a `RuntimeException`).
- Symlinks pointing outside the project are no longer archived (a warning counts them), and hard links to secret
  files are skipped. `backup.include_paths` entries with `..` or an absolute path fail the container build.
- Restore applies only the regular files listed in the archive's MANIFEST and refuses archives with link entries.
- `backup.extract_limits` (100 GiB, 200 000 entries, ratio 200 above 64 MiB by default) applies to verify / restore.
- The `mysql` client importer needs a client that can disable client commands: Oracle 8.0.43+ / 8.4.6+ / 9.4+
  (`--commands=OFF`) or MariaDB 10.5.25+ / 10.6.18+ / 10.11.8+ / 11.4.2+ (`--sandbox`; Debian's current
  `mariadb-client` qualifies).
- Links to directories outside the project (e.g. Deployer / Capistrano `shared/` uploads) are skipped with a warning
  (logged at notice): back those directories up separately. Hard links are compared with the secret files of the
  project tree (excluding `vendor/`, `node_modules/`, `var/cache/`, `var/log/`); a hard link to a secret stored
  elsewhere cannot be recognised.
- `backup.work_dir` must not be a symlink and must belong to the PHP user.

**Recommended for large databases:**

```yaml
nowo_site_backup:
    backup:
        database:
            dumper: builtin                      # mysqldump straight into the staging file; needed for table rules
            skip_ssl_verify_server_cert: true    # only if your db-dump command used it
            required_tables: [user]              # never skipped / dumped without rows
    setup:
        profiles:
            post_restore:
                steps:
                    # …
                    - { type: sql_file, paths: ['var/site-backup/last-restore-dump.sql'], if_exists: true, importer: client }
```

`importer: client` streams the restore dump through the `mysql` client; the 1.27 `pdo` importer reads the whole file
into memory.

## To 1.27.0

From **1.26.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

Security hardening (REQ-SEC-004). The setup wizard is **default-deny**.

**No action needed** when the host sets `nowo_site_backup.setup.setup_token` (or disables the wizard, e.g.
`when@prod: setup.enabled: false`) or has already completed setup (`setup.done` / durable store): completed hosts keep
the wizard closed (302 `/`), now even if the progress file is lost.

**Action needed** when the host used the web wizard **without** `setup_token`:

1. First install / after a restore: on the server run
   `php bin/console nowo:site-backup:setup-token --open` (add `=<profile>` to pick one, e.g. `--open=post_restore`) and
   open the printed `/_setup?token=…` link. Without `--open` the token is issued but the wizard stays closed unless a
   detector (or `setup.required`) reports a need. The token is one-time: it is deleted when the wizard completes
   (`--revoke` deletes it earlier). A host already mid-setup sees a 403 gate that says to run this command.
2. Or set a static secret: `setup_token: '%env(SITE_SETUP_TOKEN)%'` with a long random value.
3. Dev / demo only: `setup.allow_without_token: true` keeps the token-less page; it must be a literal boolean and the
   container refuses it unless `kernel.debug` is true. `/_setup/api/advance` always needs a token.

Behaviour changes to check in host tests / scripts:

- `GET /_setup` no longer starts a run (it does not write `setup.required` or progress); the first step runs on the
  Continue POST. A test that asserted `setup.required` after a GET must POST first (or mark it itself).
- `/_setup` and `/_setup/api/advance` answer 302 `/` / 404 whenever setup is not required (before, `/_setup` stayed
  open until a run completed). `/_setup/api/progress` is unchanged (token-gated, read-only). Scripts that drive `api/advance` must send the token
  (`X-Setup-Token` or `?token=`) even when `setup_token` is null (use the one-time token).
- `DATABASE_URL` values whose database, user, host or `unix_socket` start with `-` or contain control characters are
  rejected by `nowo:site-backup:db-dump` and by the setup `database_url` step; the `mysqldump` argv now ends with
  `-- <database>`.
- Backup / verify / restore staging directories are `<tmp>/nowo-site-backup[-verify|-restore]-<id>-<32 hex>` (`0700`)
  instead of the fixed `<tmp>/nowo-site-backup-<id>`.

## To 1.26.0

`nowo-tech/form-kit-bundle` (`^2.14`) is now required. Register `Nowo\FormKitBundle\NowoFormKitBundle` in
`config/bundles.php` if Flex did not (the forms still render without it, but the profile is only applied with it).
Templates, block names, form names, field names and ids are unchanged: no override needs an update. To theme the panel
and setup forms, configure `nowo_form_kit.profiles.site_backup` (see [CONFIGURATION](CONFIGURATION.md#formkit-profile-req-twig-005)).

## To 1.25.1

No change needed when `config/routes.yaml` imports `@NowoSiteBackupBundle/Resources/config/routes.yaml` (see [INSTALLATION](INSTALLATION.md)). If the panel answered on your site without that import (Symfony >= 7.3 with the default `controllers: { resource: routing.controllers }`), add the import: the unprefixed copies (`/`, `/create`, `/restore/{id}`…) are gone.

## To 1.25.0

From **1.24.1**:

```bash
composer update nowo-tech/site-backup-bundle
```

From **1.24.1**:

- **`setup.default_profile` is validated (REQ-CFG-001).** It must be a key of `setup.profiles`; otherwise the
  container no longer compiles (`Invalid nowo_site_backup.setup.default_profile "fresh_install": it must be a key of
  nowo_site_backup.setup.profiles (defined: …)`). Such a configuration already failed at runtime ("Unknown setup
  profile") as soon as no profile was requested. Most often the host declared `setup.profiles` (which **replaces**
  the bundle defaults) without `fresh_install`: set `setup.default_profile` to one of your profiles, or declare
  `fresh_install`. Not checked while `setup.enabled: false`.
- **Subprocess timeouts (REQ-RUNTIME-001).** `process_timeout` is now also the idle timeout, and on expiry the whole
  process tree is killed (no orphan `mysqldump`). A timed-out database dump fails with
  `RuntimeException('Database dump timed out after N seconds (process_timeout).')` instead of Symfony's
  `ProcessTimedOutException` (whose message repeated the dump command); `tar` and console steps still throw
  `ProcessTimedOutException`. Check that PHP / proxy / FrankenPHP deadlines stay above `process_timeout`
  ([CONFIGURATION.md](CONFIGURATION.md#timeouts-req-runtime-001)).
- **Logging (REQ-OBS-001).** Backups, restores, deletions and setup runs are logged on the Monolog channel
  `site_backup` (see [SECURITY.md](SECURITY.md#logging)). Route or silence it in `monolog.yaml` if needed.
- **Clock (REQ-DI-001).** Time-dependent services use the framework `clock` service (`symfony/clock`, now a
  dependency). No change unless you replaced that service.
- **Attribute wiring (REQ-SF-003).** Detectors, the time-limit listener, the production guard / cold-start / durable
  done subscribers and the controllers are registered through attributes + autoconfigure instead of manual tags.
  Service ids, priorities and behaviour are unchanged. A host that redefined one of these services with
  `autoconfigure: false` must now tag it itself.

## To 1.24.1

From **1.24.0**: no change needed (development dependencies updated).

## To 1.24.0

From **1.23.0**: no change needed. The tabs carry icons that show with NowoUiKitBundle **2.17** or later (older 2.x versions ignore
them), and only when the host sets `data-tab-style="icon-text"` or `"icon"`.

## To 1.23.0

From **1.22.2**:

```bash
composer update nowo-tech/site-backup-bundle
```

### Panel and setup wizard require form CSRF protection at compile time

While the panel or the setup wizard (on by default) is enabled, the container no longer compiles without form CSRF protection (`LogicException`: "require CSRF protection"), including `framework.form.csrf_protection: false`. Standard Flex hosts (`symfony/security-csrf` installed, `framework.csrf_protection` enabled) need no change. Otherwise enable `framework.csrf_protection: true` and form CSRF — also in the `test` environment of functional suites that boot the bundle — or disable `web_ui.enabled` and `setup.enabled`.

## To 1.22.2

From **1.22.1**:

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.22.1

From **1.22.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

Patch release. `setup.token_gate.illustration.webp` no longer defaults to the derived `.webp` sibling: a PNG/JPEG-only host got a broken `<source>`. If your illustration has a WebP next to it, set `webp: true` (or the WebP path).

## To 1.22.0

From **1.21.3**:

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.22.0

From **1.21.x**:

```bash
composer update nowo-tech/site-backup-bundle
```

No breaking change: without `setup.token_gate` the token gate renders exactly as in 1.21 (no illustration, the three
default how-to steps).

**Retire a copied token page.** If your app overrides `web_ui.templates.setup_token` only to add an illustration and
its own copy (e.g. `templates/kit/site_backup_setup_token.html.twig`), delete the template and its
`web_ui.templates.setup_token` line, then configure the gate:

```yaml
# config/packages/nowo_site_backup.yaml
nowo_site_backup:
    setup:
        token_gate:
            illustration:
                src: 'illustrations/error-403.png'
                webp: true                            # only if illustrations/error-403.webp exists (1.22.1+: opt-in)
                width: 896
                height: 597
                alt: 'error.403.image_alt'
            # Optional: keep the app wording (keys of translation_domain, %path% = <prefix>?token=…)
            # howto_steps: ['setup.token.step_env', 'setup.token.step_url', 'setup.token.step_header']
            translation_domain: messages             # domain of alt + howto_steps (default NowoSiteBackupBundle)
```

The page title, lead, field placeholder, submit label and the disclosure summary stay in the `NowoSiteBackupBundle`
domain (`setup.token.*`): to keep your own wording, copy those keys to `translations/NowoSiteBackupBundle.<locale>.yaml`.
Selectors of the bundle page: `[data-testid="nowo-sbb-token-gate"]` (auth panel),
`[data-testid="nowo-sbb-token-illustration"]` (art), `img.nowo-sbb-token__img`, `details.nowo-sbb-token__howto`
(`[data-testid="nowo-sbb-token-howto"]`), field `input[name="token"]` in a GET form; update functional tests that
asserted classes of the copied template.

## To 1.21.3

From **1.21.2**:

```bash
composer update nowo-tech/site-backup-bundle
```

No code change. If you copied an override from the docs that extends the `@!NowoSiteBackupBundle` namespace, change it to
`@!NowoSiteBackup/…` (e.g. `{% extends '@!NowoSiteBackup/panel/history.html.twig' %}`); the old name never resolved.
Includes and non-overriding extends can keep `@NowoSiteBackupBundle/…`.

## To 1.21.2

From **1.21.1**:

```bash
composer update nowo-tech/site-backup-bundle
```

## To 1.21.1

From **1.21.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

Requires UiKit **^2.2** (`composer require nowo-tech/ui-kit-bundle:^2.2`). No configuration change. What hosts may
notice:

- **Setup wizard progress**: the bar is the UiKit `progress` partial (`.nowo-ui-progress-field`, `<progress
  id="nowo-sbb-setup-progress">`, the step message as its `<label>`). Host CSS targeting `.nowo-sbb-progress` or
  `.nowo-sbb-setup__progress` should theme the UiKit slots `progress` / `progress_meter` instead.
- **Wizard layout**: `SetupPages` builds `WizardPage(guest: true)`; with `web_ui.guest_layout_template: null` the
  scaffold extends `nowo_ui_kit.layouts.guest` itself. Code calling `SiteBackupView::guestLayout()` keeps working
  (deprecated); prefer `guestLayoutOverride()` plus `guest: true`.
- **Default theme** (UiKit 2.2): table headers in small uppercase labels, striped rows, subtle badges, solid red
  confirm button. Hosts that styled danger confirms through `btn_danger` set `btn_danger_solid` too (UiKit UPGRADING
  "Template contract (2.2)").
- **Host layouts** with their own script list: include `{{ include(nowo_ui_partial('scripts')) }}` instead of listing
  the UiKit runtimes.

## To 1.21.0

From **1.20.0**:

```bash
composer update nowo-tech/site-backup-bundle
```

The panel and setup pages move to the UiKit 2.1 page contract (REQ-UI-003). Steps:

```bash
composer require nowo-tech/ui-kit-bundle:^2.1
composer update nowo-tech/site-backup-bundle
php bin/console cache:clear
```

1. Point `nowo_ui_kit.layouts.admin` and `.guest` at your layouts (or one-file bridges into your base layout) that
   render the UiKit canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`
   (admin), `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`), load
   `nowo-ui.css` + `nowo_ui_tokens()` and the UiKit scripts (`nowo-ui-confirm.js` for the restore / delete dialogs).
   Example: UiKit `docs/PAGE_CONTRACT.md#example-host-integration` or `demo/symfony8/templates/layouts/`.
2. **The guest layout renders the setup wizard on a cold start** (no database, no schema, no users): it must not call
   database-backed Twig (user menus, DB navigation). Use UiKit's guest layout or a minimal bridge if yours does.
3. Remove `panel.layout_template` / `setup.layout_template` (or move them to `web_ui.layout_template` /
   `web_ui.guest_layout_template` only to give these pages other layouts — they must render the same blocks).
4. Move the deprecated keys below and delete page copies under `templates/bundles/NowoSiteBackupBundle/`.

### Template contract

What hosts may notice:

- **Pages → scaffolds.** `panel/index` and `panel/history` extend `@NowoUiKit/page/list.html.twig`, new
  `panel/create` extends `page/form`, new `panel/confirm` extends `page/confirm`, `panel/login` and `setup/token` extend
  `page/auth`, `setup/wizard` and `setup/done` extend `page/wizard`. Each page receives `page` (UiKit view model) plus
  the context listed in [TEMPLATES.md](TEMPLATES.md). The 1.x panel variables (`backups`, `history`, `error`,
  `success`, `csrfToken`, `createForm`, `clearRestoreForm`, `logoutForm`, `backupForms`, `loginForm`,
  `protectionEnabled`, `misconfigured`, `pathPrefix`) are gone (`messages`, `progress`, `clear_restore`, `path_prefix`,
  `throttled` remain); the setup pages keep `progress`, `steps`, `currentStep`, `reasons`, `error`, `wizardForm`,
  `advanceMode`, `progressUrl`, `pathPrefix`, `brandName` (`tokenForm` is `page.form`).
- **Removed templates:** `panel/layout.html.twig`, `panel/_flashes.html.twig`, `setup/layout.html.twig`,
  `setup/_styles.html.twig`, `setup/admin.html.twig`, `setup/database.html.twig`, `setup/sample_data.html.twig`.
  New: `_messages.html.twig`, `panel/_restore_status.html.twig`, `panel/create.html.twig`, `panel/confirm.html.twig`,
  `panel/_legacy_layout.html.twig` and `setup/_legacy_layout.html.twig` (deprecated bridges).
- **Blocks:** the `nowo_site_backup_*` blocks are gone. Overrides move to the UiKit data blocks or to the theme:

  | 1.x block | 1.21 |
  | --- | --- |
  | `nowo_site_backup_panel_content`, `nowo_site_backup_content` (in your layout) | `nowo_ui_content` (render the canonical blocks; 1.x layouts with only these blocks are bridged, deprecated) |
  | `nowo_site_backup_html_lang`, `_html_class`, `_body_class`, `_panel_styles`, `_panel_brand`, `_panel_nav`, `_theme_tokens`, `_styles`, `_brand`, `_logo` | your layout + `nowo_ui_kit.theme.tokens`; the Backups \| History nav is `page.header.tabs` |
  | `nowo_site_backup_create_section` | the create page (`panel/create`, `nowo_ui_form_body`) |
  | `nowo_site_backup_restore_section` | `panel/_restore_status.html.twig` in `nowo_ui_flashes` |
  | `nowo_site_backup_archives_section`, `nowo_site_backup_row_actions`, `nowo_site_backup_history_row`, `nowo_site_backup_history_heading` | `nowo_ui_list_row` (cells), `nowo_ui_list_head`, `nowo_ui_list_after`; row actions from the view model (`row_actions` partial / slots) |
  | `nowo_site_backup_logout`, `nowo_site_backup_confirm_script` | header action / UiKit confirm `<dialog>` (`nowo-ui-confirm.js`) |
  | `nowo_site_backup_login_*` | `nowo_ui_auth_fields`, `nowo_ui_auth_actions`, `nowo_ui_auth_footer` |
  | `nowo_site_backup_token_*` | `nowo_ui_auth_footer` (how-to), `page/auth` regions |
  | `setup_body`, `nowo_site_backup_title` | `nowo_ui_wizard_step`, `nowo_ui_document_title` |

- **Confirmations:** the `data-nowo-confirm` attribute and its `window.confirm()` listener are gone. Restore opens the
  UiKit confirm dialog and asks to type the backup id; delete opens the confirm dialog. Without JavaScript the
  `<noscript>` links open `GET {path_prefix}/restore/{id}` and `GET {path_prefix}/delete/{id}` (POST with CSRF).
- **Create:** the inline create form moved to `GET {path_prefix}/create` (header action "Create backup"); it still
  POSTs `action=create` to the panel index. A failed create answers 422 with the form.
- **Restore status** shows only once a restore ran (phase ≠ idle), above the archives, with "Clear status".
- **Markup / test ids:** new `data-testid` values `nowo-sbb-archives`, `nowo-sbb-create`, `nowo-sbb-create-form`,
  `nowo-sbb-logout`, `nowo-sbb-verify`, `nowo-sbb-restore`, `nowo-sbb-delete`, `nowo-sbb-restore-status`,
  `nowo-sbb-restore-confirm`, `nowo-sbb-delete-confirm`, `nowo-sbb-history`, `nowo-sbb-login`, `nowo-sbb-setup-wizard`,
  `nowo-sbb-setup-done`, `nowo-sbb-setup-reasons`, `nowo-sbb-setup-log`, `nowo-sbb-nav-panel`, `nowo-sbb-nav-history`;
  kept `nowo-sbb-token-gate`; `data-phase` on the restore status and the wizard root. The auto-advance form id is
  `nowo-sbb-auto-advance` (was `auto-advance`).
- **Classes:** markup carries `nowo-ui-*` classes plus the theme slot classes of `nowo_ui_kit.css_framework`; the
  1.x `card`, `danger`, `flash ok|err`, `steps span.on|done`, `bar`, `warn`, `log`, `actions`, `secondary` classes are
  gone.
- **Translations:** new keys `panel.nav.label`, `panel.index.empty_hint`, `panel.history.intro`, `panel.create.intro`,
  `panel.confirm.*`, `panel.login.intro`, `panel.error.backup_not_found`, `setup.wizard.progress`,
  `setup.database.field_label`, `setup.done.step` — add them if you override the `NowoSiteBackupBundle` domain.
- **Restore loading page** (`restore/page.html.twig`) is unchanged: it stays a self-contained page.

### Configuration

| 1.x key | 1.21 | Action |
| --- | --- | --- |
| `panel.enabled`, `panel.path_prefix` | deprecated aliases | `web_ui.enabled`, `web_ui.path_prefix` (the canonical key wins); parameter `nowo.site_backup.panel.path_prefix` keeps the value |
| `panel.layout_template`, `templates.panel_layout` | deprecated aliases | `web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`; the 1.x default `@NowoSiteBackupBundle/panel/layout.html.twig` maps to null) |
| `setup.layout_template`, `templates.setup_layout` | deprecated aliases | `web_ui.guest_layout_template` (null = `nowo_ui_kit.layouts.guest`). Now also used by the panel sign-in |
| `templates.panel_index`, `panel_login`, `panel_history`, `setup_wizard`, `setup_token`, `setup_done`, `restore_page` | deprecated aliases | `web_ui.templates.index`, `login`, `history`, `setup_wizard`, `setup_token`, `setup_done`, `restore_page` |
| `templates.setup_admin`, `setup_sample`, `setup_database` | deprecated, no effect | the steps render inside `setup/wizard.html.twig` |
| `css_framework` (root) | deprecated (default `null`) | set `nowo_ui_kit.css_framework`; when set here it is still seeded into UiKit. **Changed:** without it the bundle no longer forces `custom` on UiKit |
| `panel.row_actions_display` | deprecated (default `null`) | `nowo_ui_kit.row_actions_display`; `text` \| `icon` \| `icon_text` still force the Verify / Restore / Delete display (`kit` = UiKit) |
| `panel.extra_classes` | deprecated, bridged | prepended into `nowo_ui_kit.theme.bundles.NowoSiteBackupBundle.classes`: `card` → `card`, `table` → `table`, `input` → `input`, `button` → `btn_primary`, `btn_secondary`, `action` (appended to the framework defaults, as in 1.x). Your own `theme.bundles` config wins |

## To 1.20.0

- With `security_guard.enabled: true`, the panel `password_hash` must now be a real password hash (`password_get_info()`); set `security_guard.check_password_hash_shape: false` only if you use a custom hash format. A hash cut by Compose interpolation now fails at boot — single-quote it in the env file (`SITE_BACKUP_PASSWORD_HASH='$2y$…'`).
- Optional: move host secret / DSN checks into `security_guard.extra_secrets` and `security_guard.dsn_credentials` (see CONFIGURATION.md).

## To 1.19.2

From **1.19.1** — setup route loader can load again in the same process.

```bash
composer update nowo-tech/site-backup-bundle
```

- **Setup routes in worker / watch mode.** Rebuilding the router in the same process (FrankenPHP worker, dev `--watch`) no longer fails with `SiteBackup setup routes already loaded.` No configuration change required. Code that relied on the `RuntimeException` from a second `SetupRouteLoader::load()` call must drop that expectation.

## To 1.19.1

From **1.19.0** — restore progress heartbeats during hashing, extraction and apply.

```bash
composer update nowo-tech/site-backup-bundle
```

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

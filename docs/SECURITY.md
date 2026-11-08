# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Setup access: default-deny (1.27+)](#setup-access-default-deny-127)
  - [Gate audience (`setup.gate`, 1.31+)](#gate-audience-setupgate-131)
- [Backups never contain secrets (1.28+)](#backups-never-contain-secrets-128)
- [Secrets & cryptography](#secrets--cryptography)
- [Production secrets guard (opt-in, v1.16+)](#production-secrets-guard-opt-in-v116)
- [CSP nonces](#csp-nonces)
- [Logging](#logging)
- [Dependency and updates](#dependency-and-updates)
- [Firewall / `access_control` (host app)](#firewall--access_control-host-app)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Scope

Covers backup creation, integrity verification, restore orchestration, restore loading page, the admin panel, and the setup wizard. Does **not** cover host OS backups, object-storage offsite replication, or automatic production DB failover.

## Attack surface

- Admin panel (`/_site_backup`) — create/delete/restore
- Progress JSON endpoint (intentionally reachable during restore for the loading UI; anonymous callers get `active` / `phase` / `percent` / `message` only)
- Setup wizard (`/_setup`, `/_setup/api/progress`, `/_setup/api/advance`) — the page and `api/advance` exist only while setup is required (302 `/` / 404 otherwise); every request needs `setup.setup_token` or a one-time token from `nowo:site-backup:setup-token` (default-deny, 1.27+)
- CLI commands (`nowo:site-backup:*`)
- Configured `database_dump_command` and setup `console` steps (shell — operator-configured only)
- Archive extract/apply paths

## Threat model

| Threat | Risk | Mitigation |
| --- | --- | --- |
| Panel password guessing / session fixation | High | `security.login_throttle` per-IP rate limit (HTTP 429 + `Retry-After`) and session id rotation after login (v1.17+) |
| Unauthorized restore/delete | High | REQ-UI-002 `access_roles` / `SiteBackupAccessCheckerInterface` (default `ROLE_ADMIN`) unless `allow_unauthenticated`; plus password gate / custom `SiteBackupAccessGateInterface` (**fail-closed** when `password_protection` is true but `password_hash` is empty); CSRF on panel POSTs (**fail-closed** if CSRF manager missing — require `symfony/security-csrf`; `CsrfProtectionPass` also refuses to compile the container while the panel or the setup wizard is registered without `form.type_extension.csrf` or with `framework.form.csrf_protection: false`, where the token manager exists but forms would skip validation). Password gate is **additional** to role check. `allow_unauthenticated: true` is demo/dev only |
| Unauthorized setup / admin creation | High | **Default-deny (1.27+):** no static `setup_token` ⇒ only a one-time token issued on the server by `nowo:site-backup:setup-token` (SHA-256 hash, `0600`, removed on completion) opens the wizard; the dev-only `setup.allow_without_token` is a literal boolean refused unless `kernel.debug`, and never opens `api/advance`. The page and `api/advance` exist only while `SetupNeedEvaluator::isSetupRequired()` (302 `/` / 404); the profile is pinned once a run started; CSRF fail-closed (controller check + `CsrfProtectionPass` at compile time); `AdminUserProvisionerInterface` is app-owned |
| Wizard started by a page view | High | **1.27+:** a GET never starts or advances a run — the first step runs on a CSRF-protected POST (or a token-authenticated `api/advance`); a host that does not need setup answers `GET /_setup` with 302 `/` and writes nothing, so the site-wide redirect to `/_setup` only follows a real need (detectors, `setup.required` from an operator / restore, an unfinished run) |
| Wizard reopened from a stale / lost progress file | Medium | **1.27+:** the wizard is closed whenever setup is not required, and `setup.done` (or the durable store) short-circuits that check whatever the progress file says (idle after a `var/` wipe, or a stale `running`) |
| `.env.local` injection via DATABASE_URL | High | Value always written single-quoted (no Dotenv `$VAR` / `$(cmd)` expansion); single quotes, line breaks and control characters rejected |
| Option-like `DATABASE_URL` components reaching the `mysqldump` argv | Medium | **1.27+:** `MysqlDumpCommandFactory` puts `--` (end of options) before the database name and rejects a database, user, host or `unix_socket` that starts with `-` or holds control characters (decoded); the setup `database_url` step refuses the same values before writing `.env.local` (`DatabaseUrlSafety`). The password stays in `MYSQL_PWD`, never in argv. No other dump / client argv is built by the bundle (`database_dump_command` is operator shell config; SQL import goes through PDO) |
| Arbitrary file import via `sql_import_path` | High | Only existing `.sql` files under `var/site-backup/` (real path, no `../` / symlink escape); SQL errors report the SQLSTATE only, never the dump contents |
| Path traversal on apply | High | Relative paths from archive; protected paths; never overwrite `var/site-backup/` |
| Command injection via dump/setup cmd | High | Dump / console commands are **operator-configured** only (not from free-form HTTP input) |
| Progress info disclosure | Low | Restore `progress.json` gives the log / error / backup id only to authorised panel users; `/_setup/api/progress` needs the setup token and never returns wizard answers |
| Archive / dump at rest | Medium | Archives (`chmod 0600`) and `var/site-backup/last-restore-dump.sql` (`0600`, deleted after the setup `sql_file` step imports it) are owner-only |
| Backup / verify / restore staging read by another local user | Low | **1.27+:** the staging trees under the system temp dir are created `0700` with an atomic `mkdir` under an unpredictable name (`nowo-site-backup[-verify\|-restore]-<id>-<32 hex>`); the DB dump and `MANIFEST.json` are `0600`; project files keep their own modes inside the archive (restore BC) but sit under the `0700` parent. No process-wide `umask()` is changed (worker-safe) |
| DoS via huge archives / long setup / hung subprocess | Medium | `process_timeout` is the hard **and** idle timeout of every subprocess (`tar`, dump, console steps); on expiry the whole process tree is killed (no orphan `mysqldump`) and the partial archive removed. Layer PHP / proxy / FrankenPHP deadlines above it ([CONFIGURATION.md](CONFIGURATION.md#timeouts-req-runtime-001)); ops should size storage and includes carefully |
| Zip that declares a false size (decompression bomb) | Medium | `backup.extract_limits` holds on the bytes really written: zip entries go through counted streams and stop at the limit, and the output is removed on failure, for plaintext and AES-256 entries (the HMAC does not cover declared sizes). Fixed after 1.29.1, independent audit Appendix HK (2026-11-06), verified at `2e54dd1`. Affected: 1.28.0, 1.29.0, 1.29.1. See [UPGRADING.md](UPGRADING.md#to-1300) |
| Modified AES-256 zip | Medium | Before anything is written, the HMAC of every entry is checked on the raw bytes libzip itself reads, so what is authenticated is what is extracted. An entry that is not AES-256, an empty archive, a second end of central directory record and data after the end record are refused. The HMAC covers the ciphertext only: names, sizes and other metadata are not authenticated, so a `MANIFEST.json` that passes still matters. Fixed after 1.29.1, where a planted end record turned the check off (affected: 1.29.1 only), independent audit Appendix HK (2026-11-06), verified at `2e54dd1` |
| Plaintext archive swapped in for an encrypted one | Medium | With a password, every path refuses an archive that is not encrypted unless `allowPlaintext` is passed. **Without** a password a plaintext archive is accepted: the check depends on the caller giving one, and a sidecar's `encrypted` field is not trusted for it. Fixed on the extract and verify paths after 1.29.1, independent audit Appendix HK (2026-11-06), verified at `2e54dd1` |
| Archive modified in `storage_dir` | Medium | `verifyIntegrity()` stops at a SHA-256 or signature failure and unpacks nothing. A SHA-256 in the sidecar proves nothing against someone who can write both files; use signatures. Audit status **Appendix HK (2026-11-06), verified at `2e54dd1`** |
| Zip extraction over existing files | Low | A zip target that exists, or a link on the way to one, fails the extraction; a failure removes only what the call created. **Modes stored in a zip are not applied**: files come back with the umask's mode, so `0600` becomes `0644` under a 022 umask. Use `tar.gz` where modes matter |

## Setup access: default-deny (1.27+)

Since 1.27.0 the wizard is **default-deny**:

1. It exists only while setup is required (`SetupNeedEvaluator::isSetupRequired()`): otherwise `/_setup` answers 302 `/` and `api/advance` 404 (the read-only, token-gated `api/progress` keeps answering). `setup.done` closes it whatever the progress file says.
2. Every request needs a token: the static `setup.setup_token` (`?token=` or `X-Setup-Token`), or the **one-time token** printed by `php bin/console nowo:site-backup:setup-token` (add `--open` to write `setup.required` on a host whose detectors do not report a need; `--revoke` deletes it). Only the SHA-256 hash is stored (`setup.access_token_file`, default `var/site-backup/setup.token`, `0600`); the token is deleted when the wizard completes. Without a static token the 403 gate tells the operator to run that command.
3. A GET never starts or advances a run; advancing is a CSRF-protected POST. `api/advance` always needs a token (the token is its CSRF defence).
4. `setup.allow_without_token: true` (DEV/DEMO only) reopens the page without a token when no `setup_token` is set; it must be a literal boolean and the container refuses to compile it unless `kernel.debug` is true. It never opens `api/advance`.

On internet-facing hosts still prefer a long random `SITE_SETUP_TOKEN` (or the one-time token) **and** firewall `/_setup`; turn on `security_guard.enabled: true` so an empty or documented static token fails closed.

### Gate audience (`setup.gate`, 1.31+)

The site gate (`SetupRequestSubscriber`, the redirect to the wizard while setup is required) is an access decision:

- `all` (default) is unchanged: every visitor is redirected, at `subscriber_priority - 1`, before the firewall.
- `guests_and_admins` lets a signed-in user **without** `setup.gate_admin_role` (default `ROLE_ADMIN`) keep using the
  app while setup is required; guests and admins are still redirected. It needs the user, so the listener moves to
  priority 7, after the firewall (8): the firewall's own `access_control` decisions (login redirect, 403) now happen
  first on those requests. Without `security.authorization_checker` (no security-bundle), or when the check throws,
  everyone counts as a guest (fail closed). Only the redirect changes: the wizard itself stays default-deny (token,
  CSRF) whatever the audience.
- Choose it only when the pages a signed-in member reaches still work while setup is required (e.g. platform
  catalogs missing). A detector tagged `reopen: true` re-opens a done wizard when it reports a need; it runs on every
  request after setup, so keep it cheap and side-effect free.
- Reopen guards (1.31, audit IN-M1 / IN-L1): reopen detectors run only on a main `GET` / `HEAD` request that the gate
  would redirect (never on the setup, panel or excluded paths, never on `POST` / `PUT` / …); a detector that throws
  counts as "not required" and is logged. A reopen detector **must return `false` when it cannot decide**: a
  fail-true detector lets any anonymous request during a database error clear `setup.done` and the durable store.
- Global flag `setup.reopen_when_detector_requires` (1.15, default `false`; audit IN-L2): it has **none** of the
  guards above. With it on, every tagged detector runs on every caller and method (site gate on `POST` too, the wizard,
  `SetupDbDoneGuard`, commands), and the built-in `doctrine_connect` detector reports a need while the database is
  unreachable, so a database blip on a done instance re-opens the wizard; a throwing host detector answers 500. Keep it
  off and tag a never-fail-true detector `reopen: true` instead. A follow-up will route the flag through the same guards.
- `gate_admin_role` is not validated: a typo means admins are not redirected (fail open for the redirect only; no
  privilege is granted). With `guests_and_admins` a lazy firewall's own response (`LazyResponseException`, e.g. a 401
  for bad API credentials) is kept; other authorization failures count as a guest.
- Reopened wizard on a live instance (audit IN-I1, owner decision 2026-11-08, **fixed in 1.32**): when
  `SetupWizardReopener` re-opens a configured instance (`setup.done` existed, or the durable store said done) it first
  writes `setup.reopened` (`setup.reopened_marker_file`). While that marker exists the wizard shows and runs only the
  steps still missing: admin creation (`admin_user`), the `.env.local` rewrite (`database_url`) and the SQL import
  (`bootstrap_mode`, `sql_file`), plus host steps that implement `InitialInstallOnlyStepInterface`, are left out of the
  steps list and never run, on the page (GET render and POST), the JSON API and the CLI alike; a stale or crafted
  progress record that points at one of them gets no form and no run. The marker is a server-side file: no request
  parameter, header or progress answer sets or clears it. It is removed when the wizard completes (the `marker` step)
  or by an operator with `nowo:site-backup:setup-reset --initial`. The token model is unchanged: a static
  `setup_token` still opens the re-opened wizard, but it can no longer create an admin, rewrite `DATABASE_URL` or
  import SQL there. Keep the marker on the same storage as `setup.done` (shared `var/site-backup` on multi-node hosts).
  Audit status: re-verification pending.

## Backups never contain secrets (1.28+)

- **Secrets floor** (`SecretPathPolicy`, not removable by config): `.env*` (except the committed root `.env.dist` /
  `.env.example`), `config/secrets/**`, `var/secrets/**`, `config/jwt/**`, `*.key`, `*.pem`, `*.p12`, `*.pfx`,
  `*.jks`, SSH keys and credential files are skipped while files are collected (also through symlinks), the final file
  list is checked again before packing, and restore never writes them. `backup.forbidden_paths` adds patterns.
- **Staging** happens in `backup.work_dir` (`0700`): per-run directories are removed in a `finally`; archives and
  sidecars are `0600` and are moved into `storage_dir` only when complete.
- **Database credentials** travel in `MYSQL_PWD` (dump, schema inspection, import), never argv; subprocess errors are
  redacted and cut; the importer keeps only the MySQL error code and line (never row data).
- **Table rules** are validated against the live schema; names are checked against `[A-Za-z0-9_$]{1,64}` and passed as
  separate argv elements (no shell).
- The database dump itself is not scrubbed: hash and ciphertext columns, tokens and personal data stay in it. Use
  structure-only rules for volatile secret tables and protect archives at rest (archive passwords arrive in 1.29).

## Secrets & cryptography

- Panel password stored as `password_hash` (bcrypt/argon2id). **Required** whenever `security.password_protection` is true (default). Generate with `php bin/console nowo:site-backup:hash-password`.
- **Brute force (v1.17+):** `security.login_throttle` (on by default) limits panel password attempts per client IP (5 per 15 minutes, `symfony/rate-limiter` on a cache pool). Over the limit the login page answers **HTTP 429** with `Retry-After` (never a 500). The limiter is reset on success. Behind a reverse proxy configure `framework.trusted_proxies` so `Request::getClientIp()` is the real client.
- **Session fixation (v1.17+):** after a correct password the session id is rotated (`$session->migrate(true)`), for the default and for custom `access_gate` services.
- Integrity uses SHA-256 (checksums, not secrecy)
- Never commit real `.env` with production dump credentials

## Production secrets guard (opt-in, v1.16+)

`security_guard.enabled: true` registers `ProductionSecretsGuardSubscriber` (`kernel.request` and `console.command`, priority 1024). Outside `security_guard.local_environments` (default `dev`, `test` — so `staging` and misnamed environments are checked) it throws when:

- `setup.setup_token` is empty (setup enabled) or listed in `forbidden_setup_tokens`;
- `security.password_hash` is empty (panel + `password_protection`, no custom `access_gate`) or listed in `forbidden_password_hashes`;
- `APP_SECRET` (`security_guard.app_secret`, default `%env(default::APP_SECRET)%`) is empty, a known placeholder (`forbidden_app_secrets`) or shorter than `app_secret_min_length` (16).

`cache:clear`, `cache:warmup`, `assets:install` and `nowo:site-backup:hash-password` are skipped (`skip_console_commands`) so Docker image builds can warm the cache without runtime secrets. The guard is stateless (re-checks on each request; no latch kept in workers). Put the values documented in your `.env.dist` into the `forbidden_*` lists.

## CSP nonces

Bundle templates follow the nowo-tech kit convention: inline script and style tags carry `nonce="…"` from the request attribute `csp_nonce` when the host sets it (`$request->attributes->set('csp_nonce', $nonce)` in its CSP subscriber). There are no inline event handlers or `style=""` attributes: the panel confirm dialogs use `data-nowo-confirm` plus one delegated, nonce'd listener.

## Logging

**PSR-3 logs (REQ-OBS-001).** `SiteBackupManager`, `SetupOrchestrator`, `SetupTabCheckerLocator` and the panel login gate log through the
injected `Psr\Log\LoggerInterface` on the Monolog channel **`site_backup`** (tag ignored without MonologBundle; no
logger = `NullLogger`):

| Event | Level | Context |
| --- | --- | --- |
| Backup started / created | info | `action`, `backup_id`, `size_bytes` |
| Backup / restore / deletion failed | error | `action`, `backup_id`, `exception` (class), `error` (redacted message) |
| Restore started, backup deleted, setup progress reset | warning | `action`, `backup_id` |
| Restore completed | info | `action`, `backup_id` |
| Setup started / step completed / completed | info | `profile`, `step` |
| Setup step failed | error | `profile`, `step`, `error` (redacted) |
| Misconfigured tab `checker:` id | warning | `checker`, `exception` / `type` |
| Panel login succeeded / failed / throttled (HTTP 429) | info / notice / warning | `action: panel_login` (no client IP, no password) |

**Never logged:** passwords, the setup token, the panel password hash, `DATABASE_URL`, the configured dump command,
actors (usernames), client IPs and backup labels. Error messages pass through `SecretRedactor` (URL user info, `password=` /
`MYSQL_PWD=` pairs, `-p…` / `--password=…` options are masked as `***`) and the exception is logged by class name,
not as an object (no unredacted message or trace). The dump timeout error never repeats the command line. There is
no `dump()` / `error_log()` in `src/`.

**History JSONL** (`storage.history_file`) stores action, actor, backup id and messages for the panel *History*
tab — avoid putting secrets in labels.

## Dependency and updates

Run `composer audit` before releases. See checklist 12.4.1 below.

## Firewall / `access_control` (host app)

The bundle's own guards are **fail-closed** (roles, password hash, CSRF). Still configure Symfony Security so panel and setup URLs are not anonymously reachable:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/_site_backup, roles: ROLE_ADMIN }
        # Setup wizard — only while setup is required; optional setup_token is additional
        - { path: ^/_setup, roles: ROLE_ADMIN }
```

- `/_site_backup` — admin panel (create/delete/restore). Match `ROLE_ADMIN` (or your `security.access_roles`).
- `/_setup` — setup wizard routes. Restrict similarly; prefer disabling or blocking after setup completes. See [INSTALLATION.md](INSTALLATION.md) and [SETUP-WIZARD.md](SETUP-WIZARD.md).

Progress JSON used by the restore loading UI may need to stay reachable during an active restore — do not open the full panel anonymously for that reason.

## Release security checklist (12.4.1)

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` present | ✅ |
| `.env` in `.gitignore` | ✅ |
| No secrets in repo | ✅ |
| Safe recipe config | ✅ |
| Input/output validation + escaping | ✅ |
| `composer audit` | Before each release |
| No-secret logs | ✅ |
| Safe cryptography | ✅ |
| Permissions/exposure documented | ✅ |
| Limits/DoS (`process_timeout`) | ✅ |
| Passes AI security audit (REQ-SEC-004; grade in monorepo `BUNDLES_SECURITY_ANALYSIS.md`) | ⏳ Re-audit pending for the 1.27.0 setup / dump hardening. Residuals: host must set `password_hash` + install `symfony/security-csrf`; firewall `/_site_backup` and `/_setup` |

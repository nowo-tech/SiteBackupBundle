# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Secrets & cryptography](#secrets-cryptography)
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
- Progress JSON endpoint (intentionally reachable during restore for the loading UI)
- Setup wizard (`/_setup`) — only while setup is required; optional `setup_token`
- CLI commands (`nowo:site-backup:*`)
- Configured `database_dump_command` and setup `console` steps (shell — operator-configured only)
- Archive extract/apply paths

## Threat model

| Threat | Risk | Mitigation |
| --- | --- | --- |
| Unauthorized restore/delete | High | REQ-UI-002 `access_roles` / `SiteBackupAccessCheckerInterface` (default `ROLE_ADMIN`) unless `allow_unauthenticated`; plus password gate / custom `SiteBackupAccessGateInterface` (**fail-closed** when `password_protection` is true but `password_hash` is empty); CSRF on panel POSTs (**fail-closed** if CSRF manager missing — require `symfony/security-csrf`). Password gate is **additional** to role check. `allow_unauthenticated: true` is demo/dev only |
| Unauthorized setup / admin creation | High | Wizard only while detectors say required; optional `setup_token`; CSRF fail-closed; `AdminUserProvisionerInterface` is app-owned |
| Path traversal on apply | High | Relative paths from archive; protected paths; never overwrite `var/site-backup/` |
| Command injection via dump/setup cmd | High | Dump / console commands are **operator-configured** only (not from free-form HTTP input) |
| Progress info disclosure | Low | Endpoints expose phase/percent/message only |
| DoS via huge archives / long setup | Medium | `process_timeout`; ops should size storage and includes carefully |

## Secrets & cryptography

- Panel password stored as `password_hash` (bcrypt/argon2id). **Required** whenever `security.password_protection` is true (default). Generate with `php bin/console nowo:site-backup:hash-password`.
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

History JSONL stores action, actor, backup id, messages — avoid putting secrets in labels.

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
| Passes AI security audit (REQ-SEC-004; grade in monorepo `BUNDLES_SECURITY_ANALYSIS.md`) | ✅ Pass (conditional) after fail-closed remedia 2026-07-29 — residuals: host must set `password_hash` + install `symfony/security-csrf`; firewall `/_site_backup` and `/_setup` |

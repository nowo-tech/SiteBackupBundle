# Security

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
| Unauthorized restore/delete | High | Password gate / custom `SiteBackupAccessGateInterface`, CSRF on panel POSTs |
| Unauthorized setup / admin creation | High | Wizard only while detectors say required; optional `setup_token`; CSRF; `AdminUserProvisionerInterface` is app-owned |
| Path traversal on apply | High | Relative paths from archive; protected paths; never overwrite `var/site-backup/` |
| Command injection via dump/setup cmd | High | Dump / console commands are **operator-configured** only (not from free-form HTTP input) |
| Progress info disclosure | Low | Endpoints expose phase/percent/message only |
| DoS via huge archives / long setup | Medium | `process_timeout`; ops should size storage and includes carefully |

## Secrets & cryptography

- Panel password stored as `password_hash` (bcrypt/argon2id)
- Integrity uses SHA-256 (checksums, not secrecy)
- Never commit real `.env` with production dump credentials

## Logging

History JSONL stores action, actor, backup id, messages — avoid putting secrets in labels.

## Dependency and updates

Run `composer audit` before releases. See checklist 12.4.1 below.

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

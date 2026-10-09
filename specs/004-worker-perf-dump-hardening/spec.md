# Feature Specification: Worker performance, long requests, built-in DB dump, production hardening

**Feature Branch**: `feat/perf-dump-2026-10`  
**Created**: 2026-10-09  
**Status**: Implemented (target **v1.16.0**)  
**Origin**: ported from a production host app (FrankenPHP worker mode, shared MySQL, nonce-based CSP).

## Problem

1. **Per-request probes in worker mode** — the cold-start gate and the Doctrine detectors query `information_schema` on every main request; the Doctrine progress storage runs `CREATE TABLE IF NOT EXISTS` on every request because `kernel.reset` clears its memo between FrankenPHP worker requests.
2. **Long requests killed** — setup `advance` (migrate / seed) and panel dump / restore run subprocesses up to `process_timeout`, but a short prod `max_execution_time` kills the parent request mid-pipe.
3. **Every host writes its own dump command** — usually leaking the password in argv and adding `--routines` that the PDO-based restore cannot replay.
4. **Placeholder secrets reach production** — documented `.env.dist` setup tokens / panel hashes / `APP_SECRET` leave `/_setup` and the panel open.
5. **Strict CSP** — inline scripts / styles without nonce and `onsubmit` handlers are blocked.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-PERF-001 | `WorkerTtlMemo` (TTL, injectable clock, **not** `ResetInterface`). TTL `0` disables. |
| FR-PERF-002 | `MemoizedSchemaExistenceChecker` wraps `MysqlSchemaExistenceChecker` when `setup.worker_memo.schema_probe_ttl > 0`; caches positive answers only. |
| FR-PERF-003 | `DoctrineConnectDetector` / `DoctrineSchemaEmptyDetector` reuse a healthy answer for `schema_probe_ttl`; required / unknown answers are never cached. |
| FR-PERF-004 | `DoctrineDbalSetupProgressStorage` / `DoctrineDbalSetupStepJournal` skip the DDL for `progress_ddl_ttl` across `kernel.reset`; stale-table retry still re-creates dropped tables. |
| FR-PERF-005 | Docs state that `reopen_when_detector_requires: true` costs one detector pass per request. |
| FR-TIME-001 | `bump_time_limit` (default `false`) registers `LongRequestTimeLimitSubscriber` (priority 512, main requests only) for the panel prefix and setup prefix (+ `/{locale}` variants when `locale.in_path != never`). |
| FR-TIME-002 | Seconds = `bump_time_limit_seconds` ?? `process_timeout`; `0` = unlimited. |
| FR-DUMP-001 | `nowo:site-backup:db-dump` streams `mysqldump` output of `DATABASE_URL` (or `--url`) to stdout; diagnostics to stderr; exit code propagated; timeout `process_timeout`. |
| FR-DUMP-002 | Password via `MYSQL_PWD` env only; `--single-transaction --no-tablespaces`; no `--routines`; optional `--skip-ssl-verify-server-cert`; `--binary`; repeatable `-o`; `?unix_socket=` → `--socket=`. |
| FR-SEC-001 | `security_guard.enabled` (default `false`) registers `ProductionSecretsGuardSubscriber` on `kernel.request` + `console.command` (priority 1024). |
| FR-SEC-002 | Outside `local_environments`: refuse empty / forbidden setup token (setup enabled), panel hash (panel + password_protection, no custom gate), APP_SECRET (+ min length). Skip configured console commands. Stateless. |
| FR-CSP-001 | Inline script/style tags in bundle templates carry `nonce` from request attribute `csp_nonce` when present. |
| FR-CSP-002 | No inline `on*` handlers or `style=""` attributes; panel confirms use `data-nowo-confirm` + delegated listener. |

## Configuration (defaults)

```yaml
nowo_site_backup:
    bump_time_limit: false
    bump_time_limit_seconds: null
    security_guard:
        enabled: false
        local_environments: [dev, test]
        require_setup_token: true
        forbidden_setup_tokens: []
        require_panel_password: true
        forbidden_password_hashes: []
        check_app_secret: true
        app_secret: null            # %env(default::APP_SECRET)%
        forbidden_app_secrets: [ThisTokenIsNotSoSecretChangeIt, ChangeMe, '!ChangeMe!', ChangeMePleaseUseARealSecret]
        app_secret_min_length: 16
        skip_console_commands: [cache:clear, cache:warmup, assets:install, nowo:site-backup:hash-password]
    setup:
        worker_memo:
            schema_probe_ttl: 60
            progress_ddl_ttl: 3600
```

## Tests

- `tests/Unit/Setup/Memo/WorkerMemoTest.php` — memo TTL, schema checker, detectors.
- `tests/Unit/Setup/Storage/DoctrineStorageWorkerLifecycleTest.php` — DDL once per worker across reset; TTL expiry; dropped table recovery.
- `tests/Unit/EventSubscriber/LongRequestTimeLimitSubscriberTest.php`, `ProductionSecretsGuardSubscriberTest.php`.
- `tests/Unit/Database/MysqlDumpCommandFactoryTest.php`, `tests/Unit/Command/DatabaseDumpCommandTest.php` (fake binary).
- `tests/Unit/DependencyInjection/SiteBackupExtensionTest.php` — wiring / opt-in.
- `tests/Unit/Twig/CspNonceTemplateTest.php` — nonce on inline tags, no inline handlers, templates parse, restore page renders nonce.

## Out of scope

- App-specific guard checks (e.g. Redis password) stay in the host.
- PostgreSQL / SQLite dumpers.

# Feature Specification: Durable setup done + cold-start schema gate

**Feature Branch**: `feat/003-durable-done-cold-start`  
**Created**: 2026-08-15  
**Status**: In progress (target **v1.12.0**)  
**Issue**: [SiteBackupBundle#9](https://github.com/nowo-tech/SiteBackupBundle/issues/9)

## Problem

1. **Ephemeral `setup.done` marker** — In containerized prod images, `var/site-backup/setup.done` is wiped on redeploy. Host apps that persist “setup completed” in the database (e.g. Beacon `instance_settings.setup_completed_at`) need the wizard to stay closed and SiteBackup markers/progress healed from that durable signal.

2. **Cold-start before schema exists** — When `DATABASE_URL` points at a MySQL server but the database/schema is not created yet, Symfony boot or early requests can fail before the setup wizard runs. A lightweight schema probe must gate traffic to the wizard (with safe-path exceptions) without requiring Doctrine migrations.

## User scenarios

### US-1 — Durable done closes wizard after container recreate (P1)

Given the host registers a `DurableSetupDoneStoreInterface` that returns `isDone(): true` and SiteBackup detectors no longer require setup, when a visitor opens `/_setup`, then they are redirected to the configured target (default `/`) and ephemeral markers/progress are healed to `completed`.

### US-2 — BC default (P1)

Given `setup.durable_done.enabled: false` (default), when the bundle boots, then `DurableSetupDoneStoreInterface` aliases to `NullDurableSetupDoneStore` (`isDone()` always false) and no redirect subscriber is registered.

### US-3 — Cold-start schema missing (P1)

Given `setup.cold_start.enabled: true` and MySQL has no application database, when a visitor requests `/`, then they are redirected to the setup path and propagation stops so later listeners do not assume a working schema.

### US-4 — Safe paths during cold start (P1)

Given cold start is active and schema is missing, when a request matches `setup.cold_start.safe_path_prefixes` (default includes `/health/`, `/_wdt`, …), then no redirect is issued and optional `stop_propagation` prevents lower-priority listeners from running.

### US-5 — Setup gate stopPropagation (P2)

Given setup is required, when `SetupRequestSubscriber` sets a redirect to the wizard, then it also calls `stopPropagation()` so no further `kernel.request` listeners run for that request.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-DONE-001 | `DurableSetupDoneStoreInterface` with `isDone()` / `markDone()`. |
| FR-DONE-002 | `NullDurableSetupDoneStore` default alias (BC). |
| FR-DONE-003 | `SetupDbDoneGuard`: when store says done and `SetupNeedEvaluator` does not require setup, heal markers + progress phase `completed`. |
| FR-DONE-004 | `SetupDbDoneRedirectSubscriber` at priority **3**; closes setup index + `/api` under prefix (locales included); configurable `redirect_target`. |
| FR-DONE-005 | Register redirect subscriber only when `setup.durable_done.enabled: true`. |
| FR-COLD-001 | `SchemaExistenceCheckerInterface::schemaExists()`. |
| FR-COLD-002 | `MysqlSchemaExistenceChecker`: optional DBAL `Connection` probe (`SELECT 1`); treat “Unknown database” as false; PDO MySQL fallback from `setup.cold_start.mysql_*` config. |
| FR-COLD-003 | `ColdStartRequestAttributes::SCHEMA_EXISTS` request attribute (`_nowo_site_backup_schema_exists`). |
| FR-COLD-004 | `ColdStartSchemaGateSubscriber` priorities **35 / 34 / 20**; redirect when schema missing; `stop_propagation` configurable. |
| FR-COLD-005 | Register cold-start services only when `setup.cold_start.enabled: true`. |
| FR-GATE-001 | `SetupRequestSubscriber` calls `stopPropagation()` after setting redirect when setup is required. |
| FR-DOC-001 | Document config, host alias override, and upgrade notes in `docs/*`. |

## Configuration (defaults)

```yaml
nowo_site_backup:
    setup:
        durable_done:
            enabled: false
            redirect_target: '/'
        cold_start:
            enabled: false
            stop_propagation: true
            safe_path_prefixes:
                - '/health/'
                - '/api/'
                - '/_wdt'
                - '/_profiler'
                - '/build/'
                - '/assets/'
                - '/_error'
                - '/favicon.ico'
            mysql_host: null      # optional; env MYSQL_HOST when wired by host
            mysql_port: 3306
            mysql_user: null
            mysql_password: null
            mysql_database: null
```

Hosts replace the `DurableSetupDoneStoreInterface` service alias with an implementation backed by their durable settings row.

## Out of scope

- Beacon-specific `InstanceSettingsRepository` (host wires the store).
- Symfony Migrations for setup tables (unchanged from 002).

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-003-001 | Unit tests: Guard heal + close; Null store; SetupRequestSubscriber stopPropagation; cold-start redirect when schema missing. |
| SC-003-002 | Extension tests: services registered only when flags enabled; default alias is Null store. |
| SC-003-003 | Docs updated for v1.12.0. |

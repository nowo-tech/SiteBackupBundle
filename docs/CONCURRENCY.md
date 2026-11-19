# Concurrency

How SiteBackupBundle stays correct when two requests, workers, cron jobs or console runs act at the same time
(REQ-RUNTIME-003). It applies to standard Symfony hosts and DDD hosts alike, and to FrankenPHP worker mode with the
kernel kept alive (no state is kept on a service between operations).

## Table of contents

- [Model](#model)
- [Write paths](#write-paths)
- [Conflict answer and codes](#conflict-answer-and-codes)
- [Host options](#host-options)
- [Several servers](#several-servers)
- [Lock lifetime, store failures and crashes](#lock-lifetime-store-failures-and-crashes)
- [Scheduled and manual runs](#scheduled-and-manual-runs)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Model

The bundle has no Doctrine entities and never touches the host's EntityManager. Its writes are files (archives,
history, restore progress, markers), the database through `mysqldump` / the `mysql` client / the setup SQL import, and
the optional DBAL setup progress table. None of them can run in one database transaction, so concurrency is
controlled by **locks taken before anything changes**:

| Lock | Key / file | Serialises |
|------|------------|------------|
| Operation lock (`BackupOperationLock`) | `backup.lock.key` (default `nowo_site_backup.operation`), else `<storage_dir>/.operation.lock` | backups, incrementals, archive imports, deletes, restores, chain restores, restore drills, `db-import`, the setup SQL import, clearing the restore status |
| Setup lock (1.37+, service `nowo_site_backup.setup_lock`) | `<backup.lock.key>.setup`, else `<storage_dir>/.setup.lock` | `SetupOrchestrator::advance()` and `resetProgress()`: the wizard page, its JSON API and `nowo:site-backup:setup*` |

Both locks are **non-blocking**: a second caller fails at once and does nothing. They use the host's `lock.factory`
(symfony/lock) when it exists, else `flock()` with `LOCK_EX | LOCK_NB` on the lock file.

## Write paths

| Path | Transaction | Control | Conflict answer |
|------|-------------|---------|-----------------|
| Panel `create` (POST `/_site_backup`), `nowo:site-backup:create`, `SiteBackupManager::createBackup()` | none (files + `mysqldump`) | operation lock; the "new full after a restore" check re-runs under the lock | panel 409 + `CONFLICT_001`; CLI exit 75 (`EXIT_LOCKED`); service `BackupAlreadyRunningException` |
| Panel `restore`, `nowo:site-backup:restore`, `restore()` | none (staging dir, then files; the dump is handed to the setup SQL step) | operation lock, refreshed on every heartbeat; the loading page covers the site while progress is fresh | panel 409 + `CONFLICT_001`; CLI error; service exception |
| `restore-chain`, `restoreChain()` | none (`mysql` client per link) | operation lock held for the whole chain; its importer does not take the lock again | CLI error; service exception |
| Panel `delete`, `deleteBackup()` | none | operation lock | panel 409 + `CONFLICT_001` |
| Panel `clear_restore`, `clearRestoreStatus()` (1.37+) | none | operation lock, and a still-running restore (fresh progress) is never cleared (`RestoreStillRunningException`, the panel says "A restore is still running") | panel 409 + `CONFLICT_001` |
| `import-archive` (one archive or a chain), `importArchive()` / `importChain()` | none | operation lock; a failed chain import removes what it imported | CLI error; service exception |
| `db-import`, `restore-drill`, `DumpImporter` | none (the `mysql` client) | operation lock, refreshed every 5 s while the client runs | CLI error; service exception |
| Setup wizard POST `/_setup`, JSON `POST …/api/advance`, `nowo:site-backup:setup` (1.37+) | none | setup lock for the whole run (progress is read after the lock is taken), refreshed before each step; "reset and run" share one lock | page 409 + `CONFLICT_001`; JSON 409 `{"error":"setup_running","code":"CONFLICT_001"}`; CLI error |
| Setup step `sql_file` (1.37+) | none (each file is one `executeStatement()` or one client run) | operation lock held for every file of the step, taken before the restore marker is written | the step fails with "SQL import not started" (nothing imported); the wizard shows it and Continue retries the step |
| DBAL setup progress row (`id = 1`) and step rows | autocommit, one statement | written only under the setup lock; 1.37+: a lost insert race (the row appeared meanwhile) becomes an update | — |
| Read-only: panel list, `progress.json`, verify, wizard progress | — | — | — |

The restore **loading page** (`RestoreRequestSubscriber`, `restore.status_code`, default 503 + `Retry-After: 30`) is
maintenance mode for the host's pages while a restore runs. It is not a lock-conflict answer and carries no
`X-Conflict-Code`.

## Conflict answer and codes

Only the bundle's own routes answer conflicts (the panel and the setup wizard). Host routes are never answered.

| Situation | Status | Headers | Body |
|-----------|--------|---------|------|
| Another backup / restore / delete / import holds the operation lock | `conflict_response.status` (409, or 503) | `Retry-After: 300`, `X-Conflict-Code: CONFLICT_001`, `Cache-Control: no-store, private` | the panel page with "Another backup or restore is running. Nothing was changed…" (`panel.error.operation_running`) |
| Another setup run holds the setup lock | same | `Retry-After: 30`, `X-Conflict-Code: CONFLICT_001` | the wizard page (`setup.error.setup_running`) or JSON `{"error":"setup_running","code":"CONFLICT_001"}` |

- `CONFLICT_001`: nothing was done, retrying is safe. Every lock is taken before the first change, so this is the
  only code the bundle sends.
- `CONFLICT_002` (part committed, read again, no `Retry-After`) is never sent: no bundle route commits part of its
  work and then meets a lock. A failure after the lock was taken (a dump error, a lost lock) is an ordinary error,
  reported as such and logged; it is never answered as a conflict.
- The header carries only `CONFLICT_001` / `CONFLICT_002`. Domain errors keep their own messages and codes.
- A failing lock store (Redis down, unwritable lock file) is **not** a conflict: `OperationLockUnavailableException`,
  nothing is started. The panel and the wizard answer **503** + `Retry-After` (no `X-Conflict-Code`); the setup JSON
  API answers `503 {"error":"lock_unavailable"}`; console commands exit 1.

## Host options

```yaml
nowo_site_backup:
    setup:
        lock_fallback_local: cold_only   # always | never: may the setup lock use its local file when the store fails?
    conflict_response:
        status: 409        # or 503 for clients that only retry on 503
    process_timeout: 600   # also sizes the lock TTL: 4 x process_timeout + 300 s
    backup:
        lock:
            key: nowo_site_backup.operation   # the setup lock uses "<key>.setup"
```

The lock store is the host's `framework.lock` (service `lock.factory`). Without symfony/lock the bundle uses `flock()`
in `backup.storage_dir`.

## Several servers

`flock()` and a `flock`/`semaphore` store only serialise **one host**. When several app containers or servers share
the database or the storage, configure a shared store, for example:

```yaml
framework:
    lock: '%env(LOCK_DSN)%'   # redis://…, or a PDO / DBAL DSN on a database every server reaches
```

Without it, two servers can run a backup and a restore at the same time. The operation lock never falls back to a
local file. The setup lock falls back to its local lock file only as `setup.lock_fallback_local` allows (1.37+):
`cold_only` (default) while the instance is genuinely cold (no `setup.done`, not re-opened, the durable done store not
done), because the store is then often the database being set up; `always`; or `never`. On a configured or re-opened
instance a store outage answers 503, so servers never stop excluding each other.

## Lock lifetime, store failures and crashes

- **TTL**: `4 × process_timeout + 300` s. Every subprocess is bounded by `process_timeout`, and the lock is refreshed on
  every heartbeat (at least every 2 s during a backup, every progress write during a restore, every 5 s while the
  `mysql` client imports, before each setup step), so a long operation never outlives its lock.
- **Release**: always in `finally`. A store failure while releasing is logged (`site_backup` channel) and the key
  expires at its TTL; the operation's own result or exception is kept.
- **Refresh**: a store failure is logged and retried on the next heartbeat (the TTL leaves the margin). A lock lost to
  another holder or expired stops the operation with Symfony Lock's exception; 1.37+: the running subprocess tree is
  killed before the exception propagates.
- **Crash**: `flock()` and a `FlockStore` are freed by the kernel when the process dies. A Redis / database key
  expires at its TTL; until then new operations get 409. A restore whose process died leaves "running" progress that
  stops counting after `restore.stale_after_seconds`.

## Scheduled and manual runs

Cron jobs run the console commands; they share the operation lock with the panel. `nowo:site-backup:create` exits
`75` (`EXIT_LOCKED`) when the lock is held, so a scheduler can retry later. There is no Messenger handler in the
bundle; a host handler that calls `SiteBackupManager::createBackup()` gets `BackupAlreadyRunningException` and should
let Messenger retry it (nothing was done). The re-opened wizard (1.32+) leaves out admin creation, `database_url` and
the SQL import, and runs the rest under the setup lock like a first install.

## Tests

- `tests/Integration/Concurrency/OperationLockRaceTest.php`: separate PHP processes (hard timeouts) race on the same
  store: two backups, a restore during a backup, a backup during a restore, two restores, clearing the status during a
  restore, two setup runs, a killed holder, and a short TTL kept alive by the heartbeat. Stores: the `flock()`
  fallback, `FlockStore`, and `PdoStore` on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (CI job
  "Databases (lock-store races)", required on `main`; `lock_timeout` / `innodb_lock_wait_timeout` are 5 s).
- Unit tests cover the 409 / 503 answers, the headers and the store-failure branches.

## Residual risks

- Several servers without a shared lock store are not serialised (see [Several servers](#several-servers)).
- A backup or restore of the same database started **outside** the bundle (a DBA, another instance with another
  `storage_dir` or lock key) is not seen.
- A killed holder on an expiring store blocks new operations until the TTL passes (up to `4 × process_timeout + 300` s).
- The setup SQL import runs each file as it is; a file that fails half-way is not rolled back (DDL cannot be). Prefer
  idempotent SQL; the step can be retried.
- During the first install (cold instance, `cold_only`), a failing shared store lets the setup lock fall back to the
  local lock file, so two servers running that first install at the same moment are not serialised. Run the first
  install on one server, or set `setup.lock_fallback_local: never`.
- `SetupWizardReopener` (re-open on a done instance) resets the stored progress without the setup lock. It only runs
  while `setup.done` or the durable store says done, when the wizard is closed and no setup run can hold the progress;
  a run started in the same instant as a re-open is the remaining window.
- A lost lock stops the operation: the running subprocess (dump, client import) is killed first (1.37+), so it never
  keeps writing without the lock; what it already wrote stays (a partial dump file is discarded with the failed backup;
  a partial import is not rolled back).

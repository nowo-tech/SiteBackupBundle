# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/site-backup-bundle` (`symfony-bundle`) |
| Audited revision | `v1.35.1` (refreshed from `v1.14.0`: framework-free panel ports, typed admin provisioner, host setup step types, `setup.dbal_connection`) |
| Audit date | 2026-09-25, refreshed 2026-11-12 (REQ-RUNTIME-002) |
| Method | Manual review of every file under `src/` (services, subscribers, controllers, setup steps, storages, DI extension, compiler pass, `Resources/config/services.yaml`) |
| Remediation (2026-09-23 / 2026-09-25) | W-02 fixed (self-healing schema memo + `ResetInterface`); W-01 mitigated with a shared "restart workers" signal (`WorkerRestartSignal`, `WorkerRestartRequiredEvent`, `nowo:site-backup:worker-restart`); W-04 PDO connect timeout added; regression tests simulate two requests on the same instances without `reset()` |
| Igor re-audit (2026-11-11) | `composer igor` (igor-php 0.10.1) on `main`: 3 KO / 2 WARN, all triaged in [W-07](#w-07--igor-findings-2026-11-11-info); no cross-request leak. Since then igor reports 0 KO / 0 WARN and runs in CI (`igor` job) |
| Worker test | [`WorkerModeKernelTest`](#worker-test-req-runtime-002): one kernel, 1,000 requests through `http_kernel` (no services resetter), operator and guest in alternation, memory margin 2 MiB |
| **Verdict** | ✅ **Viable under scenario B** — no cross-request state leak; the only remaining condition is operational: restart workers when the bundle raises the restart signal after a restore / `cache_clear` / `database_url` (W-01, cannot be fixed in-process) |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
  - [W-01 — Restore and setup change code, env and cache under a running worker (Medium)](#w-01--restore-and-setup-change-code-env-and-cache-under-a-running-worker-medium)
  - [W-02 — `$schemaEnsured` flag goes stale when the setup tables are dropped (Medium)](#w-02--schemaensured-flag-goes-stale-when-the-setup-tables-are-dropped-medium)
  - [W-03 — Long synchronous subprocesses inside HTTP requests (Low)](#w-03--long-synchronous-subprocesses-inside-http-requests-low)
  - [W-04 — Cold-start schema probe runs on every main request (Low)](#w-04--cold-start-schema-probe-runs-on-every-main-request-low)
  - [W-05 — File markers are read with `is_file()` (Info)](#w-05--file-markers-are-read-with-is_file-info)
  - [W-06 — `SetupRouteLoader::$loaded` guard (Info)](#w-06--setuprouteloaderloaded-guard-info)
  - [W-07 — Igor findings 2026-11-11 (Info)](#w-07--igor-findings-2026-11-11-info)
  - [W-08 — Object-keyed static weak maps for secrets (Info)](#w-08--object-keyed-static-weak-maps-for-secrets-info)
- [Worker test (REQ-RUNTIME-002)](#worker-test-req-runtime-002)
- [Host Doctrine entity managers](#host-doctrine-entity-managers)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

Precondition, precisely: with the stock Symfony kernel, `FRANKENPHP_RESET_KERNEL=false` only stops the kernel from being
cloned; `Kernel::handle()` still calls `services_resetter->reset()` before every request after the first (scenario A).
Scenario B happens when a host removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly),
in Messenger consumers started with `--no-reset`, and in long-running console commands. The bundle is correct in both.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `DoctrineDbalSetupProgressStorage::$schemaEnsured` and `DoctrineDbalSetupStepJournal::$schemaEnsured`, now re-validated on query failure and reset by `kernel.reset` (W-02, resolved); every other service holds `readonly` config only |
| Static properties / `static` locals | ✅ | Two object-keyed `WeakMap`s for secrets (`ArchivePassword::$values`, `AdminUserData::$passwords`, W-08); otherwise only static factory methods on models (`fromArray()`, `ok()`, …) and static closures in DI / form options |
| `ResetInterface` / `kernel.reset` coverage | ✅ | The two DBAL setup storages implement `ResetInterface` (autoconfigured `kernel.reset`); correctness no longer depends on it (W-02) |
| Request / user / locale captured in services | ✅ | `SetupPathPrefixResolver` reads `RequestStack` at call time; session and `isGranted()` are read per call |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used at runtime. `DatabaseUrlStep` writes `.env.local` on disk, which a running worker does not re-read (W-01) |
| Doctrine / EntityManager | ✅ | No ORM / EntityManager. DBAL is used through the shared connection, queries only; no entities kept in properties |
| Output, headers, `exit`, shutdown functions | ✅ | None; all responses are `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | Files are opened and closed per call; temp/staging dirs removed in `finally` blocks. PDO fallback connection is a local variable (W-04) |
| Memory growth across requests | ✅ | No caches or accumulating arrays in services; progress logs are capped at 200 lines and stored outside the process |
| Blocking I/O and timeouts | ⚠️ Low | `tar`, DB dump and `bin/console` subprocesses run synchronously inside HTTP requests with the configurable `process_timeout` (default 600 s) (W-03, accepted); per-request schema probe, PDO fallback now has a 5 s connect timeout (W-04) |
| Third-party static state | ✅ | Symfony Process / Filesystem / Finder / Form / Twig only; no static state used |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

Worker demo: `demo/symfony8/docker/frankenphp/Caddyfile` runs `php_server` with a `worker { file /app/public/index.php; watch }` block (`FRANKENPHP_MODE=worker` by default, see `docs/DEMO-FRANKENPHP.md`).

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Service\SiteBackupManager` | yes | none (`readonly` collaborators) | ✅ | ✅ |
| `Security\StaticActorResolver` / `Security\SecurityTokenActorResolver` | yes | none (`final readonly`; the token is read per call) | ✅ | ✅ |
| `Security\ThrottledSiteBackupAccessGate` | yes | none in memory; failed attempts per client IP live in the rate limiter storage (`security.login_throttle`) | ✅ | ✅ |
| `Security\PanelRequestContextFactory` (static factory, no state), `Security\HttpFoundationPanelSession` (1.35) | no — a context and a session adapter are built per call from the `Request` | per-call only | ✅ | ✅ |
| `DependencyInjection\Compiler\SetupStepsPass` (1.35) | build time only | n/a | ✅ | ✅ |
| `Backup\BackupArchiver` | yes | none (`readonly` config + `Filesystem`) | ✅ | ✅ |
| `Incremental\BinlogCapture`, `Incremental\BinlogPrerequisites`, `Incremental\BinlogReplayer`, `Incremental\MysqlClientBinlogServer`, `Incremental\MysqlBinlogTool`, `Restore\ChainRestorer` (1.36) | yes | none (`readonly` config); option files, captures, staging and decoded logs live in per-call private directories removed in a `finally` | ✅ | ✅ |
| `Restore\RestoreOrchestrator` | yes | none; progress lives in storage; raises the worker restart signal after applying files | ✅ (W-01 signal) | ✅ (W-01 signal) |
| `Storage\FilesystemRestoreProgressStorage`, `Storage\FilesystemBackupHistoryStorage` | yes | none; read/write files per call | ✅ | ✅ |
| `Setup\Storage\FilesystemSetupProgressStorage`, `CacheSetupProgressStorage`, `ChainSetupProgressStorage`, `CacheDoctrineSetupProgressStorage` | yes | none | ✅ | ✅ |
| `Setup\Storage\DoctrineDbalSetupProgressStorage` | yes | `private bool $schemaEnsured` (re-validated on failure, `ResetInterface`) | ✅ | ✅ |
| `Setup\Storage\DoctrineDbalSetupStepJournal` | yes | `private bool $schemaEnsured` (re-validated on failure, `ResetInterface`) | ✅ | ✅ |
| `Worker\WorkerRestartSignal` | yes | none; marker file read per call (shared by all workers and the CLI) | ✅ | ✅ |
| `Setup\Storage\SetupMarkerManager` | yes | none; `is_file()` per call | ✅ | ✅ |
| `Setup\SetupOrchestrator`, `SetupStepFactory`, `SetupTabCheckerLocator`, `ConsoleProcessRunner`, `SetupDbDoneGuard` | yes | none; the orchestrator raises the worker restart signal after `cache_clear` / `database_url` | ✅ (W-01 signal, W-03) | ✅ (W-01 signal, W-03) |
| Setup steps (`Setup/Step/*`, 16 step classes + `AbstractSetupStep`) | no — built per call by `SetupStepFactory::createAll()` | per-call only | ✅ | ✅ |
| `SetupContext` | no — created per `advance()` call | per-call only | ✅ | ✅ |
| Detectors (`MarkerFileDetector`, `DoctrineConnectDetector`, `DoctrineSchemaEmptyDetector`, `IncompleteSetupProgressDetector`) + `SetupNeedEvaluator` | yes | none | ✅ | ✅ |
| `Setup\ColdStart\MysqlSchemaExistenceChecker` (only when `cold_start.enabled`) | yes | none (`readonly`) | ✅ (W-04) | ✅ (W-04) |
| `EventSubscriber\RestoreRequestSubscriber`, `SetupRequestSubscriber`, `SetupDbDoneRedirectSubscriber`, `ColdStartSchemaGateSubscriber` | yes | none; per-request data goes into `Request` attributes | ✅ | ✅ |
| `Security\PasswordSiteBackupAccessGate` | yes | none; auth flag stored in the request session | ✅ | ✅ |
| `Security\ConfigurableSiteBackupAccessChecker` / `AllowAllSiteBackupAccessChecker` | yes | none; `isGranted()` evaluated per call | ✅ | ✅ |
| `Exclusion\SiteBackupExclusionMatcher` | yes | none | ✅ | ✅ |
| `Routing\SetupPathPrefixResolver` | yes | none; `RequestStack::getCurrentRequest()` at call time | ✅ | ✅ |
| `Routing\SetupRouteLoader` (`routing.loader`) | yes | `private bool $loaded` (W-06) | ✅ | ✅ |
| `Twig\SiteBackupExtension` | yes | none; globals are constant config strings | ✅ | ✅ |
| `Controller\SiteBackupPanelController`, `SetupWizardController`, `SetupUnlocalizedLocaleRedirectController` | yes | none | ✅ | ✅ |
| 9 form types + `AbstractSiteBackupFormType` (`Form/**`) | yes | stateless | ✅ | ✅ |
| 9 console commands (`Command/*`, incl. `WorkerRestartCommand`) | CLI only | n/a in HTTP worker | ✅ | ✅ |

Models (`BackupArtifact`, `BackupHistoryEntry`, `RestoreProgress`, `SetupProgress`, `SetupStepResult`, `SetupTabCheckResult`, `SetupStepInput`) and events are created per call and never stored in a service.

## Findings

### W-01 — Restore and setup change code, env and cache under a running worker (Medium)

- **Where:**
  - `src/Restore/RestoreOrchestrator.php:175-211` (`applyFromStaging()`) overwrites project files. Default `include_paths` are `config`, `public`, `templates`, `translations`, `src`, `migrations`, `composer.json`, `composer.lock`, `.env` (`src/DependencyInjection/Configuration.php:65-69`).
  - `src/Setup/Step/DatabaseUrlStep.php:57-70` writes `DATABASE_URL` to `.env.local`.
  - `src/Setup/Step/CacheClearStep.php:26` runs `bin/console cache:clear` in a subprocess.
  - All of them can run inside an HTTP request: `SiteBackupPanelController::handleRestore()` (`src/Controller/SiteBackupPanelController.php:194-200`) and `SetupWizardController::index()` / `advanceApi()` (`src/Controller/SetupWizardController.php:106`, `177-180`).
- **Worker impact:** with PHP-FPM, the next request boots a fresh kernel and picks up the new `.env.local`, the restored `config/` / `src/` and the rebuilt cache. A worker keeps the container, the environment variables, the resolved DBAL connection parameters and the class definitions it loaded at boot. After a restore it serves old code and old config; after the database URL step, the setup detectors, `SqlFileStep` and the DBAL progress storages in that worker still use the old connection (the console subprocesses do see the new URL, so the two can disagree). After `cache:clear`, a worker that lazily `require`s a container factory file that was removed or regenerated can fail; this depends on the container hash and was not verified. Same in scenario A and B (not a reset issue).
- **Recommendation:** restart the workers after a restore or after the setup wizard finishes (Caddy admin API `POST /frankenphp/workers/restart`, or redeploy the container). Prefer running restore from the CLI (`nowo:site-backup:restore`) and restart workers as part of that runbook. In the setup flow, put `database_url` and `cache_clear` last, or restart workers right after them.
- **Status:** Accepted — cannot be fixed in-process (a worker cannot reload its container, env and loaded classes). Mitigated with an explicit signal: `src/Worker/WorkerRestartSignal.php` writes `var/site-backup/worker-restart.required` (visible to every worker and the CLI) and dispatches `src/Event/WorkerRestartRequiredEvent.php`. It is raised by `RestoreOrchestrator::restore()` once files were applied (also on a failure after that point; a progress log line tells the operator to restart workers) and by `SetupOrchestrator::advance()` after a `cache_clear` step or a `database_url` step that wrote `.env.local`. `bin/console nowo:site-backup:worker-restart` reports it (exit code `3`) and `--clear` removes it after the restart. Hosts can listen to the event to call the Caddy admin API automatically.

### W-02 — `$schemaEnsured` flag goes stale when the setup tables are dropped (Medium)

- **Where:** `src/Setup/Storage/DoctrineDbalSetupProgressStorage.php` and `src/Setup/Storage/DoctrineDbalSetupStepJournal.php` (`private bool $schemaEnsured`).
- **Worker impact (before fix):** after the first successful `CREATE TABLE IF NOT EXISTS`, the flag stayed `true` for the life of the worker. If the database was later dropped or recreated, `ensureSchema()` was skipped and durable progress could be lost until the worker restarted. Same in scenario A and B because nothing reset it.
- **Status:** Resolved — both classes implement `ResetInterface` (autoconfigured `kernel.reset`). Every read/write runs through a `withSchema()` helper: when the operation fails and the memo was set before this call, the flag is cleared, `CREATE TABLE IF NOT EXISTS` runs again and the operation is retried once. Regression test: `tests/Unit/Setup/Storage/DoctrineStorageWorkerLifecycleTest.php` (same instances, tables dropped between two requests, **no** `reset()` — scenario B).

### W-03 — Long synchronous subprocesses inside HTTP requests (Low)

- **Where:** `src/Backup/BackupArchiver.php:359-392` (`tar`, DB dump via `Process::fromShellCommandline()`), `src/Setup/ConsoleProcessRunner.php:31-43` (`bin/console …`), `src/Setup/Step/RequirementsStep.php:53` (`exec('tar --version')`, no timeout, short-lived).
- **Worker impact:** timeouts are explicit and configurable (`process_timeout`, default 600 s, minimum 30 s, `src/DependencyInjection/Configuration.php:51-55`). A panel backup/restore or a wizard step still pins one of the limited worker threads for up to that time. If the worker is killed mid-restore (for example by `max_execution_time` or a deploy), `restore-progress.json` stays `active` and every visitor gets the restore page until an operator clears it. This is the same under PHP-FPM, but the thread pool is usually smaller in worker mode. Staging and temp directories are removed in `finally` blocks, so there is no leak on a normal exception.
- **Recommendation:** run backups and restores from the CLI or a Messenger worker when possible. Set FrankenPHP `max_wait_time` and size `num_threads` so that one long panel request does not starve the site. Keep `php_binary` pointing to a real PHP CLI: the default `php` exists in the official FrankenPHP Docker image but not next to a static `frankenphp` binary (use `frankenphp php-cli` wrappers there).
- **Status:** Accepted — timeouts are already explicit and configurable; this is a deployment concern, not state leakage. Since v1.16, `bump_time_limit: true` raises `set_time_limit()` for setup and panel requests only (`LongRequestTimeLimitSubscriber`, stateless).

### W-04 — Cold-start schema probe runs on every main request (Low)

- **Where:** `src/EventSubscriber/ColdStartSchemaGateSubscriber.php:49-64` calls `MysqlSchemaExistenceChecker::schemaExists()` (`src/Setup/ColdStart/MysqlSchemaExistenceChecker.php:43-117`) on every main request when `setup.cold_start.enabled` is true, even after setup is done. Without a DBAL connection it opens a new `PDO` per request (`:91-96`) with no `PDO::ATTR_TIMEOUT`.
- **Worker impact:** no state leak (the result is kept in a `Request` attribute). It adds `SELECT 1` + an `information_schema` query to each request, and an unreachable MySQL host blocks the thread for the driver connect timeout.
- **Recommendation:** enable `cold_start` only on images that really need it. Prefer the DBAL connection path, and set a short connect timeout in `DATABASE_URL` / driver options.
- **Status:** Resolved — the PDO fallback sets `PDO::ATTR_TIMEOUT` (5 s). Since v1.16 `MemoizedSchemaExistenceChecker` reuses a positive probe per worker for `setup.worker_memo.schema_probe_ttl` (default 60 s) through `Setup\Memo\WorkerTtlMemo` — deliberately **not** `ResetInterface`, bounded by the TTL, never caches a negative answer. The same memo covers `DoctrineConnectDetector` / `DoctrineSchemaEmptyDetector`, and `setup.worker_memo.progress_ddl_ttl` keeps the progress-table DDL from running on every request (the per-request `$schemaEnsured` flag is still reset by `kernel.reset`; the stale-table retry of W-02 is unchanged).

### W-05 — File markers are read with `is_file()` (Info)

- **Where:** `src/Setup/Storage/SetupMarkerManager.php:26-34`, `FilesystemRestoreProgressStorage.php:38`, `FilesystemSetupProgressStorage.php:35`.
- **Worker impact:** markers are re-read on every call, which is correct for a long-lived worker: changes written by the CLI or another worker thread are seen on the next request. PHP's stat cache holds only the last stat'd path and is cleared by PHP's own `unlink`/`rename`. I did not verify whether FrankenPHP clears it between worker requests. Given the many other `is_file()` calls per request, a stale result is unlikely.
- **Recommendation:** none needed. Custom `DurableSetupDoneStoreInterface` / detector implementations must not cache "done" in a property without `ResetInterface`.
- **Status:** Not a bug — markers are re-read per call.

### W-06 — `SetupRouteLoader::$loaded` guard (Info)

- **Where:** `src/Routing/SetupRouteLoader.php:23`, `46-50`.
- **Worker impact:** this is the standard Symfony custom-loader guard. It only runs while the router cache is built (warmup), not per request, so it does not affect normal worker traffic. In debug mode with a route resource change and no worker restart, a second load in the same process would throw "already loaded"; the demo `watch` directive restarts workers on change, which avoids this.
- **Recommendation:** none.
- **Status:** Accepted — standard Symfony loader guard, only used during router cache warmup.

### W-07 — Igor findings 2026-11-11 (Info)

Igor (igor-php 0.10.1) flagged five services. None keeps request, user or tenant data across requests:

| Igor finding | Verdict | Change |
|--------------|---------|--------|
| `AgeBinaryEncryptor::$refusal` mutated in `resolveBinary()` (KO) | False positive: configuration-derived, written only while the service is built | `resolveBinary()` now returns the binary and the refusal; both are `readonly`, set in the constructor |
| `ArchivePassword::$values` static (KO, INFO) | False positive: a `WeakMap` keyed by the password object (a value object, not a service); each entry dies with its object or on `wipe()` | Same-line `@igor-ignore` (the previous-line annotation sat above a PHPStan comment, so Igor never saw it) |
| `InMemoryRemoteTarget::$objects` (KO) | False positive: test / demo double, never registered as a service | `@igor-ignore` |
| `RestoreOrchestrator::$filesystem` "not reset in reset()" (WARN) | False positive: Symfony's `Filesystem` is stateless; the call deletes the restore's own staging directory | `@igor-ignore` on a local reference (Igor's reset rule ignores annotations). Hardening: `restore()` now also clears the caller's heartbeat closure (it holds the operation lock) and the throttle when it ends or refuses to start, instead of keeping them until the next restore / `kernel.reset` (`RestoreHeartbeatTest::testKeptAliveOrchestratorKeepsNoHeartbeatBetweenRestores`, five restores on one instance without `reset()`) |
| `DoctrineDbalSetupProgressStorage::$stepJournal` "not reset in reset()" (WARN) | False positive: `clear()` deletes journal rows in the database; the journal's only in-memory state (schema flag) is reset by `reset()` | `@igor-ignore`; `reset()` calls the journal without the nullsafe operator |

- 1.36 (PP delta 1): `BinlogBoundaries` (the structural cursor of one binlog filter run) mutates its own position on
  every line; it is created per capture / restore run inside `BinlogCapture` / `ChainRestorer` and never registered
  as a service. Its mutations carry a same-line `@igor-ignore`.
- **Status:** Closed — Igor 0 KO / 0 WARN; `igor` CI job added.

No other findings. Panel authentication is stored in the request session (`PasswordSiteBackupAccessGate.php:47-52`, `74`), and role checks call `isGranted()` per request (`ConfigurableSiteBackupAccessChecker.php:21-34`), so no user or auth decision is kept across requests.

### W-08 — Object-keyed static weak maps for secrets (Info)

- **Where:** `src/Model/ArchivePassword.php` (`private static ?WeakMap $values`) and `src/Setup/AdminUserData.php`
  (`private static ?WeakMap $passwords`, 1.35).
- **Why a static:** the plain secret must never sit in an object property, so `var_export()`, an `(array)` cast,
  `var_dump()`, VarDumper / the profiler and serialisation cannot print it (audit EO-L1). The value lives in a map
  owned by the class and keyed by the value object itself.
- **Why it is worker-safe:** a `WeakMap` holds its keys weakly. An entry exists exactly as long as its password object
  (a per-call value object, never stored in a service) and is removed earlier by `wipe()`; a clone gets no entry. The
  map can never serve one request's secret to another request: a lookup needs the very object that carries it, and a
  new request has no reference to an old request's objects. It is not request state, so it needs no boundary clear
  and no `ResetInterface` (there would be nothing to reset once the objects are gone).
- **Proof:** `WorkerModeKernelTest::testArchivePasswordWeakMapKeepsNoEntryAfterItsObjects` creates 2,000 passwords
  for two users in alternation, checks each reveals only its own value, and asserts the map is empty once the objects
  are released.
- **Status:** Accepted by design; same-line `@igor-ignore` (W-07).

## Worker test (REQ-RUNTIME-002)

`tests/Integration/Kernel/WorkerModeKernelTest.php` (runs in `make test` / CI):

- **Kernel:** one `DddHostKernel` (standard host, deprecated gate port, every default) booted once; requests go
  through the `http_kernel` service and `terminate()`, so `services_resetter` never runs between them.
- **Path and N:** `GET` panel `progress.json`, 500 rounds × 2 clients = **1,000 requests**.
- **Clients:** an operator logged in through the host gate (IP `10.9.0.1`) and an anonymous restore-page poller
  (`10.9.0.2`), in alternation. The operator always gets the full progress (log, error, backup id); the guest always
  gets only `active`, `phase`, `percent`, `message`.
- **Memory:** `memory_get_usage()` after the last round stays within **2 MiB** of the value after round 10.
- **Secrets:** the weak-map test of W-08.

Igor: `composer igor` 0 KO / 0 WARN (CI job `igor`). PHPStan FrankenPHP worker ruleset: included in
`phpstan.neon.dist`.

## Host Doctrine entity managers

The bundle uses no ORM and no EntityManager of its own (DBAL queries only). A host that disables or bypasses the
services resetter must clear its own entity managers at every boundary (main request start or end, Messenger message,
console command); otherwise the identity map serves stale rows across requests. This is a host requirement
(REQ-RUNTIME-002 rule 7); the bundle does not keep or return managed entities.

## Usage recommendations in worker mode

- Restart workers whenever `nowo:site-backup:worker-restart` reports a pending restart (written after a restore, `cache_clear` or `database_url`; W-01). Include `POST /frankenphp/workers/restart` (Caddy admin API) or a container restart in the restore runbook, then run `nowo:site-backup:worker-restart --clear`, or automate it with a `WorkerRestartRequiredEvent` listener.
- Prefer CLI commands (`nowo:site-backup:*`) for create / restore; keep the web panel for monitoring (`progress.json`).
- Keep `process_timeout` as low as your largest backup allows, and set FrankenPHP `max_wait_time`.
- Host implementations of `DurableSetupDoneStoreInterface`, `AdminUserProvisionerInterface`, `SetupNeedDetectorInterface` and `SetupTabCheckerInterface` must stay stateless (or implement `ResetInterface`) to keep this verdict.
- No `max_requests` / `FRANKENPHP_LOOP_MAX` limit is needed for memory: no service accumulates data.

## Re-audit triggers

Re-run this audit when a change adds: properties to any shared service (especially storages, detectors, orchestrators), a cache of progress / markers / schema state, runtime `putenv()` or `$_ENV` writes, new setup step types that modify code or config, or an in-process restore that swaps files while serving requests.

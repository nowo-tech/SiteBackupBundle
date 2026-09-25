# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/site-backup-bundle` (`symfony-bundle`) |
| Audited revision | `v1.14.0` (worker restart signal + DBAL schema self-heal) |
| Audit date | 2026-09-25 |
| Method | Manual review of every file under `src/` (services, subscribers, controllers, setup steps, storages, DI extension, compiler pass, `Resources/config/services.yaml`) |
| Remediation (2026-09-23 / 2026-09-25) | W-02 fixed (self-healing schema memo + `ResetInterface`); W-01 mitigated with a shared "restart workers" signal (`WorkerRestartSignal`, `WorkerRestartRequiredEvent`, `nowo:site-backup:worker-restart`); W-04 PDO connect timeout added; regression tests simulate two requests on the same instances without `reset()` |
| **Verdict** | ✅ **Viable under scenario B** — no cross-request state leak; the only remaining condition is operational: restart workers when the bundle raises the restart signal after a restore / `cache_clear` / `database_url` (W-01, cannot be fixed in-process) |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `DoctrineDbalSetupProgressStorage::$schemaEnsured` and `DoctrineDbalSetupStepJournal::$schemaEnsured`, now re-validated on query failure and reset by `kernel.reset` (W-02, resolved); every other service holds `readonly` config only |
| Static properties / `static` locals | ✅ | None; only static factory methods on models (`fromArray()`, `ok()`, …) and static closures in DI / form options |
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
| `Backup\BackupArchiver` | yes | none (`readonly` config + `Filesystem`) | ✅ | ✅ |
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
- **Status:** Accepted — timeouts are already explicit and configurable; this is a deployment concern, not state leakage.

### W-04 — Cold-start schema probe runs on every main request (Low)

- **Where:** `src/EventSubscriber/ColdStartSchemaGateSubscriber.php:49-64` calls `MysqlSchemaExistenceChecker::schemaExists()` (`src/Setup/ColdStart/MysqlSchemaExistenceChecker.php:43-117`) on every main request when `setup.cold_start.enabled` is true, even after setup is done. Without a DBAL connection it opens a new `PDO` per request (`:91-96`) with no `PDO::ATTR_TIMEOUT`.
- **Worker impact:** no state leak (the result is kept in a `Request` attribute). It adds `SELECT 1` + an `information_schema` query to each request, and an unreachable MySQL host blocks the thread for the driver connect timeout.
- **Recommendation:** enable `cold_start` only on images that really need it. Prefer the DBAL connection path, and set a short connect timeout in `DATABASE_URL` / driver options.
- **Status:** Resolved (partly) — the PDO fallback now sets `PDO::ATTR_TIMEOUT` (5 s) (`src/Setup/ColdStart/MysqlSchemaExistenceChecker.php`). The per-request probe itself is intended behaviour and accepted; for the DBAL path the connect timeout stays in `DATABASE_URL`.

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

No other findings. Panel authentication is stored in the request session (`PasswordSiteBackupAccessGate.php:47-52`, `74`), and role checks call `isGranted()` per request (`ConfigurableSiteBackupAccessChecker.php:21-34`), so no user or auth decision is kept across requests.

## Usage recommendations in worker mode

- Restart workers whenever `nowo:site-backup:worker-restart` reports a pending restart (written after a restore, `cache_clear` or `database_url`; W-01). Include `POST /frankenphp/workers/restart` (Caddy admin API) or a container restart in the restore runbook, then run `nowo:site-backup:worker-restart --clear`, or automate it with a `WorkerRestartRequiredEvent` listener.
- Prefer CLI commands (`nowo:site-backup:*`) for create / restore; keep the web panel for monitoring (`progress.json`).
- Keep `process_timeout` as low as your largest backup allows, and set FrankenPHP `max_wait_time`.
- Host implementations of `DurableSetupDoneStoreInterface`, `AdminUserProvisionerInterface`, `SetupNeedDetectorInterface` and `SetupTabCheckerInterface` must stay stateless (or implement `ResetInterface`) to keep this verdict.
- No `max_requests` / `FRANKENPHP_LOOP_MAX` limit is needed for memory: no service accumulates data.

## Re-audit triggers

Re-run this audit when a change adds: properties to any shared service (especially storages, detectors, orchestrators), a cache of progress / markers / schema state, runtime `putenv()` or `$_ENV` writes, new setup step types that modify code or config, or an in-process restore that swaps files while serving requests.

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.35.2] - 2026-11-12](#1352---2026-11-12)
- [[1.35.1] - 2026-11-12](#1351---2026-11-12)
- [[1.35.0] - 2026-11-11](#1350---2026-11-11)
- [[1.34.1] - 2026-11-11](#1341---2026-11-11)
- [[1.34.0] - 2026-11-10](#1340---2026-11-10)
- [[1.33.0] - 2026-11-10](#1330---2026-11-10)
- [[1.32.0] - 2026-11-08](#1320---2026-11-08)
- [[1.31.0] - 2026-11-08](#1310---2026-11-08)
- [[1.30.0] - 2026-11-06](#1300---2026-11-06)
- [[1.29.1] - 2026-11-05](#1291---2026-11-05)
- [[1.29.0] - 2026-11-04](#1290---2026-11-04)
- [[1.28.0] - 2026-11-04](#1280---2026-11-04)
- [[1.27.0] - 2026-11-02](#1270---2026-11-02)
- [[1.26.0] - 2026-10-30](#1260---2026-10-30)
- [[1.25.1] - 2026-10-30](#1251---2026-10-30)
- [[1.25.0] - 2026-10-29](#1250---2026-10-29)
- [[1.24.1] - 2026-10-28](#1241---2026-10-28)
- [[1.24.0] - 2026-10-28](#1240---2026-10-28)
- [[1.23.0] - 2026-10-28](#1230---2026-10-28)
- [[1.22.2] - 2026-10-24](#1222---2026-10-24)
- [[1.22.1] - 2026-10-21](#1221---2026-10-21)
- [[1.22.0] - 2026-10-21](#1220---2026-10-21)
- [[1.21.3] - 2026-10-19](#1213---2026-10-19)
- [[1.21.2] - 2026-10-18](#1212---2026-10-18)
- [[1.21.1] - 2026-10-18](#1211---2026-10-18)
- [[1.21.0] - 2026-10-18](#1210---2026-10-18)
- [[1.20.0] - 2026-10-15](#1200---2026-10-15)
- [[1.19.2] - 2026-10-13](#1192---2026-10-13)
- [[1.19.1] - 2026-10-13](#1191---2026-10-13)
- [[1.19.0] - 2026-10-13](#1190---2026-10-13)
- [[1.18.1] - 2026-10-13](#1181---2026-10-13)
- [[1.18.0] - 2026-10-13](#1180---2026-10-13)
- [[1.17.0] - 2026-10-12](#1170---2026-10-12)
- [[1.16.0] - 2026-10-09](#1160---2026-10-09)
- [[1.15.1] - 2026-10-09](#1151---2026-10-09)
- [[1.15.0] - 2026-10-07](#1150---2026-10-07)
- [[1.14.3] - 2026-10-01](#1143---2026-10-01)
- [[1.14.2] - 2026-09-28](#1142---2026-09-28)
- [[1.14.1] - 2026-09-27](#1141---2026-09-27)
- [[1.14.0] - 2026-09-25](#1140---2026-09-25)
- [[1.13.8] - 2026-08-24](#1138---2026-08-24)
- [[1.13.7] - 2026-08-21](#1137---2026-08-21)
- [[1.13.6] - 2026-08-20](#1136---2026-08-20)
- [[1.13.5] - 2026-08-20](#1135---2026-08-20)
- [[1.13.4] - 2026-08-20](#1134---2026-08-20)
- [[1.13.3] - 2026-08-20](#1133---2026-08-20)
- [[1.13.2] - 2026-08-19](#1132---2026-08-19)
- [[1.13.1] - 2026-08-18](#1131---2026-08-18)
- [[1.13.0] - 2026-08-15](#1130---2026-08-15)
- [[1.12.0] - 2026-08-15](#1120---2026-08-15)
- [[1.11.0] - 2026-08-15](#1110---2026-08-15)
- [[1.10.1] - 2026-08-07](#1101---2026-08-07)
- [[1.10.0] - 2026-08-04](#1100---2026-08-04)
- [[1.9.1] - 2026-08-04](#191---2026-08-04)
- [[1.9.0] - 2026-08-03](#190---2026-08-03)
- [[1.8.1] - 2026-08-01](#181---2026-08-01)
- [[1.8.0] - 2026-08-01](#180---2026-08-01)
- [[1.7.0] - 2026-07-31](#170---2026-07-31)
- [[1.6.0] - 2026-07-30](#160---2026-07-30)
- [[1.5.0] - 2026-07-30](#150---2026-07-30)
- [[1.4.0] - 2026-07-30](#140---2026-07-30)
- [[1.3.2] - 2026-07-30](#132---2026-07-30)
- [[1.3.1] - 2026-07-30](#131---2026-07-30)
- [[1.3.0] - 2026-07-30](#130---2026-07-30)
- [[1.2.0] - 2026-07-29](#120---2026-07-29)
- [[1.1.0] - 2026-07-29](#110---2026-07-29)
- [[1.0.0] - 2026-07-26](#100---2026-07-26)

## [Unreleased]

Target: **1.36.0** (minor). Incremental backups (Beacon request 2026-11-04, block E0 items 9–11). Everything is
opt-in: existing options, defaults, archives, sidecars and events are unchanged.

### Added

- **Binlog coordinates in the full dump (item 9).** `BackupOptions::$recordBinlogCoordinates` runs the data pass of the
  built-in dumper with `--source-data=2` (and `--set-gtid-purged=COMMENTED` from 8.0.32) and records `binlog_file`,
  `binlog_position`, `gtid_executed` and `server_uuid` (plus `binlog_database`) in the meta; the backup starts a chain
  (`type: full`, `chain_id`). Needs `backup.database.dumper: builtin` and the **Oracle** MySQL client tools 8.0.26+
  (the MariaDB tools cannot read MySQL binlog coordinates; full-only hosts keep using them). Refused with table rules.
  CLI: `nowo:site-backup:create --record-binlog`.
- **Binlog capture (item 10).** `BinlogCaptureInterface` / `BinlogCapture::capture(BinlogCoordinates $from, string
  $database): IncrementalArtifact`: `FLUSH BINARY LOGS`, then `mysqlbinlog --read-from-remote-server --raw` of every
  closed log from `$from` to the one just closed (whole files, checked against the server's sizes, SHA-256), the
  end coordinates and the first / last event time (`TZ=UTC`). A typed `BinlogGapException` (`expired`,
  `server_changed`, `position_invalid`) when `$from` left the server or belongs to another server: take a full backup
  (`nowo:site-backup:create` exits 3). `BinlogPrerequisitesInterface` / `BinlogPrerequisites::check(): list<Check>`
  (binaries, server, `log_bin`, `binlog_format`, `binlog_row_image`, expiry, grants, `gtid_mode`) and
  `nowo:site-backup:binlog-check`. Credentials reach the tools only through a 0600 option file
  (`--defaults-file`, private directory, removed in a `finally`), never argv. New `BinlogServerInterface` SPI.
- **File deltas and chains (item 11).** `BackupOptions::$recordFileManifest` records the full file manifest (path →
  size, mtime, SHA-256; `file_manifest` + `file_manifest_sha256`). `BackupOptions::$baseManifest` (with `$parentId`,
  `$chainId` and, for the database, `$binlogFrom`) creates an incremental: only changed files, plus `DELETED.json`,
  plus the binary logs since the parent; the meta gains `type`, `chain_id`, `parent_id`, `base_manifest_sha256`,
  `binlog_from`, `binlog_files`, `first_event_at`, `last_event_at` and the full manifest for the next delta.
  `IncrementalOptions::fromParentMeta()` and `SiteBackupManager::incrementalOptions($parentId)` build the next
  incremental's options (the remote sidecar's new `chain` block is enough when the local copy is gone). CLI:
  `--record-file-manifest`, `--incremental-of=<id>`.
- **Chain restore.** `SiteBackupManager::restoreChain($headId, ?DateTimeImmutable $until)` / `ChainRestorer` /
  `nowo:site-backup:restore-chain <head> [--until=…] [--check] --force`: verifies the whole chain before touching
  anything (`ChainVerifier`: parents present, contiguous binlogs, one `server_uuid`, manifest links; every archive's
  SHA-256 / signature / MANIFEST; binlog hashes; the final file set against the last manifest), decodes the binlogs
  (`--skip-gtids --database --start-position --stop-datetime`), then imports the dump, replays the binlogs
  (`mysql --binary-mode`), writes the folded file set and deletes the deleted files. `ChainBrokenException` = nothing
  changed; `ChainRestoreException` carries a `ChainRestoreReport` that lists every step as applied / failed / not
  applied / skipped.
- **Chain import.** `importArchive()` accepts a list of paths (full first) and `importChain()` returns the imported
  chain; parents are relinked to the new ids, the chain is verified and removed again on any problem. An incremental
  imported alone is refused.
- **Chain-aware retention.** `RetentionItem` gains `chainId` / `parentId` (`RetentionItem::fromMeta()`);
  `RetentionPlanner` prunes whole chains only and never returns an ancestor of a kept backup. Local retention deletes
  newest first; remote retention keeps a chain whole when one of its sidecars is untrusted.
- `IncrementalBackupManagerInterface` (new port, implemented by `SiteBackupManager`; `SiteBackupManagerInterface` is
  unchanged). Config: `backup.database.mysqlbinlog_binary`, `backup.database.binlog_min_expiry_seconds`.
- CI: job "Binlog incrementals (MySQL 9)": MySQL 9.7 with binary logs, the Oracle `mysqlbinlog` 9.7.2 (from the
  pinned client RPM): full → writes → incrementals → point-in-time restore, gap detection, password absent from argv
  and logs.

### Changed

- `nowo:site-backup:restore` / `restore()` refuse an incremental backup (restore its chain), and `deleteBackup()`
  refuses a backup an incremental depends on (delete the chain from its head). Neither existed before 1.36.

### Documentation

- `docs/SECURITY.md`: replace the "re-audit pending" checklist cell with the recorded audits (Appendices CG, IR, KH, KZ, LX, MJ) and their dates (REQ-SEC-004).

## [1.35.2] - 2026-11-12

Target: **1.35.2** (patch).

### Fixed

- `lint:container` failed on 1.35.0 and 1.35.1 for a host that configures `security.actor_resolver` or
  `setup.admin_provisioner` with a service implementing only one of the two ports: "Invalid alias definition: alias
  `AdminUserProvisionerInterface` is referencing class ... but this class does not implement
  `AdminUserProvisionerInterface`" (and the same for `ActorResolverInterface`, or for the new port with a host still on
  the deprecated one). This is a 1.35.0 bug, not a 1.35.1 regression: 1.35.0 aliased both the deprecated port and its
  replacement to the configured service, and Symfony's `CheckAliasValidityPass` only runs in `lint:container`, so
  `cache:clear` and runtime did not show it. Affected: `>= 1.35.0, < 1.35.2`.
  A new `PortAliasValidityPass` now keeps each port alias only when the configured service implements that port
  (`AdminUserProvisionerInterface` / `AdminAccountProvisionerInterface`, `ActorResolverInterface` /
  `PanelActorResolverInterface`, `SiteBackupAccessGateInterface` / `PanelAccessGateInterface`), and the panel
  controller and the setup step factory get the configured service directly. The gate aliases keep pointing at the
  throttling wrapper, which implements both ports; an inner gate on the deprecated port only still denies a
  `PanelRequestContext` call (fail closed, audit MJ). A configured service that implements neither port now fails the
  container build with a clear message instead of a type error at runtime.

### Tests

- `PortAliasValidityKernelTest` boots a host kernel and runs `lint:container` for a gate, actor resolver and admin
  provisioner on the new port only, the deprecated port only, and both; `PortAliasValidityPassTest` covers the pass.

## [1.35.1] - 2026-11-12

Target: **1.35.1** (patch, tests and docs only; no runtime change).

### Changed

- REQ-RUNTIME-002 worker test: `WorkerModeKernelTest` boots one kernel and sends 1,000 panel `progress.json` requests
  through `http_kernel` (so the services resetter never runs between them), as a logged-in operator and an anonymous
  poller in alternation; each response holds only that client's view and memory stays within 2 MiB. A second test
  proves the `ArchivePassword` weak map keeps no entry once its password objects are gone.
- `docs/FRANKENPHP-WORKER-AUDIT.md` refreshed from v1.14.0 to v1.35.1: the 1.35 services, the precise worker
  precondition (the stock kernel still runs the services resetter; state that only it clears leaks when a host
  bypasses it, in Messenger consumers with `--no-reset` and in long console commands), why the object-keyed static
  weak maps of `ArchivePassword` and `AdminUserData` are worker-safe (W-08), the worker test, and the host rule for
  Doctrine entity managers. The `RestoreOrchestrator` heartbeat fix and the `igor` CI job shipped in 1.34.1.

## [1.35.0] - 2026-11-11

Target: **1.35.0** (minor, no breaking change). See [UPGRADING](UPGRADING.md#unreleased).

### Added

- **Framework-free panel ports (REQ-ARCH-001 rule 3):** `Security\PanelAccessGateInterface` and
  `Security\PanelActorResolverInterface` take a bundle-owned `Security\PanelRequestContext` (client IP, user identifier,
  `Security\PanelSessionInterface` session) instead of a Symfony `Request`; `PanelRequestContextFactory` and
  `HttpFoundationPanelSession` adapt the request. `security.access_gate` / `security.actor_resolver` accept a service
  on either port; new aliases `PanelAccessGateInterface` (the throttled gate) and `PanelActorResolverInterface`.
  `PasswordSiteBackupAccessGate`, `ThrottledSiteBackupAccessGate`, `StaticActorResolver` and
  `SecurityTokenActorResolver` implement both ports with one shared decision. The throttled gate calls an inner gate
  through the new port when it implements it, passes it the token user identifier, and denies a context call that
  could only reach an inner gate on the deprecated port (fail closed).
- **Typed admin provisioner (rule 1):** `Setup\AdminAccountProvisionerInterface::createAdminAccount(AdminUserData)`
  with the `Setup\AdminUserData` value object (email, roles, and the plain password behind `plainPassword()`: kept in a weak map like `ArchivePassword`, masked in `json_encode()`, `var_export()`, `var_dump()` and VarDumper / the profiler, not serialisable).
  `setup.admin_provisioner` accepts either port; the typed one wins when both are implemented.
- **Host setup step types (rule 5):** `#[AsSetupStep(type: '…')]` / tag `nowo.site_backup.setup_step` registers a
  `SetupStepInterface` service as a profile step type (`SetupStepsPass`). Built-in type names and duplicates fail the
  build, and so does a host type used twice in one profile; `SetupStepFactory` also refuses a reserved key and a repeated
  host type at runtime. `InitialInstallOnlyStepInterface` keeps a host step out of a re-opened wizard.
- **`setup.dbal_connection` (rule 8):** the DBAL connection of the setup progress storages, database detectors, the
  `sql_file` import (seeds go into that connection's database) and the cold-start gate. Default unchanged (`doctrine.dbal.default_connection`, ignored without
  DoctrineBundle); another id must exist.
- Tests: old-vs-new equivalence (password gate, throttled gate with a legacy vs a port host gate, actor resolvers,
  admin provisioning) and a DDD-host kernel wired only with the new ports.

### Deprecated

- `Security\SiteBackupAccessGateInterface`, `Security\ActorResolverInterface` (take a Symfony `Request`) and
  `Setup\AdminUserProvisionerInterface` (array shape with the plain password): use the ports above. They keep working
  until 2.0; the container build reports a deprecation for each configured host service that implements one without
  its replacement.

### Changed

- Docs: USAGE section "Standard host and DDD host" (replaces "Using it from a DDD / hexagonal application"), host step
  types in SETUP-WIZARD, new keys in CONFIGURATION.
- `composer.json` requires `symfony/deprecation-contracts` explicitly (already installed through Symfony).
- `SetupStepFactory` refuses a `$customSteps` key that is a built-in type (`SetupStepFactory::RESERVED_TYPES`), so a
  host step can never replace a built-in one (audit MJ-S-L2). Before 1.35 the bundle always passed `[]`; only code
  that built the factory by hand with such a key is affected.

## [1.34.1] - 2026-11-11

Target: 1.34.1 (patch).

### Fixed

- FrankenPHP worker mode (kernel kept alive, no services resetter): `RestoreOrchestrator::restore()` clears the
  caller's heartbeat closure (it holds the operation lock) and the heartbeat throttle when the restore ends or is
  refused, instead of keeping them in the shared service until the next restore or `kernel.reset`.

### Changed

- Igor worker-state audit (`composer igor`) clean: 0 KO / 0 WARN. The five findings of 2026-11-11 were false positives
  (triage in `docs/FRANKENPHP-WORKER-AUDIT.md`, W-07): `AgeBinaryEncryptor` resolves the binary and the refusal reason
  once into `readonly` properties; `ArchivePassword`, `InMemoryRemoteTarget`, `RestoreOrchestrator` and
  `DoctrineDbalSetupProgressStorage` are annotated.
- CI: standalone `igor` job (REQ-CS-008).

## [1.34.0] - 2026-11-10

Security: restore and setup failure messages are redacted and capped before they reach events, history, progress files, the panel and the console (GHSA). See [UPGRADING](https://github.com/nowo-tech/SiteBackupBundle/blob/v1.34.0/docs/UPGRADING.md#to-1340).

### Security

- **Restore and setup failures no longer store or dispatch raw exception messages.** A PDO / Doctrine / `mysql` /
  `psql` error can quote the DSN, a `password=` pair or a failing `ALTER USER … PASSWORD '…'` statement. Since 1.0.0 the
  restore failure wrote `$e->getMessage()` verbatim to `RestoreFailedEvent::getError()`, the `restore_failed` history
  entry (history file, panel history page), the restore progress `error` and log (panel status notice, `progress.json`
  for authenticated pollers), and the panel error message. All of them now get
  `SecretRedactor::fromThrowable()` (redacted, ≤ 500 characters), like `BackupFailedEvent` since 1.28. The same applies
  to a failed setup step (setup progress `error` / `message` / log, wizard page, `SetupStepFailedEvent::getError()`)
  and to the error lines of the console commands. Logs were already redacted.

### Added

- `RestoreFailedEvent::getException()` / `getExceptionClass()`: the original throwable, in memory only. It is
  **sensitive** (unredacted message and trace): never persist, serialize, forward or render it.
- `Log\SecretRedactor::forStorage()` / `fromThrowable()` (UTF-8 scrub + redact + cap) and `STORAGE_MAX_LENGTH`.
  `forStorage()` replaces invalid UTF-8 bytes first, so a latin1 snippet in a driver error can no longer make the
  history / progress JSON write throw and leave a failed restore shown as "active" (PHP 8.2–8.4).

### Changed

- `SecretRedactor::redact()` masks more (independent audit KZ): a URL password with a raw `@` up to the last `@` of the
  token; backslash-escaped quotes in `password='…'` / `"…"` and in SQL `IDENTIFIED BY '…'` / `PASSWORD '…'` (doubled
  quotes too); `IDENTIFIED WITH … BY|AS '…'`; `--password <value>`; quoted argv (`'-pX'`, `'-p' 'X'`,
  `'--password=a b'`); env and `=` keys in any case (`database_password=`, `secretKey=`); YAML `*_secret:` /
  `accessKeyId:`; XML elements (`<SecretAccessKey>`); `passphrase`; ODBC `Pwd={…}`; age identities. Only the exact
  MySQL hint `(using password: YES|NO)` stays readable (a password starting with `YES)` is masked).

## [1.33.0] - 2026-11-10

Target 1.33.0.

### Added

- `Service\SiteBackupManagerInterface`: the application API (aliased to `SiteBackupManager`, which stays `final`). The panel, the restore / setup request subscribers, the commands and the Twig extension now depend on the interface, so a host alias or decorator replaces the manager everywhere.
- `Security\ActorResolverInterface` (`security.actor_resolver`): the actor recorded for panel create / delete / restore (history, events, backup `createdBy`). Default `StaticActorResolver` (`panel`, as before); opt-in `SecurityTokenActorResolver` records the logged-in user identifier. Every resolved actor (host resolvers too) is normalised before use: controls, tab / newline and format characters dropped, at most 128 characters (a valid `createdBy`), `panel` when nothing is left.

### Changed

- The panel passes the security token user to `SiteBackupAccessCheckerInterface::canAccess()` (it always passed `null`). The built-in checkers ignore the argument; `null` is still passed when anonymous or without SecurityBundle.

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [1.32.0] - 2026-11-08

### Added

- **Re-opened wizard runs only the missing steps (owner decision IN-I1):** `SetupWizardReopener::reopen()` writes a server-side `setup.reopened` marker (`setup.reopened_marker_file`, default `setup.reopened` in the directory of `done_marker_file`) before it clears `setup.done` / the durable store. While it exists, `SetupOrchestrator::getSteps()` / `advance()` leave out `admin_user`, `database_url`, `bootstrap_mode` and `sql_file` (`InitialInstallOnlySteps`), for the page (GET and POST), the JSON API and the CLI; host steps can opt in with `InitialInstallOnlyStepInterface`. The marker is removed when the wizard completes or by `nowo:site-backup:setup-reset --initial`. The wizard template gets `reopened`. Token model unchanged. Audit status: re-verification pending.

### Fixed

- **Audit IR:** the default `reopened_marker_file` follows a moved `done_marker_file` (IR-L1 a); a failed `reopen()` on the site gate (e.g. the marker cannot be written) is logged and keeps the instance done instead of answering 500 (IR-L2). Docs: the marker is local, so a new node or a `var/` wipe during a reopen looks like an initial install (IR-L1 b, durable reopened state is a follow-up); a plain `setup-reset` or a hand-deleted `setup.done` also gives a full install (operator action, IR-I2); host, `console` and `sample_data` steps still run on a reopen unless they implement `InitialInstallOnlyStepInterface` (IR-I3).
- **IN-L2:** `setup.reopen_when_detector_requires` (1.15) now goes through the 1.31 reopen guards: after done, detectors run only for the site gate on a main `GET` / `HEAD` outside the setup, panel and excluded paths (no reopen from `POST`, the wizard, `SetupDbDoneGuard`, the commands or `getReasons()`); a throwing detector counts as "not required" and is logged instead of answering 500; `DoctrineConnectDetector` / `DoctrineSchemaEmptyDetector` are not reopen signals once setup is done. SECURITY, SETUP-WIZARD, CONFIGURATION and the config info say it is fixed. Audit status: re-verification pending.

## [1.31.0] - 2026-11-08

### Added

- **Setup gate audience (SB-1):** `setup.gate: all|guests_and_admins` (default `all`, unchanged) and `setup.gate_admin_role` (default `ROLE_ADMIN`). `guests_and_admins` lets signed-in users without that role keep browsing while setup is required (no detector probe on their requests); the gate then runs after the firewall (priority 7). Without security-bundle everyone counts as a guest. See [SECURITY](SECURITY.md#gate-audience-setupgate-131).
- **Per-detector re-open (SB-1):** `#[AsSetupNeedDetector(reopen: true)]` / tag attribute `reopen: true`: that detector is still evaluated after setup is done and, when it requires setup, re-opens the wizard (`SetupWizardReopener`), without turning on `reopen_when_detector_requires` for every detector. Compiler pass `SetupReopenDetectorsPass`. Guards (audit IN): reopen detectors run only from the site gate, on a main `GET` / `HEAD` outside the setup, panel and excluded paths (the gate now checks those paths before any detector); a reopen detector that throws counts as "not required" and is logged (no reopen, no 500); `reopen: "true"` (XML) is normalised and a non-boolean value fails the compilation. Contract: a reopen detector never fails to `true`.
- **Gate:** with `guests_and_admins`, a lazy firewall's `LazyResponseException` is rethrown instead of counting as a guest.

### Fixed

- Tests: `RemotePublisherTest` no longer depends on a fixed date (its orphan assertions failed from 2026-11-08).

### Documentation

- **IN-L2:** SECURITY, SETUP-WIZARD and the `setup.reopen_when_detector_requires` config info now warn that the 1.15 global flag has none of the 1.31 reopen guards and that `doctrine_connect` reports a need while the database is unreachable, so a database blip re-opens a live wizard; prefer `reopen: true` on a never-fail-true detector.

## [1.30.0] - 2026-11-06

### Security

- Independent audit Appendix HK (2026-11-06): verified at `2e54dd1`, no Critical or High, nothing above Low open at that head. The two Medium findings below exist in published versions and are fixed here.
- **`backup.extract_limits` now holds for zip archives on the bytes really written.** The limit check summed
  `ZipArchive::statIndex()['size']`, which is only what the archive declares, and `extractTo()` then wrote whatever the
  deflate data held: a 1 MiB plaintext zip declaring 147 bytes wrote 1 GiB past its 199 MiB limit (ratio 200), and verify and
  import reported success. Entries that share one compressed stream multiply that, past `max_bytes` too. The same goes for AES-256 zips made by someone who holds the password, because the HMAC does not
  cover the declared sizes. Entries now go through counted streams: nothing over the limit reaches the disk, and
  everything the call wrote is removed on failure. Affects 1.28.0, 1.29.0 (never tagged) and 1.29.1: zip reached through
  `extractTo()`, `verifyIntegrity()` (panel verify and restore, `nowo:site-backup:verify <id>`, `nowo:site-backup:drill`),
  `verifyFile()` (`nowo:site-backup:verify --file`) and `import()` (`nowo:site-backup:import`). Availability only.

- **The AES authentication of 1.29.1 could be bypassed.** `ZipAesAuthenticator` found the central directory on its own,
  and libzip finds it differently: an end record planted in the zip comment with no entries made the check pass having
  authenticated nothing, while libzip extracted the real entries. No password is needed to plant it. The entries and
  their raw bytes now come from libzip, so what is authenticated is what is extracted. A second end record, in the
  comment or anywhere libzip could read it, data after the end record, a non-AES entry and an empty archive are refused.
  Affects 1.29.1 only: 1.29.0 did not authenticate at all (fixed by 1.29.1) and 1.28.0 refuses encrypted zips.
- **A password now refuses plaintext on every path.** `extractTo()` and `verifyIntegrity()` accepted a plaintext zip or
  tar.gz when given a password, so verify `<id>` and the drill too; only import and verify `--file` refused it. Pass
  `allowPlaintext` (or `--allow-plaintext` on verify) to accept one.
- **`verifyIntegrity()` no longer unpacks an archive that failed its SHA-256 or its signature.**
- **Zip `extractTo()` leaves what it did not create alone.** A path that already exists, or a link on the way to one,
  now fails the extraction. Nothing is written through a link, and a failure removes only what the call created.

### Changed

- The tar.gz listing is read while tar runs and is not kept, and a link or an entry over `max_entries` stops tar at once.
- Zip extraction reports a wrong CRC or a damaged entry as `zip extract failed.` on every PHP version. Before, PHP < 8.4
  ignored these errors and the MANIFEST checksums were the only check. When a zip repeats a name, the last entry wins on
  every PHP version, as it already did on 8.4+. Paths, modes (the umask, as before: zip extraction has never applied the
  modes stored in the archive), mtimes and the name, link and mixed-AES checks are unchanged, and the HMAC check still
  comes before any write.

## [1.29.1] - 2026-11-05

### Security

- **Zip AES entries are now always authenticated.** On PHP < 8.4, `ZipArchive::extractTo()` ignores libzip's read
  errors, so an AES-256 entry whose ciphertext had been modified was extracted (as altered plaintext) and reported as
  success: the WinZip AE HMAC-SHA1 was never enforced. A flipped byte in `MANIFEST.json` (which carries the checksums,
  not one of its own) then passed `verifyFile()` / `verifyIntegrity()` / import / restore. The new
  `ZipAesAuthenticator` checks every AES entry's authentication code (and password verifier) in PHP before anything is
  extracted, on every PHP version, ZIP64 included. Cost: one PBKDF2-SHA1 (1000 iterations) per entry, which libzip
  also does when it extracts.

### Fixed

- The zip tamper regression test flipped one byte, which hit the case above only on some PHP versions and archive
  layouts (red CI on main after the 1.29.0 release). It now flips every byte of every encrypted entry and expects an
  integrity failure (or a wrong password inside the salt / verifier).

## [1.29.0] - 2026-11-04

> **Never tagged.** CI on the 1.29.0 release commit revealed the zip AES authentication gap fixed in 1.29.1; install 1.29.1.

Archive passwords, archive import, remote targets, optional Ed25519 signatures and the restore drill (1.29.0,
contract "SiteBackupBundle 1.28 – 1.30" v1.0 with symfony-beacon).

### Added

- `ArchivePassword` (≥ 16 characters, masked, never serialisable, `wipe()`), `BackupOptions::$password` /
  `$keyId` (opaque id recorded in the meta and the remote metadata). `zip` + password → WinZip AE-2 AES-256 on every
  entry (`ZipArchive::EM_AES_256`, refused when libzip lacks AES; never ZipCrypto); a warning when the password looks
  weak for zip's PBKDF2-SHA1 × 1000. A password on `tar.gz` / `sql.gz` uses age (see below).
- `verifyIntegrity()` / `extractTo()` / `SiteBackupManager::verifyBackup()` take the password; a missing or wrong one
  throws / reports `WrongPasswordException`.
- `SiteBackupManager::importArchive($path, $password)` and `nowo:site-backup:import`: detects zip / tar.gz / sql.gz by
  content, decrypts, refuses unsafe names, links and ZipCrypto (`CorruptArchiveException`), verifies the MANIFEST,
  drops secret paths and stores a plaintext `tar.gz` (`0600`) with `imported_from` in the meta; `BackupImportedEvent`.
- Remote targets: `BackupRemoteTargetInterface` (host-implemented: upload / head / list / delete / openRead, streams
  only), `RemoteObject`, `InMemoryRemoteTarget` (tests), and `RemoteBackupPublisher`: upload → verify (`size`,
  `size + nowo-sha256 metadata`, or full re-hash) → `<key>.backup.json` sidecar (no secrets, no local paths); a failed
  or mismatched upload is deleted and gets no sidecar. `prune()` applies a `RetentionPolicy` to sidecared archives
  under the prefix only; `orphans()` lists sidecar-less archives. Events `RemoteBackupUploadedEvent`,
  `RemoteBackupPrunedEvent`.
- `--password-env` / `--password-stdin` on `nowo:site-backup:create`, `verify` and `import` (no `--password=<value>`),
  `--key-id` on `create`.
- `SecretRedactor` also masks signed-URL and token query parameters (`X-Amz-Signature`, `X-Amz-Credential`, `token`, …).
- age for `tar.gz` / `sql.gz` passwords (owner decision Q-O4, option A): `AgeBinaryEncryptor` drives the `age` binary
  ≥ 1.3 with `age-plugin-batchpass`, the passphrase on fd 3 only (never argv / environment); `.tar.gz.age` /
  `.sql.gz.age`; first-use check of the binary, its version and the plugin; `backup.encryption.age.*`. Verify,
  extract and import handle `.age` files; CI installs age 1.3.1 and runs CLI interop tests both ways.
- Optional Ed25519 signatures (`backup.signing.*`, off by default): `signature` / `signing_key_id` in the local and
  remote sidecars, checked by `verifyIntegrity()` and `verify --file`; `nowo:site-backup:signing-keygen`.
- `nowo:site-backup:verify --file=<archive> [--sha256] [--sidecar] [--password-*]`: checks any archive without
  storing it; exit codes 0 ok, 1 integrity, 2 password, 3 unsupported / corrupt, 4 signature.
- Restore drill: `BackupOptions::$recordTableStats` (row counts + `CHECKSUM TABLE` in `table_stats`),
  `TableStatsCollector`, `DumpImporter::importInto()` / `createSchema()` / `dropSchema()`, `RestoreDrill` (throwaway
  schema, always dropped; `DrillReport`) and `nowo:site-backup:drill`.
- `FlysystemRemoteTarget` (optional, league/flysystem 3) and `backup.remote.flysystem_storage`.
- Archive passwords refuse CR / LF.

### Security (REQ-SEC-004 crypto audit EO)

- **EO-M1:** with a password, `importArchive()` / `verify --file` refuse plaintext archives (exit 5, `allowPlaintext`
  to accept) and zips mixing AES and plaintext entries (always); `encrypted` comes from the container, never the
  MANIFEST.
- **EO-M2:** `backup.signing.required` (default true once a key is configured): unsigned or wrongly signed sidecars
  fail verify (exit 4), import (`ImportOptions::$sidecar`, `--sidecar`, `--require-signature`) and are ignored by
  prune. What the signature covers is documented.
- **EO-M3:** imports into another schema (restore drill) refuse dumps that switch / create databases, run the client
  with `--one-database`, and `backup.drill.database_url` lets the drill use an account limited to its schema.
- **EO-L1:** `ArchivePassword` keeps its value in a weak map (nothing for `var_export`, `(array)` or VarDumper; a
  VarDumper caster prints `***`).
- **EO-L3 / EO-L4:** prune ignores future-dated sidecars and sidecars whose id, size or sha256 do not match the
  object; `publish()` refuses an existing key (a failed upload can no longer delete the previous good object).
- **EO-L5:** age and its plugin are pinned: resolved once, the plugin must sit next to the binary, and age runs with
  `PATH` = that directory only.
- **EO-L6:** imported archives are deleted after a successful restore (`deleteAfterRestore`, default true).
- **Info:** tampered / truncated files are reported as integrity failures, not wrong passwords; `max_work_factor`
  defaults to 20 (max 22); sidecars are read in a loop;
  stale docs fixed; the `/proc` test ignores fork-before-exec samples.

### Security (REQ-SEC-004 EO re-audit residuals)

- **EO-R1:** drilling an imported archive requires `backup.drill.database_url` (a restricted account): with the live
  account, statements on `live_db.table` would still reach production. The drill pre-scan also refuses statements
  whose target is qualified with another database (`INSERT INTO app.t`, `LOCK TABLES`, `ALTER TABLE`, …; best effort).
  A missing URL database now says so (`ERROR 1049`). USAGE documents the drill account and grants.
- **EO-R2:** REMOTE-TARGETS documents that, without signing, planted sidecars can steer prune into deleting genuine
  backups, and recommends `backup.signing` for every shared remote target.
- **EO-R4:** `encryption.age.binary` is resolved once to an absolute path when the service is built, a bare-name
  lookup is logged (notice, `site_backup` channel), and relative paths or world-writable binaries / directories are
  refused. An absolute path is recommended.
- **EO-L2 / EO-I9 tests:** the tar size limit is tested with bsdtar too (CI coverage job); the `/proc` scan keeps only
  stable samples.
- Dev lock: `symfony/*` 7.4.23 (Symfony advisories of 2026-11-04; runtime constraints unchanged).

## [1.28.0] - 2026-11-04

Scheduler-friendly local backup engine (1.28.0, contract "SiteBackupBundle 1.28 – 1.30" v1.0 with symfony-beacon,
spec `specs/008-local-backup-engine`). No cryptography and no network yet (archive passwords, remote targets and
`importArchive()` follow in 1.29.0).

### Added

- `SiteBackupManager::createBackup(BackupOptions)`: per-call options (`includeDatabase`, `includeFiles`,
  `includePaths`, `tableRules`, `format`, `compressionLevel`, `label`, `createdBy`, `heartbeat`,
  `allowUnsafeTableRules`, `extraMeta`). The 1.27 call `createBackup($label, $createdBy)` keeps working.
  `defaultOptions()` returns what the panel and the CLI use. No HTTP / session access: safe from a Messenger handler.
- Archive formats `tar.gz` (any level 0–9; tar is now streamed through PHP's gzip), `zip` (ext-zip) and `sql.gz`
  (database only, checksum of the SQL in the sidecar). `verifyIntegrity()`, `extractTo()` and restore handle all
  three. Zip extraction refuses `..`, absolute names, symlinks and encrypted entries.
- Built-in dumper (`backup.database.dumper: builtin`): `mysqldump` runs directly into the staging file
  (`--result-file`, no shell, no buffered plaintext copy), with `--set-gtid-purged=OFF` on the Oracle client.
- Table rules (`TableRule::Skip`, `TableRule::StructureOnly`): data pass with `--ignore-table`, then a `--no-data` pass
  for structure-only tables. Rules are checked against the live schema (`information_schema` through the `mysql`
  client): unknown tables, views, `backup.database.required_tables` and the migrations table are refused; `skip` is
  refused for a table referenced by a foreign key or read by a view (`allowUnsafeTableRules` downgrades those two to
  warnings); structure-only on a referenced table warns. `nowo:site-backup:db-dump` gains `--ignore-table`,
  `--structure-only` and `--allow-unsafe-table-rules`.
- Secrets floor (`SecretPathPolicy`): `.env*` (except the committed `.env.dist` / `.env.example` at the root),
  `config/secrets/**`, `var/secrets/**`, `config/jwt/**`, every `*.key` / `*.pem` / `*.p12` / `*.pfx` / `*.jks`,
  `id_rsa*`, `id_ed25519*`, `auth.json`, `.npmrc`, `.git-credentials`, `.netrc`, `.pgpass`, `.my.cnf` never enter an
  archive (also through symlinks) and are never written by a restore. `backup.forbidden_paths` adds patterns. The
  final file list is checked again before packing (`SecretsInArchiveException`).
- `backup.work_dir` (default `var/site-backup/work`): owner-only (`0700`) staging root; each run removes its directory
  in a `finally`, the next backup removes leftovers of killed runs, `nowo:site-backup:cleanup` does it on demand.
  Archives are built there and moved into `storage_dir` only when complete.
- Events `BackupStartedEvent` and `BackupFailedEvent` (exception class + redacted message, no exception object);
  `BackupCreatedEvent` gains `getOperationId()`, `getFormat()`, `getWarnings()`; `SiteBackupEvents` lists every event.
- Operation lock (`BackupOperationLock`): one backup / delete / restore at a time, through `lock.factory` when
  available (refreshed on every heartbeat) or an `flock()` in `storage_dir`; a held lock throws
  `BackupAlreadyRunningException`.
- `RetentionPlanner` (pure; keep last N, max age, daily / weekly / monthly buckets) and `backup.retention` for
  `storage_dir`.
- `DumpImporter`, `nowo:site-backup:db-import <file> --force` and `sql_file` steps with `importer: client`: the dump
  is streamed through the `mysql` client instead of being loaded into PHP memory.
- `nowo:site-backup:create` options `--format`, `--level`, `--no-database`, `--no-files`, `--path`, `--skip-table`,
  `--structure-only`, `--allow-unsafe-table-rules`, `--json`; exit code 75 while another operation runs.
- `backup.preflight_disk_check` (default on): refuses to start when `work_dir` or `storage_dir` has less than about
  three times the last archive free.
- MANIFEST v2 / sidecar keys: `format`, `content`, `compression_level`, `encrypted`, `table_rules`, `warnings`,
  `dump`, `bundle_version`, `manifest_version`, `plaintext_sha256` (`sql.gz`). `BackupArtifact` gains `getFormat()`,
  `getContent()`, `getTableRules()`, `getWarnings()`, `getPlaintextSha256()`, `isEncrypted()`.

### Security (REQ-SEC-004 audit EN of the 1.28.0 branch)

- **EN-M1:** the `mysql` client importer (`db-import`, `sql_file` `importer: client`) disables client commands found
  in the dump (`\!`, `\T`, `source`, …): `--commands=OFF` for Oracle clients 8.0.43+ / 8.4.6+ / 9.4+ (re-audit:
  `--system-command=OFF` alone still allowed `\T` and `source`), `--sandbox` for MariaDB clients 10.5.25+ / 10.6.18+ /
  10.11.8+ / 11.0.6+ / 11.1.5+ / 11.2.4+ / 11.3.2+ / 11.4.2+ / 11.5+. Any other client is refused
  (`DumpFailedException`). CI runs tampered dumps against the real Oracle mysql 8.4 and MariaDB 11.8 clients.
- **EN-L6:** each file is opened once, the open handle's inode is compared with the inode at its resolved path (which
  must be allowed, and a hard-linked inode must not be a floor file), and the copy is made from that handle: a file
  swapped for a link to a secret during the backup is dropped.
- **EN-L7 / EN-I11:** the tar size limit uses the decompressed stream (no longer the listing's size column, which an
  owner name with spaces could shift) and the bytes actually extracted; gzip is inflated in 16 KiB input slices.
- **EN-I12:** backup warnings (skipped secret paths, external links, table rules) are logged at notice level.
- **EN-L1 / EN-L2:** collection resolves `.` / `..` and symlinks: each file's real path must stay inside the project
  and must not match the floor; hard links to floor files are skipped (inode match); `backup.include_paths` entries
  with `..` or an absolute path are refused.
- **EN-L3:** restore and verify list a tar before extracting it and refuse link, hard-link and special entries and
  unsafe names; restore applies only regular files listed in the MANIFEST.
- **EN-L4:** restore refreshes the operation lock with its progress writes.
- **EN-L5:** `SecretRedactor` also masks `"password": "…"`-style JSON keys, `IDENTIFIED BY '…'`, `*_SECRET=` /
  `*_KEY=` / `*_TOKEN=` assignments, `token: …`, `Bearer …` and `Basic …`.
- **EN-I1 / I2 / I3 / I4 / I6 / I7 / I8 / I9:** the pre-pack re-check walks the staged tree; the floor adds
  `id_ecdsa*`, `id_dsa*`, `.htpasswd`, `credentials.json`, `.aws/credentials`, `.docker/config.json`, `*.ppk`,
  `*.kdbx`; `work_dir` must not be a symlink and must belong to the running user, a run directory whose process died
  is removed at the next start, `storage_dir` is created 0700; `extra_options` names are normalised (`--loose-`,
  `_`, unambiguous prefixes such as `--pass=`) and `--all-databases` / `--databases` / `--tables` / `--where` are refused; restore staging is in `work_dir`;
  deletes stay inside `storage_dir` and ids are validated; `backup.extract_limits` (size, entries, ratio) applies to
  every extraction; imports take the operation lock.

### Changed

- The default `backup.include_paths` no longer lists `.env`; an explicit secret path in `include_paths` is skipped and
  deprecated.
- A failed dump throws `DumpFailedException` (a `RuntimeException`) with a redacted message cut to 500 characters.
- Sidecars (`*.meta.json`) are written `0600`.
- PHPUnit runs with `memory_limit=512M`.

## [1.27.0] - 2026-11-02

Security release: the setup wizard is default-deny (token required, no side effects on GET), mysqldump gets an option terminator and DSN validation, backup staging is owner-only. See [UPGRADING](https://github.com/nowo-tech/SiteBackupBundle/blob/v1.27.0/docs/UPGRADING.md#to-1270).

Security hardening of the setup wizard and the dump command (REQ-SEC-004). The setup wizard
is now default-deny, so a host that ran the web wizard without `setup.setup_token` must issue a one-time token (or set
the token). See [UPGRADING](https://github.com/nowo-tech/SiteBackupBundle/blob/v1.27.0/docs/UPGRADING.md#to-1270) and [SECURITY](SECURITY.md#setup-access-default-deny-127).

### Security

- **Setup wizard is default-deny.** It used to be reachable without a token in the default configuration
  (`setup_token: null`) and a page view could start a run. Now:
  - `/_setup` and `/_setup/api/advance` exist only while
    `SetupNeedEvaluator::isSetupRequired()` reports a real need (302 `/` / 404 otherwise). The closed state no longer
    depends on the progress file: `setup.done` closes the wizard even when progress is idle or lost.
  - A GET never starts or advances a run: the first step runs on a CSRF-protected POST (Continue).
  - Every request needs `setup.setup_token` or a **one-time token** issued on the server by the new
    `nowo:site-backup:setup-token` command (`--open` also writes `setup.required` and restarts a finished run, `--revoke`
    deletes the token). The
    token is stored as a SHA-256 hash (`setup.access_token_file`, default `var/site-backup/setup.token`, `0600`) and
    removed when the wizard completes. Without a static token the 403 gate tells the operator to run the command
    (new key `setup.token.lead_console`, 7 locales).
  - `api/advance` always needs a token (its CSRF defence).
  - New `setup.allow_without_token` (default `false`, DEV/DEMO only): literal boolean, refused unless `kernel.debug` is
    true; reopens the page (never the API) without a token. The demo opts in.
- **`mysqldump` argv hardening.** `MysqlDumpCommandFactory` adds a `--` end-of-options marker before
  the database name and rejects a database, user, host or `unix_socket` that starts with `-` or contains control
  characters (`InvalidArgumentException`); the setup `database_url` step refuses the same values before writing
  `.env.local` (new `Database\DatabaseUrlSafety`).
- **Private staging directories.** Backup, verify and restore staging trees are created `0700` under an
  unpredictable name (`<prefix>-<id>-<32 hex>`, atomic `mkdir`, new `Backup\PrivateTempDirectory`); the DB dump and
  `MANIFEST.json` are written `0600`.

### Changed

- `GET /_setup` on an idle run renders the wizard with a Continue button instead of running the first automatic steps.
- `SetupWizardController` takes two optional constructor arguments (`$accessTokens`, `$allowWithoutToken`).
- `mysqldump` argv ends with `-- <database>`.

## [1.26.0] - 2026-10-30

The panel and setup wizard forms are FormKit Types (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-1260).

### Changed

- **FormKit is a required dependency** (`nowo-tech/form-kit-bundle` `^2.14`). Every panel / setup form type extends
  `AbstractSiteBackupFormType`, which carries `#[FormKitConfig('site_backup')]` + `FormOptionsTrait` and adds its fields
  through FormKit's option merger.
- **FormKit profile `nowo_form_kit.profiles.site_backup`** (REQ-UI-003 "Form theme" row), prepended by the extension when
  FormKitBundle is registered and the host does not define it: translation domain `NowoSiteBackupBundle`, no classes,
  no convention label / placeholder / help keys — the forms render exactly as in 1.25 until a host themes the profile.
  When the host has no `nowo_form_kit` config at all, FormKit's implicit `default` profile stays the default one.
  Without FormKitBundle in the kernel the forms keep their explicit options (no merger, same markup).

## [1.25.1] - 2026-10-30

Bundle routes are opt-in again on Symfony >= 7.3 (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1251).

### Security

- **Panel routes registered without being imported (REQ-SF-004 rule 7).** On Symfony >= 7.3 every autoconfigured `#[Route]` controller gets the `routing.controller` tag (`#[AsController]` also autoconfigured the controllers), so a host whose `config/routes.yaml` holds the default `controllers: { resource: routing.controllers }` loaded the backup panel routes **without `panel.path_prefix`** — the panel on `/` (over the host home page), `/create`, `/restore/{id}`, `/delete/{id}`, `/progress.json`, `/history`, outside any firewall or `access_control` rule written for the panel path — even when the host never imported `@NowoSiteBackupBundle/Resources/config/routes.yaml`. The controllers are no longer autoconfigured and no longer carry `#[AsController]` (explicit `controller.service_arguments` tag); the panel and setup routes come only from `PanelRouteLoader` / `SetupRouteLoader`. Regression test with a kernel that only loads `routing.controllers`.

## [1.25.0] - 2026-10-29

SiteBackup: process deadlines that kill the whole tree, PSR-3 logging with secret redaction, injected clock, strict setup profile config See [UPGRADING](UPGRADING.md#to-1250).

### Added

- **Logging (REQ-OBS-001)**: backup create / delete / restore and setup runs log start, success and failure through
  `Psr\Log\LoggerInterface` on the Monolog channel `site_backup` (structured context: action, backup id, profile,
  step). Actors, labels, credentials and the dump command are never logged; error messages are redacted
  (`SecretRedactor`). Panel login attempts are logged (success info, failure notice, throttled warning) without the
  client IP. A misconfigured setup tab `checker:` id is logged as a warning instead of being ignored silently.
- **Configuration docs**: `docs/CONFIGURATION.md` gains a table of contents and the *Profiles (REQ-CFG-001)* and
  *Timeouts (REQ-RUNTIME-001)* sections.

### Changed

- **Subprocess deadlines (REQ-RUNTIME-001)**: `tar`, the database dump, `nowo:site-backup:db-dump` and console setup
  steps run under `process_timeout` as both hard and idle timeout. For `tar`, the dump shell and console steps
  (`ProcessDeadline`) the whole process tree is killed on expiry (descendants first, so `mysqldump` under the dump
  shell does not survive as an orphan). A timed-out dump
  reports `Database dump timed out after N seconds (process_timeout).` without repeating the command line; `db-dump`
  prints a timeout message and exits 1. A failed or timed-out `tar` no longer leaves a partial archive in
  `storage_dir`.
- **Demo**: both FrankenPHP Caddyfiles set the timeout hierarchy (`process_timeout` 300 s < PHP
  `max_execution_time` 360 s < Caddy `write` 400 s; `max_wait_time` 30 s), documented in `docs/DEMO-FRANKENPHP.md`.
- **Clock (REQ-DI-001)**: time-dependent services (`BackupArchiver`, `SiteBackupManager`, `RestoreOrchestrator`,
  `SetupOrchestrator`, `SetupDbDoneGuard`, `SetupMarkerManager`, `WorkerRestartSignal`, DBAL setup storages) take an optional `Psr\Clock\ClockInterface` (framework `clock` service; default `NativeClock`).
  `symfony/clock` and `psr/log` are now explicit dependencies.
- **Attribute wiring (REQ-SF-003)**: built-in setup-need detectors use `#[AsSetupNeedDetector(priority: …)]`, the
  time-limit listener `#[AsEventListener]`, the controllers `#[AsController]`, and the event subscribers are tagged by
  autoconfigure — no manual `addTag()` left except the two request listeners whose priority comes from configuration.

### Fixed

- **`setup.default_profile` is validated (REQ-CFG-001)**: a name that is not a key of `setup.profiles` now fails the
  container compile with the path and the defined profiles, instead of a runtime "Unknown setup profile" error in the
  wizard.

### Documentation

- Spec Kit baseline refreshed (`specs/001-baseline/`): inventory **164/164** production files (adds
  `CsrfProtectionPass`, `SetupTokenGate`, `ProcessDeadline`, `SecretRedactor`), domain-grouped functional
  requirements, last audited 2026-10-29.

## [1.24.1] - 2026-10-28

Dependency update

### Changed

- **Dependencies updated** within their constraints: NowoUiKitBundle 2.17.1 in the development lock, PHPStan 2.3.3, Rector 2.8.1. No change in the bundle's behaviour or configuration.

## [1.24.0] - 2026-10-28

Tab icons (NowoUiKitBundle 2.17)

### Added

- **Tab icons**: the panel tabs (Backups, History) carry an icon (UiKit 2.17 `icon`, Tabler names), so they follow the
  host's tab style (`data-tab-style` on `<html>`: text, icon and text, or icon only). Without that attribute the tabs
  look as before. Icons need NowoUiKitBundle **2.17** or later; older 2.x versions ignore them.

## [1.23.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-1230).

### Security

- CSRF fails closed also when only form CSRF is disabled: new `CsrfProtectionPass` makes the container refuse to compile while the panel (`web_ui.enabled`) or the setup wizard (`setup.enabled`) is registered and `security.csrf.token_manager` or `form.type_extension.csrf` is missing, or `framework.form.csrf_protection` is false. The controllers already denied mutations without a token manager, but with `framework.form.csrf_protection: false` the token manager exists while the panel / wizard forms skipped token validation, so backup / restore / delete and setup steps accepted tokenless cross-site POSTs. See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#threat-model).

### Added

- Developer tooling: `make check-no-raw-html-form` fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Demo: the force-setup flag buttons (home page and guest layout gate escape) render through a Symfony Form Type (`CsrfOnlyType`, CSRF token id `demo_force_setup`) with `form_start` / child loop / `form_end`, and the POST handlers validate with `handleRequest` + `isValid()` instead of a hand-rolled `_csrf_token` input (REQ-TWIG-005).

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain with `ensure-up` first (then the Cursor co-author, open-PR and Twig `extra` gates, composer-sync, cs-fix, cs-check, rector-dry, phpstan, igor, translations, coverage-check and the demo release-check).
- Developer tooling: `composer test-coverage` runs PHPUnit with `memory_limit=1G` so the HTML coverage report no longer runs out of memory at the 128M default (`make test-coverage` / `coverage-check` read the % again).

## [1.22.2] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-1222).

### Changed

- Setup wizard partials (`setup/_admin_form`, `_bootstrap_form`, `_database_form`, `_continue_form`, `_sample_form`) render the form body through the child loop (`{% for child in form %}` + `not child.rendered` + `form_row(child)`) instead of `form_row(form.<field>)` + `form_rest`; `sql_import_path` and `database_url` keep their custom options and render first (REQ-TWIG-003 rule 3).

## [1.22.1] - 2026-10-21

Setup-token gate WebP source is opt-in (webp: true or a path), so PNG-only hosts get no broken image. See [UPGRADING](UPGRADING.md#to-1221).

### Fixed

- `setup.token_gate.illustration.webp` is opt-in: `null` (default) emits no WebP `<source>` (1.22.0 derived `<src>.webp` without knowing it exists, so PNG/JPEG-only hosts showed a broken image); `true` derives the sibling, a string is the WebP path.

## [1.22.0] - 2026-10-21

Setup-token gate illustration and configurable how-to steps (hosts drop their copied token page), dependency refresh, php-cs-fixer ParallelConfig(2). See [UPGRADING](UPGRADING.md#to-1220).

### Added

- Setup-token gate presentation options (`setup.token_gate`, 1.22): an optional **illustration** above the auth panel
  (`illustration`: asset path shorthand or `{ src, webp, width, height, alt }`, rendered as `<picture>` with a WebP
  source and a PNG/JPEG fallback `<img>` with intrinsic width/height, sized by a CSP-nonce `<style>`), configurable
  **how-to steps** of the `<details>` disclosure (`howto_steps`: translation keys or translated texts, `[]` hides it) and
  their `translation_domain`. New `View\SetupTokenGate` (immutable, worker safe) passed to `setup/token.html.twig` as
  `tokenGate`; new translation key `setup.token.illustration_alt` (7 locales). Hosts that copied the token page only to
  add art and copy can delete it (see UPGRADING).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).

### Documentation

- Documentation: wave-1 REQ compliance — canonical issue templates, CODEOWNERS header, README documentation list order (PSR/Templates under Additional documentation), CONTRIBUTING Cursor Agent queue settings (REQ-IDE-006), DEMO-FRANKENPHP dev/production start, timeout hierarchy and troubleshooting, commented demo `.env.example`, Spec Kit manual (layers, incremental features), categorized Spec Kit inventory (160/160), tables of contents on long docs.

## [1.21.3] - 2026-10-19

Docs: per-page overrides extend @!NowoSiteBackup (the documented namespace did not resolve). See [UPGRADING](UPGRADING.md#to-1213).

### Fixed

- **Docs:** per-page overrides (`web_ui.templates.<page>`) extend the bundle original with `{% extends '@!NowoSiteBackup/…' %}`. USAGE, TEMPLATES and the `web_ui.templates` config info showed `@!NowoSiteBackupBundle/…`, which is not a registered Twig namespace (Symfony names a bundle's `!` namespace without the `Bundle` suffix; the `@NowoSiteBackupBundle` alias added by the bundle has no `!` variant), so a copied override failed with "There are no registered paths for namespace". A kernel test now resolves the documented namespace.

## [1.21.2] - 2026-10-18

Setup locales accept %parameters% and env vars; int env placeholders for the cold-start MySQL port are kept. See [UPGRADING](UPGRADING.md#to-1212).

### Fixed

- `setup.locale.enabled` given as a container parameter or env var (`'%kernel.enabled_locales%'`, `'%app.locales%'`,
  `'%env(json:APP_LOCALES)%'`) no longer breaks the container build ("Expected array, but got string"): `prepend()`
  processed the whole raw host config, where the parameter is still a string (and `kernel.enabled_locales` is not
  defined yet), and the node only accepted a literal list. `prepend()` now processes only `css_framework` and
  `panel.extra_classes`, the node accepts a list or a parameter / env placeholder, and an env var is read at runtime
  by the setup routes, gates, exclusion matcher and time-limit subscriber (the derived `/{locale}{prefix}` paths are
  built by factories). See [CONFIGURATION](CONFIGURATION.md#setup-locales-from-a-parameter-or-env-var).
- `setup.cold_start.mysql_port` given as an env var (`'%env(int:DATABASE_PORT)%'`) was silently replaced by `3306`;
  the placeholder is now kept and resolved at runtime.

## [1.21.1] - 2026-10-18

Requires UiKit ^2.2 (wizard on the guest layout, progress partial, scripts partial); setup placeholders translated once. See [UPGRADING](UPGRADING.md#to-1211).

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.2** (default-theme polish: table header hierarchy, striped rows, subtle
  badges, solid destructive confirm button with a close button). See [UPGRADING](UPGRADING.md#unreleased).
- Setup wizard and "done" page: `WizardPage` uses UiKit 2.2 `guest: true` (layout `nowo_ui_kit.layouts.guest`)
  instead of resolving the guest layout in the bundle; `web_ui.guest_layout_template` is still passed as the layout
  override. `SiteBackupView::guestLayout()` is deprecated (kept for host-built wizard view models).
- Setup wizard progress bar renders through the UiKit `progress` partial (slots `progress` / `progress_meter`,
  `data-testid="nowo-sbb-setup-progress"`) instead of the bundle's own `<progress class="nowo-sbb-progress">`; the
  step message is the progress label.
- `_messages.html.twig` passes the last POST result to the UiKit `flashes` partial through `flash_messages` (the
  2.0 `flashes` input is deprecated in UiKit 2.2).
- Demo: the host layout prints the UiKit runtimes through the UiKit `scripts` partial (the hand-kept list lacked
  `modal`, `tabs` and `theme`).
- README / docs screenshots re-captured on UiKit 2.2 (`docs/images/demo/*.webp`, REQ-DEMO-013).

### Fixed

- **Missing / double translations (profiler):** the example placeholders of the setup forms (`DatabaseUrlType`,
  `_database_form`, `_bootstrap_form`) were literals looked up in the bundle domain by the form theme; they are now
  catalogue keys `setup.database.url_placeholder` / `setup.bootstrap.sql_placeholder` (7 locales). The wizard
  translated the controller error (already translated), literal step output ("Migrations applied.") and exception
  messages again: only key-shaped progress messages / errors are looked up now, the rest is printed as given. A
  contract test renders every page with a `DataCollectorTranslator` and asserts no missing message.

## [1.21.0] - 2026-10-18

Panel, sign-in, setup wizard and confirm pages on the UiKit 2.1 standard page contract (REQ-UI-003): list, form, confirm, auth and wizard scaffolds, type-to-confirm restore, no-JS fallbacks; deprecated `panel.*` / `setup.*` / `templates.*` keys mapped to `web_ui.*`. See [UPGRADING](UPGRADING.md#to-1210).

### Added

- **UiKit 2.1 page contract (REQ-UI-003).** The panel and setup pages extend UiKit scaffolds and are themed once in
  `nowo_ui_kit` for every Nowo bundle: archives and history → `page/list`, new create-backup page → `page/form`
  (`GET {path_prefix}/create`), new no-JS restore / delete confirmations → `page/confirm`
  (`GET {path_prefix}/restore/{id}`, `GET {path_prefix}/delete/{id}`), panel sign-in and setup token → `page/auth`
  (guest layout), setup wizard and done → `page/wizard` (guest layout, final "Done" step). View models are built by
  `View\PanelPages` / `View\SetupPages` and rendered by `View\SiteBackupView` (no database service: the wizard renders
  before the app has a schema or users). See [TEMPLATES.md](TEMPLATES.md).
- **Type-to-confirm restore.** Restoring an archive asks to type its id in the UiKit confirm dialog (and on the no-JS
  confirm page); delete uses the UiKit confirm dialog. Both keep their CSRF forms.
- Canonical keys `web_ui.enabled`, `web_ui.path_prefix`, `web_ui.layout_template` (admin pages, null = UiKit),
  `web_ui.guest_layout_template` (sign-in + setup, null = UiKit) and `web_ui.templates.<page>`.
- Panel area navigation (Backups | History) as `PageHeader::tabs`, published to the UiKit area-nav registry as area
  `site_backup` (`SiteBackupView` implements `AreaNavProviderInterface`).
- Contract tests on UiKit `PageContractTestCase` (English and German catalogues), controller flow tests (panel, and the
  setup wizard without any database), demo Playwright e2e + WebP screenshots (`make -C demo/symfony8 test-e2e`,
  `demo-screenshots`).

### Changed

- Requires `nowo-tech/ui-kit-bundle` `^2.1`.
- Pages render inside `nowo_ui_kit.layouts.admin` / `.guest` instead of the bundle's standalone layouts; restore status
  and POST results render through the UiKit flash slots; row actions are UiKit `Action`s (`nowo_ui_kit.row_actions_display`).
- Form types carry their labels / help / placeholders (`translation_domain: NowoSiteBackupBundle`).
- Without the deprecated root `css_framework`, the bundle no longer seeds `nowo_ui_kit.css_framework: custom` /
  `icon_set` (UiKit defaults apply).
- A failed create (CSRF / archiver error) re-renders the create form with HTTP 422.

### Deprecated

- `panel.enabled`, `panel.path_prefix`, `panel.layout_template`, `setup.layout_template`, `templates.*` (aliases of the
  `web_ui` keys; `templates.setup_admin|setup_sample|setup_database` have no effect), root `css_framework`,
  `panel.row_actions_display` (use `nowo_ui_kit`), `panel.extra_classes` (bridged into
  `nowo_ui_kit.theme.bundles.NowoSiteBackupBundle.classes`), the Twig globals `nowo_site_backup_*_layout_template`,
  `nowo_site_backup_css_framework`, `nowo_site_backup_row_actions_display`, `nowo_site_backup_panel_classes`, and the
  1.x layout bridges `panel/_legacy_layout.html.twig` / `setup/_legacy_layout.html.twig`. Removed in 2.0.

### Removed

- Templates `panel/layout.html.twig`, `panel/_flashes.html.twig`, `setup/layout.html.twig`, `setup/_styles.html.twig`,
  `setup/admin.html.twig`, `setup/database.html.twig`, `setup/sample_data.html.twig`, the `data-nowo-confirm` listener
  and the inline `<style>` blocks of the panel / setup pages (the restore loading page keeps its own).

### Fixed

- The setup and restore gates no longer redirect the Symfony toolbar / profiler (`/_wdt/`, `/_profiler/`): the toolbar
  of every gated page embedded the wizard page.
- Demo: the panel password hash (`password`) was invalid.

## [1.20.0] - 2026-10-15

### Added

- **Production guard: hash shape, extra secrets, DSN credentials.** `security_guard.check_password_hash_shape` (default `true`) refuses a panel `password_hash` that `password_get_info()` does not recognise — typically a bcrypt hash cut by Docker Compose `$` interpolation, which used to lock the panel silently. `security_guard.extra_secrets` checks more app secrets (min length, documented placeholders, optional `required`); `security_guard.dsn_credentials` requires a password (userinfo, `?auth=` or `password_fallback`) and refuses documented default credentials (`guest:guest`, …) in Redis / AMQP DSNs. Apps can drop their host copies of these checks (Nowo REQ-SETUP-004 / REQ-ENV-007).

## [1.19.2] - 2026-10-13

### Fixed

- `SetupRouteLoader` threw `SiteBackup setup routes already loaded.` when the router was rebuilt in the same process (FrankenPHP worker, `bin/console` / dev server `--watch` reloading the route cache), because the loader service remembered its first `load()` call. The loader is now stateless: every call returns a fresh, identical collection of setup routes.
- `RestoreOrchestrator` kept its heartbeat throttle timestamp across restores in a long-lived worker. It now implements `ResetInterface` and starts each restore with a fresh throttle. `DoctrineDbalSetupProgressStorage::reset()` also resets its step journal's schema memo.

### Changed

- `PanelRouteLoader`: behaviour-neutral Rector rewrite (`__DIR__ . '/../…'` path) and an Igor note on the locally imported collection.

### Dependencies

- Dev lock refreshed by `composer-sync` (`nowo-tech/ui-kit-bundle` 1.16.0, `nowo-tech/phpstan-frankenphp` 1.3.0).

## [1.19.1] - 2026-10-13

### Fixed

- A very large restore still running could be judged abandoned by `restore.stale_after_seconds` (default 2 × `process_timeout`), because archive hashing, tar extraction and file apply wrote no progress: a second restore could then start on top of it. These steps now write progress heartbeats (refresh `updated_at`) at most every 5 s, or every third of `stale_after_seconds` when that is smaller, so staleness is measured from the last heartbeat. `BackupArchiver::verifyIntegrity()` / `extractTo()` accept an optional heartbeat callable (archive hashed in 8 MiB chunks, tar polled while running); `RestoreOrchestrator` accepts an optional `clock` closure.

## [1.19.0] - 2026-10-13

### Changed

- **Requirements:** Symfony floor raised to `^7.4 || ^8.0` for every `symfony/*` dependency (`symfony/rate-limiter` already needed `^7.3`, and the CI "7.0" cell actually installed the latest 7.x). CI matrix drops the `7.0` cell; README / INSTALLATION aligned.
- docs/CONFIGURATION.md documents every configuration key (including setup profile step / tab keys).

### Added

- `restore.stale_after_seconds` (default `null` = 2 × `process_timeout`, `0` = never): an active restore whose progress was not updated for that long is treated as abandoned. `RestoreOrchestrator::isRestoreStale()`.
- `RestoreOrchestrator::MESSAGE_*` translation ids (`restore.message.*`) translated in en/es/it/fr/pt/de/nl.

### Fixed

- Backups no longer nest previous archives or runtime state when `backup.storage_dir`, `restore.progress_file`, `storage.history_file` or `setup.progress_file` point inside the project but outside `var/site-backup/`: those paths (resolved relative to the project dir) are excluded from archives, and restores never overwrite them (`BackupArchiver` `$runtimeStatePaths`, `RuntimeStatePaths`).
- A worker that died mid-restore left `active: true` and the site answered 503 forever; stale progress is now ignored by the site gate, `progress.json` reports `active: false`, and a new restore may start (logging the abandoned one).
- `panel.enabled: false` no longer leaves the panel routes registered against the removed controller (HTTP 500): `routes.yaml` imports the panel through `PanelRouteLoader` (type `nowo_site_backup_panel`), which registers no route when the panel is disabled (404).
- Restore loading page reloads as soon as the restore is no longer active (completed, failed, cleared, abandoned), not only on `completed`.
- Restore progress messages were English literals, so `|trans` did nothing and `default_message` was never shown: the orchestrator now writes `restore.message.*` translation ids; the loading page renders `default_message` as its lead text and the translated step below; panel `progress.json` returns the translated message.
- Locale codes are `preg_quote`d when building the `/{locale}/_setup` exclusion regex and the setup route `_locale` requirement.

## [1.18.1] - 2026-10-13

### Security

- **`POST /_setup/api/advance` closed after setup**: it answers 404 once setup is completed (same gate as the wizard page), so `{"profile":"minimal"}` can no longer create an extra admin and `{"profile":"full_database"}` can no longer re-import `last-restore-dump.sql`. The payload `profile` is only honoured before a run started; afterwards the stored profile is pinned. Responses no longer include `answers`.
- **`GET /_setup/api/progress`** requires the setup token (when configured) and no longer returns `answers` (admin email, DATABASE_URL, SQL path).
- **DATABASE_URL step**: the value is always written single-quoted to `.env.local` (Symfony Dotenv no longer expands `$VAR` / `$(cmd)` or mangles passwords containing `$` / `\`), the existing line is replaced literally (no regex backreferences), and URLs containing `'`, line breaks or control characters are rejected (percent-encode them).
- **`sql_import_path`** (bootstrap mode / `sql_file` step) is confined to existing `.sql` files under `var/site-backup/` (real path; absolute paths elsewhere, `../` and symlink escapes are rejected). SQL import errors report the file name and SQLSTATE only, never the dump contents.
- **Restore `progress.json`**: callers that are not authorised for the panel get `active` / `phase` / `percent` / `message` only (no log, error or backup id).
- Backup archives are `chmod 0600`; `var/site-backup/last-restore-dump.sql` is `0600` and deleted after the setup `sql_file` step imports it.
- Docs: `setup.setup_token` is mandatory on internet-facing hosts (SECURITY.md, CONFIGURATION.md).

## [1.18.0] - 2026-10-13

### Added

- **Translated panel, setup and restore screens** (spec 006): every user-facing string goes through the `NowoSiteBackupBundle` domain in all 7 locales (`en`, `es`, `fr`, `it`, `pt`, `de`, `nl`) — panel login (heading, notices, label, button, "Invalid password."), dashboard (sections, columns, actions, confirms, logout, phase label), history, flash / error messages (`panel.flash.*`, `panel.error.*`, 403 "Access denied."), setup page titles (`setup.page_title.*`), admin form labels, setup controller errors (`setup.error.*`), token gate and restore phase (`restore.phase.*`). 69 new keys; `<html lang>` follows the request locale. `SetupWizardController` takes an optional `$translator` (last argument, wired to `translator`); both controllers fall back to English without one.
- **Setup token gate with a paste form**: `setup/token.html.twig` renders `tokenForm` (`Form\Setup\SetupTokenType`, GET, field `token`, no CSRF) plus a collapsible how-to (`setup.setup_token`, `?token=…`, `X-Setup-Token`). Templates without `tokenForm` keep the text-only page.
- **`--nowo-ui-*` token theming**: panel layout CSS, the new `setup/_styles.html.twig` partial and the setup layout read UiKit tokens with inline fallbacks; the setup dark palette is the overridable block `nowo_site_backup_theme_tokens`. Legacy `--nowo-sbb-*` variables are aliases of the tokens.
- **Chrome blocks** so hosts stop forking pages: panel layout (`nowo_site_backup_html_lang`, `_html_class`, `_body_class`, `_panel_styles`, `_panel_brand`, `_panel_nav`), login (`nowo_site_backup_login_eyebrow`, `_heading`, `_intro`, `_notice`, `_form`, `_after`), index (`nowo_site_backup_create_section`, `_restore_section`, `_archives_section`, `_row_actions`, `_logout`, `_confirm_script`), history (`nowo_site_backup_history_heading`, `_history_row`), setup layout (`nowo_site_backup_theme_tokens`, `nowo_site_backup_logo`, lang / class blocks), token (`nowo_site_backup_token_art`, `_title`, `_lead`, `_form`, `_howto`).
- **`panel.row_actions_display`** (`text` default \| `icon` \| `icon_text` \| `kit`) and **`panel.extra_classes`** (`card`, `table`, `button`, `input`); Twig globals `nowo_site_backup_row_actions_display` / `nowo_site_backup_panel_classes`.
- Reusable partials for host shells: `panel/_flashes.html.twig` (panel `error` / `success`) and `setup/_styles.html.twig`.
- Tests: functional render tests with real Twig + Form + Translation bridges (`tests/Unit/Twig/TranslatedTemplatesRenderTest.php`), catalogue test (deep key parity + every literal key used by templates / controllers exists).

### Changed

- Panel templates use the UiKit macros for `css_framework` (`ui.btn`, `ui.input`, `ui.table_wrap`, `ui.table`, `ui.flash`, `ui.empty`, `ui.row_actions`, `ui.action`); legacy classes (`nowo-ui-btn`, `card`, `inline`, `danger`, `nowo-ui-flash--err`, …) are kept. Tables are wrapped in `nowo-ui-table-wrap`; "Clear status" / "Logout" use the secondary button style.
- `.scripts/validate-translations.php` (`make validate-translations`) checks deep key parity, not only top-level sections.
- Translations: replaced English copies in `fr`, `it`, `pt`, `de`, `nl` (bootstrap, reasons, token) and outdated `db_connection_hint` / `db_connection_form` texts.
- **`nowo-tech/ui-kit-bundle` constraint `^1.6`** (row-action kinds / icons used by the panel).

### Fixed

- **Panel row / logout / clear forms posted to the wrong URL**: `PanelActionType` used Symfony's reserved `action` option for the hidden field value, so `<form action="verify">` (also `restore`, `delete`, `logout`, `clear_restore`) was rendered and the browser posted outside the panel. The value is now the `panel_action` option; forms post back to the panel URL.

## [1.17.0] - 2026-10-12

### Added

- **`security.login_throttle`** (on by default: `enabled: true`, `limit: 5`, `interval: '15 minutes'`, `policy: fixed_window`, `limiter: null`, `cache_pool: null`): per-client-IP rate limit of panel password attempts. `Security\ThrottledSiteBackupAccessGate` (service `nowo_site_backup.access_gate`, target of the `SiteBackupAccessGateInterface` alias) wraps the default password gate or the custom `security.access_gate`; it only throttles while `isProtectionEnabled()` is true, resets the limiter on success and throws `TooManyRequestsHttpException` with `Retry-After` when exhausted. The built-in limiter is `nowo_site_backup.login_limiter` (`RateLimiterFactory`, `CacheStorage` on `cache_pool` ?? `cache.rate_limiter` ?? `cache.app`, optional `lock.factory`); `limiter` plugs a host `RateLimiterFactoryInterface` service instead. `DependencyInjection\Compiler\LoginThrottleStoragePass` fails at compile time with an actionable message when no pool exists.
- Panel login renders the throttle as the login page with **HTTP 429**, `Retry-After` and the translated `panel.login.too_many_attempts` message (all 7 locales; template variable `throttled`). Also applies to host decorators that throw `TooManyRequestsHttpException`.
- **Session fixation protection:** after a successful panel login the session id is rotated (`$session->migrate(true)`), also when `login_throttle.enabled: false` or with a custom gate.

### Changed

- **`symfony/rate-limiter` (`^7.3 || ^8.0`) is now a hard requirement** (needed by the default-on throttle; `RateLimiterFactoryInterface` exists since 7.3).
- The `SiteBackupAccessGateInterface` alias now points to `nowo_site_backup.access_gate` instead of `PasswordSiteBackupAccessGate` / the custom gate id (both remain registered and are injected as the inner gate).
- `SiteBackupPanelController` takes an optional `$translator` (last argument).
- Igor: annotated four pre-existing false positives (persistent I/O / per-request locale) with `@igor-ignore`.
- Tooling: Rector skips `DependencyInjection/Configuration.php` (its scope analysis never finished, so `rector-dry` timed out); two Rector fixes applied (`Command::INVALID`, redundant string cast). Lock refresh: UiKitBundle 1.12.0. Demo `config/reference.php` regenerated.

## [1.16.0] - 2026-10-09

### Added

- **`setup.worker_memo`** (`schema_probe_ttl`, default `60`; `progress_ddl_ttl`, default `3600`): per-worker TTL memos (`Setup\Memo\WorkerTtlMemo`, deliberately not `ResetInterface`) so FrankenPHP workers stop probing `information_schema` and running `CREATE TABLE IF NOT EXISTS` on every request. `MemoizedSchemaExistenceChecker` wraps the cold-start `MysqlSchemaExistenceChecker`; `DoctrineConnectDetector` / `DoctrineSchemaEmptyDetector` reuse a healthy answer; `DoctrineDbalSetupProgressStorage` / `DoctrineDbalSetupStepJournal` remember the DDL across `kernel.reset`. Only positive answers are cached; a dropped table is still re-created on the first failing query. `0` restores the previous behaviour.
- **`bump_time_limit`** (default `false`) + **`bump_time_limit_seconds`** (default `null` = `process_timeout`; `0` = unlimited): `LongRequestTimeLimitSubscriber` calls `set_time_limit()` on main requests under the setup prefix (incl. `/{locale}` variants) and the panel prefix, so migrate / dump / restore subprocesses are not killed mid-pipe by a short prod `max_execution_time`.
- **`nowo:site-backup:db-dump`**: built-in `backup.database_dump_command` — `mysqldump` from `DATABASE_URL` (or `--url`), password via `MYSQL_PWD` (never argv), `--single-transaction --no-tablespaces`, no `--routines` (restore replays through PDO), optional `--skip-ssl-verify-server-cert` (MariaDB client), `--binary`, repeatable `-o` options. Pure argv builder: `Database\MysqlDumpCommandFactory`.
- **`security_guard`** (opt-in, default `enabled: false`): `ProductionSecretsGuardSubscriber` fails closed outside `local_environments` (default `dev`, `test`) on HTTP requests and console commands when the setup token, panel password hash or `APP_SECRET` are empty, listed placeholders or (APP_SECRET) shorter than 16 characters. Skips `cache:clear`, `cache:warmup`, `assets:install`, `nowo:site-backup:hash-password`.

### Changed

- **CSP:** inline script and style tags in the restore page, setup and panel layouts, wizard and panel index carry `nonce` from the request attribute `csp_nonce` when present (nowo-tech kit convention). Panel restore / delete confirms use `data-nowo-confirm` + one delegated nonce'd listener instead of `onsubmit="return confirm(…)"`. Inline `style=""` attributes were replaced by classes (wizard progress bar is now a `<progress class="bar">` element).
- Docs: `setup.reopen_when_detector_requires` documents its cost (one detector pass per request).

## [1.15.1] - 2026-10-09

### Fixed

- Tests: `DetectorsTest` no longer uses an anonymous `readonly` class (PHP 8.3+), so the suite parses on PHP 8.2.

### Changed

- Dependencies (lock refresh): UiKitBundle 1.9.1; dev PHPStan 2.3.1 (+ phpunit/symfony extensions 2.1), PHPUnit 10.5.66, Rector 2.7.0, `nowo-tech/phpstan-frankenphp` 1.2.3.
- Demo (Symfony 8): regenerated `config/reference.php`.

## [1.15.0] - 2026-10-07

### Added

- **`setup.reopen_when_detector_requires`** (default `false`, BC): when `true`, `short_circuit_when_done` no longer hides detectors. If `setup.done` or the durable store says complete but a tagged `SetupNeedDetectorInterface` still reports setup required (e.g. platform catalogs wiped), `SetupNeedEvaluator` clears `setup.done`, calls `DurableSetupDoneStoreInterface::clearDone()` and resets wizard progress so the wizard can run again. Costs one detector pass per request.
- **`SetupWizardReopener`** (public service): kit-side helper (`reopen()`, `resetProgress()`) so hosts no longer copy the clear-done / reset-progress logic into their own subscribers.
- **`DurableSetupDoneStoreInterface::clearDone(): void`** (SPI). `NullDurableSetupDoneStore` implements it as a no-op.

### Changed

- **BC note (SPI):** custom `DurableSetupDoneStoreInterface` implementations must add `clearDone()` (no-op is acceptable if the host does not want the bundle to clear its durable flag).

### Fixed

- Tests: avoid PHP 8.4-only `new Foo()->method()` chaining in `UnlocalizedDefaultLocaleSubscriberTest` so PHP 8.2/8.3 CI jobs parse the suite.

## [1.14.3] - 2026-10-01

### Fixed

- **`setup.locale.unlocalized: serve`:** `UnlocalizedDefaultLocaleSubscriber` forces `setup.locale.default` at request time on SiteBackup `*_unlocalized` routes (after Symfony `LocaleListener`), so bare `/setup` stays correct when the compiled route `_locale` default was warmed with a different value (Docker image build vs runtime env). Registered only when setup is enabled, `in_path: both`, and `unlocalized: serve`.

[1.14.3]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.14.3

## [1.14.2] - 2026-09-28

### Security

- Empty `security.access_roles` is fail-closed (deny) unless `allow_unauthenticated` or a custom `access_checker` is set.

## [1.14.1] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.14.2]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.14.2
[1.14.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.14.1

## [1.14.0] - 2026-09-25

### Added

- **Worker restart signal (FrankenPHP worker mode):** `Worker\WorkerRestartSignal` writes `var/site-backup/worker-restart.required` and dispatches `Event\WorkerRestartRequiredEvent` after a restore applied files, after `cache_clear`, and after `database_url` wrote `.env.local`. Command `nowo:site-backup:worker-restart` shows the signal (exit code `3` when a restart is required) and clears it with `--clear`.
- `ConditionalAnswerStep::getInner()`.
- Audit document [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) (scenario B: kernel not reset between requests).

### Fixed

- **Worker mode (scenario B):** `DoctrineDbalSetupProgressStorage` and `DoctrineDbalSetupStepJournal` no longer trust a stale "schema ensured" flag for the life of the worker. When a query fails after the table was created earlier in the process (database dropped / recreated), the table is re-created and the query retried once. Both services implement `ResetInterface` (`kernel.reset`); correctness does not depend on the resetter.
- Cold-start PDO fallback sets `PDO::ATTR_TIMEOUT` (5 s).
- PHPStan findings cleared (level 8).

### Documentation

- [UPGRADING.md](UPGRADING.md) **From 1.13.8 to 1.14.0**; [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) links the worker audit.

## [1.13.8] - 2026-08-24

### Changed

- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.13.8]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.8

## [1.13.7] - 2026-08-21

### Added

- **`setup.short_circuit_when_done`** (default `true`): `SetupNeedEvaluator` skips all need detectors when the `setup.done` marker exists or `DurableSetupDoneStoreInterface::isDone()` is true. Avoids repeated Doctrine / host catalog probes on every request after setup. Set `false` only if a host detector must re-open the gate after done.

[1.13.7]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.7

## [1.13.6] - 2026-08-20

### Fixed

- **CI:** schema-checker coverage tests no longer mock `Doctrine\DBAL\Connection` (not a required package), so PHPUnit succeeds without `doctrine/dbal` in the QA image (REQ-CI-003).
- **Tests:** duck-typed DBAL/PDO probes, wizard form branches (`bootstrap` / `sample` / `database_url`), and FakeDbal `fetchOne` so Clover statements stay ≥ 99% (REQ-TEST-003).

[1.13.6]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.6

## [1.13.5] - 2026-08-20

### Changed

- **Tests:** coverage-completion cases for schema checker, setup journal, DI, and panel forms (REQ-TEST-003).
- **Tests:** extract `FakeDbalConnection` / `FakeDbalResult` / `FakeDurableSetupDoneStore` so coverage tests can autoload and subclass them.

[1.13.5]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.5

## [1.13.4] - 2026-08-20

### Security

- **Flex recipe:** ship `security_nowo_site_backup.yaml` (`access_control` for `/_site_backup` and `/_setup`) and prod hardening (`setup.enabled: false`, `allow_unauthenticated: false`). Prefer **`^1.13.4`** so Flex installs the files (landed on `main` via #15).

## [1.13.3] - 2026-08-20

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.13.2] - 2026-08-19

### Changed

- **Tests:** cover cold-start schema checker and setup redirect subscriber paths (REQ-TEST-003).

[1.13.2]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.2

## [1.13.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.13.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.1

## [1.13.0] - 2026-08-15

### Added
- **`progress_storage: cache` / `cache_doctrine`**: PSR-6 pool progress (`progress_cache_pool`, default `cache.app`) for cold-start without `var/` JSON. `cache_doctrine` prefers DBAL on load and soft-fails Doctrine writes until the schema exists; invalidates cache progress when app tables disappear after migrations.
- **`setup.cold_start.require_application_tables`** (default `true`): empty MySQL schemas (only `nowo_site_backup_*` runtime tables) stay cold-start until host migrations create application tables.

### Fixed
- **Optional `database_url` Skip**: when the step is optional, Skip stays available and the field is not `required` even if detectors report `database connection failed` (missing/empty schema). Skip button uses `formnovalidate`.

### Documentation
- [SETUP-WIZARD.md](SETUP-WIZARD.md) / [CONFIGURATION.md](CONFIGURATION.md) / [UPGRADING.md](UPGRADING.md).

## [1.12.0] - 2026-08-15

### Added
- **Durable setup done** (`003-durable-done-cold-start`): `DurableSetupDoneStoreInterface` + `NullDurableSetupDoneStore` (default alias). Optional `SetupDbDoneGuard` heals ephemeral markers/progress when the host store says complete. `SetupDbDoneRedirectSubscriber` (priority 3) closes the wizard when `setup.durable_done.enabled: true`.
- **Cold-start schema gate**: `ColdStartSchemaGateSubscriber` (priorities 35/34/20) redirects to the setup path when MySQL schema is unreachable; configurable `setup.cold_start.safe_path_prefixes` and `stop_propagation`. `MysqlSchemaExistenceChecker` probes DBAL or PDO credentials.
- `SetupRequestSubscriber` calls `stopPropagation()` after redirecting to the wizard.

### Documentation
- Spec `specs/003-durable-done-cold-start/`; [CONFIGURATION.md](CONFIGURATION.md), [SETUP-WIZARD.md](SETUP-WIZARD.md), [UPGRADING.md](UPGRADING.md).

## [1.11.0] - 2026-08-15

### Added
- **Setup step journal** (`002-setup-step-rows`): optional per-step DBAL table (`setup.progress_steps_table`, default `nowo_site_backup_setup_step`) upserted when `progress_storage` is `doctrine`/`chain` and `progress_step_rows: true` (default). Runtime DDL only — **not** Symfony Migrations — so early wizard steps still work before the host schema exists. Load merges completed step ids from the journal; `latestFinishedStep()` supports ops/status queries.

### Documentation
- [SETUP-WIZARD.md](SETUP-WIZARD.md) / [CONFIGURATION.md](CONFIGURATION.md) / [UPGRADING.md](UPGRADING.md) — cold-start progress storage and per-step journal.
- Spec `specs/002-setup-step-rows/` and baseline FR-SETUP-002 update.

[1.13.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.13.0

[1.12.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.12.0

[1.11.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.11.0

## [1.10.1] - 2026-08-07

### Documentation
- **SECURITY:** host firewall / `access_control` examples for panel (`/_site_backup`) and setup (`/_setup`).

### Fixed
- **REQ-GIT-001:** removed Cursor co-author trailers from git history (CI `git-hygiene`).

[1.10.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-08-04

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed
- **REQ-UI-001-kit:** Requires **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`). Panel/setup layouts load `asset('css/nowo-ui.css', 'nowo_ui_kit')` and import UiKit macros. Extension seeds `nowo_ui_kit` from panel/setup CSS framework settings when the host has not configured UiKit.

### Documentation
- [INSTALLATION.md](INSTALLATION.md) / [UPGRADING.md](UPGRADING.md) — UiKit, Twig Extra, and Twig-CS-Fixer notes.

[1.10.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.10.0

## [1.9.1] - 2026-08-04

### Fixed
- **CI:** `destroyHarness()` removes history/progress files instead of their `dirname` (`/tmp`), which broke GHA runners.

[1.9.1]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-08-03

### Added

- REQ-UI-002 panel access control: `security.access_roles` (default `[ROLE_ADMIN]`), optional `security.access_checker`, and `SiteBackupAccessCheckerInterface` (`ConfigurableSiteBackupAccessChecker` / `AllowAllSiteBackupAccessChecker`).
- `security.allow_unauthenticated` (default `false`): when `false` and the panel is enabled, `symfony/security-bundle` is required (boot fails with `LogicException` otherwise).

### Changed

- Ops password gate remains an **additional** layer on top of role / access-checker checks.
- Demo sets `allow_unauthenticated: true` (password gate only; never copy to production).
- Docs / recipe / suggest text updated for the dual-layer security model ([CONFIGURATION.md](CONFIGURATION.md), [USAGE.md](USAGE.md), [SECURITY.md](SECURITY.md), [INSTALLATION.md](INSTALLATION.md)).
- CI: `actions/stale` v11; lock refresh (`rector/rector` 2.6.0, `nowo-tech/phpstan-frankenphp` 1.0.3).

### Compatibility

- PHP `>=8.2`, `<8.6`; Symfony `^7.0 || ^8.0` (CI minors **7.4**, **8.0**, **8.1**).
- Panel with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.8.1] - 2026-08-01

### Documentation

- FrankenPHP demo showcases recent features: host `setup`/`panel.layout_template`, `css_framework`, `setup.locale.in_path: both`, `#[AsSetupNeedDetector]` toggle, and profile `demo_features` (custom tab + checker + `app:demo:seed-catalog`).
- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) / [demo/README.md](../demo/README.md) / [demo/symfony8/README.md](../demo/symfony8/README.md).

## [1.8.0] - 2026-08-01

### Added

- Custom **setup-need detectors** for the site gate: `#[AsSetupNeedDetector(priority: int)]` → tag `nowo.site_backup.setup_need_detector` (same attribute pattern as tab `#[AsSetupTabChecker]`).
- `SetupNeedEvaluator` collects detectors via `tagged_iterator` (built-ins + host apps). OR semantics unchanged.

### Documentation

- Clarify **gate detector** vs wizard tab **`checker:`** / `SetupTabCheckerInterface` ([SETUP-WIZARD.md](SETUP-WIZARD.md), [CONFIGURATION.md](CONFIGURATION.md), [UPGRADING.md](UPGRADING.md)).

## [1.7.0] - 2026-07-31

### Added

- Setup **`locale`** configuration (mirrors AuthKit locale-in-path pattern): `setup.locale.in_path` (`never` \| `always` \| `both`), `.default`, `.enabled`, `.unlocalized` (`serve` \| `redirect`).
- `SetupRouteLoader` (`type: nowo_site_backup_setup`) registers wizard routes with optional `/{_locale}` prefix depending on mode.
- `SetupPathPrefixResolver` resolves the effective setup path prefix for the current request (locale-aware redirects, Twig vars, progress URL).
- `SetupUnlocalizedLocaleRedirectController` redirects bare setup URLs to the canonical `/{_locale}/…` URL when `in_path: both` + `unlocalized: redirect`.
- Enums `LocaleInPathMode` and `UnlocalizedLocaleMode` under `Nowo\SiteBackupBundle\Enum\`.
- DI parameters: `nowo.site_backup.setup.locale.in_path`, `.default`, `.enabled`, `.unlocalized`.
- Localized setup routes are auto-excluded from restore/setup gate patterns.
- `SetupRequestSubscriber` is locale-aware: skips redirect for localized setup paths; redirect target uses `SetupPathPrefixResolver`.

### Changed

- Setup routes are now registered by `SetupRouteLoader` (type `nowo_site_backup_setup`) instead of `#[Route]` attributes on `SetupWizardController`. Default behaviour (`in_path: never`) is fully backward compatible.
- `SetupWizardController` uses `SetupPathPrefixResolver` for all path prefix references (Twig vars, redirects, progress URL).

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md) / [SETUP-WIZARD.md](SETUP-WIZARD.md) / [UPGRADING.md](UPGRADING.md) for locale-in-path.

## [1.6.0] - 2026-07-30

### Added

- Root config **`css_framework`** (default `custom`) with Twig global `nowo_site_backup_css_framework` (REQ-UI-001). Accepted values: `bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`.
- Demo setup/panel layouts expose `nowo-ui-css-*` / `data-css-framework` for host theming hints.

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md) / [USAGE.md](USAGE.md): `css_framework` + host layout example; Twig override freeze guidance.
- [UPGRADING.md](UPGRADING.md) section **To 1.6.0**.

## [1.5.0] - 2026-07-30

### Added

- Panel **`layout_template`** (`panel.layout_template` / `templates.panel_layout`) so hosts brand the admin panel without forking page markup (REQ-UI-001).
- Twig globals `nowo_site_backup_setup_layout_template` and `nowo_site_backup_panel_layout_template` (pages `{% extends %}` those globals).
- Stable **`nowo-ui-*`** CSS hooks on setup and panel chrome/partials for host restyling without forking templates.

### Changed

- Setup pages extend the setup layout **global** (same as panel); prefer `setup.layout_template` / `panel.layout_template` over copying layouts.
- Docs: [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [UPGRADING.md](UPGRADING.md), [SETUP-WIZARD.md](SETUP-WIZARD.md).

## [1.4.0] - 2026-07-30

### Added

- Setup **`layout_template`** (`setup.layout_template` / `templates.setup_layout`): wizard, done, and token pages extend a host layout via block `nowo_site_backup_content`. Default layout: `@NowoSiteBackupBundle/setup/layout.html.twig`.
- Setup **reasons** banner in the vendor wizard (connection failed, empty schema, markers, incomplete progress).
- `_database_form` hides Skip and warns when Doctrine connect detection reports failure.
- Translation keys `setup.reason.*` and `setup.token.*` in all bundle locales.

### Changed

- Setup Twig pages are **content-only** (no standalone DOCTYPE); chrome lives in `setup/layout.html.twig` or the app `layout_template`.
- Docs: prefer `layout_template` over forking `wizard.html.twig` / `done` / `token` ([SETUP-WIZARD.md](SETUP-WIZARD.md), [UPGRADING.md](UPGRADING.md), [USAGE.md](USAGE.md)); inventory 108/108.

## [1.3.2] - 2026-07-30

### Fixed

- Restored Symfony component constraints to `^7.0 || ^8.0` again (CI `code-style-fix` had been committing `composer require …:^7.4` pins).
- CI auto-fix job no longer commits `composer.json` / `composer.lock`; only PHP CS Fixer changes.

### Documentation

- [UPGRADING.md](UPGRADING.md) / [RELEASE.md](RELEASE.md) for 1.3.2.

## [1.3.1] - 2026-07-30

### Added

- Setup partial `setup/_continue_form.html.twig` for generic Continuar on custom tabs (included from the wizard when no `template` is set).

### Fixed

- Restored Symfony component constraints to `^7.0 || ^8.0` (had drifted to `^7.4` only for some packages after a CI sync, breaking Symfony 8 demos).

### Documentation

- Clarified REQ-TWIG-001 / REQ-UI-001: reuse bundle Twig; prefer overrides under `templates/bundles/NowoSiteBackupBundle/`; avoid app-forked `@App` templates for custom tabs ([USAGE.md](USAGE.md), [SETUP-WIZARD.md](SETUP-WIZARD.md), [UPGRADING.md](UPGRADING.md)).
- Inventory refreshed (107/107).

## [1.3.0] - 2026-07-30

### Added

- Setup step **`bootstrap_mode`**: choose **guided** (create admin) or **full database** dump import; answer `sql_import_path`; step filter `when_answer`.
- Default `fresh_install` profile: bootstrap choice → optional SQL import when `full_database` → migrations → `admin_user` (`skip_if_admin_exists`) → marker.
- Profile **`full_database`** for deep-link `?profile=full_database`.
- Starting the wizard marks `setup.required` so the site gate stays on until `setup.done` (FR-SETUP-005).
- Setup **`profiles.*.tabs`**: ordered YAML tabs with optional `checker` (`SetupTabCheckerInterface`), `template`, `runner`, and i18n `label` / `description`; legacy `steps` still work.
- `setup.advance_mode` / per-profile override: `automatic` (default) or `manual` (one auto tab per Continuar); CLI forces automatic.
- `#[AsSetupTabChecker]` autoconfigures checker services (tag `nowo.site_backup.setup_tab_checker`).
- Built-in type **`custom`** for app-owned tab bodies; translation keys `setup.tab.*` / `setup.check.*` in all bundle locales.

### Documentation

- Spec US-7 / US-8 / US-9; [SETUP-WIZARD.md](SETUP-WIZARD.md) / [UPGRADING.md](UPGRADING.md) / [CONFIGURATION.md](CONFIGURATION.md) updated; inventory 106/106.

## [1.2.0] - 2026-07-29

### Added

- Setup progress **Doctrine DBAL** storage + **`chain`** mode (`setup.progress_storage`: `filesystem` \| `doctrine` \| `chain`; load prefers DB so wiping `var/` keeps the current step).
- `IncompleteSetupProgressDetector` (`setup.detectors.incomplete_progress`, default `true`) — site gate while phase is `running` / `waiting_input` / `failed`.
- Progress fields `started_at` / `completed_at` (JSON + DB columns); `setup-status` CLI shows them.

### Fixed

- Restored Symfony component constraints to `^7.0 || ^8.0` on `main` (had drifted to `^7.4` only for some packages after 1.1.0).
- Route loaders use `%nowo.site_backup.panel.path_prefix%` / `%nowo.site_backup.setup.path_prefix%` so config overrides apply to imported routes.

### Documentation

- [SETUP-WIZARD.md](SETUP-WIZARD.md) / [CONFIGURATION.md](CONFIGURATION.md) / [UPGRADING.md](UPGRADING.md) updated for `progress_storage` and incomplete detector.
- Spec baseline amended (US-4–US-6); inventory refreshed (97/97).

## [1.1.0] - 2026-07-29

### Security

- Panel **fail-closed** when `password_protection` is true but `password_hash` is empty (`isMisconfigured()`).
- CSRF on panel/setup POSTs **fail-closed** if no CSRF token manager is available (require `symfony/security-csrf`).

### Added

- `make check-open-prs`, `demo-smoke`; `release-check` gates open PRs.
- `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- Spec Kit `.specify/` + full `code-inventory.md` (94/94); `docs/COVERAGE.md`.
- Release security checklist item for REQ-SEC-004.
- Expanded PHPUnit coverage toward the ≥99% Lines gate.

### Changed

- Coverage gate **≥ 99%** Lines; README reports measured **99.4%**.
- `backup.include_paths: []` or `["."]` now means **entire project** (minus `exclude_patterns`), with clean relative paths (no `./` prefix). Omitting the key still uses the selective defaults.
- Restore page `default_message` default is translation id `restore.page.message` (domain `NowoSiteBackupBundle`).
- Symfony component constraints aligned to `^7.0 || ^8.0` (was partially pinned to `^7.4`).

### Documentation

- [UPGRADING.md](UPGRADING.md) **To 1.1.0**; SECURITY / CONFIGURATION / USAGE updated for fail-closed and include_paths.

## [1.0.0] - 2026-07-26

First stable release of **Site Backup Bundle**.

### Added

#### Backup & restore

- Integral site backup (`.tar.gz` + SHA-256 `MANIFEST.json` + `.meta.json` sidecar).
- Configurable `backup.include_paths` / `exclude_patterns` and optional `database_dump_command`.
- Safe restore orchestrator: validate → extract to staging → apply with protected paths (`var/site-backup/`, `.env.local`, …).
- Public **restore loading page** (HTTP **503**) with progress polling (`/_site_backup/progress.json`).
- Admin panel at `/_site_backup` (password gate + CSRF): create / verify / restore / delete / history.
- CLI: `nowo:site-backup:create`, `list`, `verify`, `restore`, `hash-password`.
- Twig helpers `nowo_site_backup_is_restoring()` / `nowo_site_backup_progress()`.
- Domain events: `BackupCreatedEvent`, `BackupDeletedEvent`, `RestoreStartedEvent`, `RestoreCompletedEvent`, `RestoreFailedEvent`.
- Attribute `#[ExcludeFromRestore]` and configurable path / route / IP exclusions.

#### Setup wizard (cold start & post-restore)

- Site gate: restore loading → `/_setup` wizard → normal app.
- Detectors: marker files (`setup.required` / `setup.done`), optional Doctrine connect / empty-schema.
- Declarative profiles (`fresh_install`, `post_restore`, `minimal`) and step types: `requirements`, `database_url`, `database_create`, `cache_clear`, `migrations`, `schema_update`, `sql_file`, `console`, `admin_user`, `sample_data`, `marker`.
- Pluggable `AdminUserProvisionerInterface` (apps bind their User entity).
- After restore, marks `setup.required` with profile `post_restore` when enabled.
- CLI: `nowo:site-backup:setup`, `setup-status`, `setup-reset`.
- Setup events: `SetupStartedEvent`, `SetupStepCompletedEvent`, `SetupStepFailedEvent`, `SetupCompletedEvent`.
- Docs: [SETUP-WIZARD.md](SETUP-WIZARD.md).

#### Packaging & quality

- Symfony Flex recipe under `.symfony/recipe/`.
- FrankenPHP demo (`demo/symfony8`, default port **8056**, `FRANKENPHP_MODE=worker`).
- Spec Kit baseline under `specs/001-baseline/`.
- Translations **en / es / it / fr / pt / de / nl** (`NowoSiteBackupBundle`).
- QA: PHPUnit, PHP-CS-Fixer, PHPStan (+ FrankenPHP rulesets), Rector, Docker root compose.

### Compatibility

- PHP `>=8.2`, `<8.6` (Symfony **8.x** requires PHP **8.4+**)
- Symfony `^7.0 || ^8.0` (CI / mandatory minors: **7.4**, **8.0**, **8.1**)
- System `tar` required for archive create/extract

[Unreleased]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.35.2...HEAD
[1.23.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.22.2...v1.23.0
[1.22.2]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.22.1...v1.22.2
[1.22.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.22.0...v1.22.1
[1.22.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.3...v1.22.0
[1.21.3]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.2...v1.21.3
[1.21.2]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.1...v1.21.2
[1.21.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.21.0...v1.21.1
[1.21.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.20.0...v1.21.0
[1.20.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.19.2...v1.20.0
[1.19.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.19.0...v1.19.1
[1.19.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.18.1...v1.19.0
[1.18.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.18.0...v1.18.1
[1.18.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.15.1...v1.16.0
[1.15.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.15.0...v1.15.1
[1.15.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.14.3...v1.15.0
[1.14.3]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.14.2...v1.14.3
[1.14.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.13.8...v1.14.0
[1.9.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.8.1...v1.9.0
[1.8.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.3.2...v1.4.0
[1.3.2]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/SiteBackupBundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/SiteBackupBundle/releases/tag/v1.0.0

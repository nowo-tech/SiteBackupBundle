# Feature Specification: Local backup engine for scheduled backups (1.28.0)

**Feature Branch**: `feat/1.28.0-local-engine`  
**Created**: 2026-11-04  
**Status**: Implemented (proposed **v1.28.0**)  
**Origin**: symfony-beacon request `2026-11-04-site-backup-scheduled-remote` (items 1, 2, 3 without passwords, 5, 7,
8), Beacon spec 203 block B0, contract "SiteBackupBundle 1.28 – 1.30" v1.0 (Beacon Q-B1 … Q-B9 and owner Q-O1 … Q-O7
decided 2026-11-04). Owner decision D-20261104-15: no secret ever goes into an archive; keys and passwords are kept
apart from the backups.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-001 | `SiteBackupManager::createBackup(BackupOptions\|string\|null, ?string)`: immutable, non-serialisable `BackupOptions` (database / files / include paths / table rules / format / level / label / actor / heartbeat / `allowUnsafeTableRules` / `extraMeta`); the 1.27 signature keeps working through `defaultOptions()` (`backup.default_format`, `backup.compression_level`). No HTTP or session access. |
| FR-002 | Formats `tar.gz` (tar streamed through PHP gzip, level 0–9), `zip` (ext-zip, deflate level or store) and `sql.gz` (database only; `plaintext_sha256` in the sidecar). Verify, extract and restore support every format; zip extraction refuses unsafe names, symlinks and encrypted entries. |
| FR-003 | `backup.database.dumper: builtin`: `mysqldump` argv from the database URL (password in `MYSQL_PWD`, `--` before the database), `--result-file` into the owner-only staging file, `--set-gtid-purged=OFF` with the Oracle client; `extra_options` refusing options that write elsewhere or read credentials / files. |
| FR-004 | Table rules (`skip` / `structure`) need the builtin dumper: a data pass with `--ignore-table=<db>.<t>` and a `--no-data` pass for structure-only tables appended to the same file. Checked first against the live schema (`information_schema` through the `mysql` client, or a host `SchemaInspectorInterface`): unknown names and views refused; required tables (`backup.database.required_tables` + migrations table) refused; `skip` of an FK-referenced or view-used table refused unless `allowUnsafeTableRules` (then a warning); `structure` of a referenced table warns. `nowo:site-backup:db-dump` gains `--ignore-table` / `--structure-only` / `--allow-unsafe-table-rules`. |
| FR-005 | Secrets floor (`SecretPathPolicy`, not removable): `.env*` except root `.env.dist` / `.env.example`, `config/secrets/**`, `var/secrets/**`, `config/jwt/**`, `*.key`, `*.pem`, `*.p12`, `*.pfx`, `*.jks`, `id_rsa*`, `id_ed25519*`, `auth.json`, `.npmrc`, `.git-credentials`, `.netrc`, `.pgpass`, `.my.cnf`; `backup.forbidden_paths` adds patterns. Skipped while collecting (also symlink targets), asserted on the final file list (`SecretsInArchiveException`), never written by a restore. Default `include_paths` drops `.env`; an explicit floor path is deprecated. |
| FR-006 | `backup.work_dir` (`0700`, tightened when it exists): per-run owner-only directory removed in a `finally`; the archive is built there and moved into `storage_dir` when complete; stale run directories removed by the next backup and `nowo:site-backup:cleanup`. |
| FR-007 | Events: `BackupStartedEvent` (operation id, loggable options, actor), `BackupFailedEvent` (class + redacted message ≤ 500, no exception object), `BackupCreatedEvent` + operation id / format / warnings; `SiteBackupEvents` constants. |
| FR-008 | `BackupOperationLock` (lock.factory or `flock`): backup, delete and restore are mutually exclusive; heartbeat refreshes the lock; `BackupAlreadyRunningException`; `nowo:site-backup:create` exits 75. |
| FR-009 | `RetentionPlanner` (pure) + `RetentionPolicy` (keep last, max age, daily / weekly / monthly) with the invariants of the contract §10; `backup.retention` for `storage_dir`. |
| FR-010 | `DumpImporter` (mysql client, file through a shell redirect, password in env, error output reduced to the error code), `nowo:site-backup:db-import --force`, `sql_file` `importer: client`. |
| FR-011 | Preflight disk check (`backup.preflight_disk_check`), MANIFEST v2 / sidecar keys, `BackupArtifact` getters, `nowo:site-backup:create` options and `--json`. |

## Non-goals (1.29.0 / 1.30.0)

Archive passwords (zip AES-256, age), `importArchive()`, remote targets, signatures, restore drill helpers; binlog
incrementals and chains.

## Tests

`tests/Unit/Engine/*` (options, secrets floor, retention property test, table safety, fake `mysqldump` / `mysql`
binaries for the dumper, inspector and importer, every format round trip, zip-slip guard, work-dir cleanup, preflight,
manager events / lock / retention, commands, `sql_file` client importer, restore skipping secrets of a 1.27 archive,
container wiring), plus updated 1.27 tests.

# Feature Specification: Archive passwords, import and remote targets (1.29.0)

**Feature Branch**: `feat/1.29.0-crypto-remote` (stacked on `feat/1.28.0-local-engine`)  
**Created**: 2026-11-04  
**Status**: Implemented (proposed **v1.29.0**; security audit before the tag, Q-O7)  
**Origin**: symfony-beacon request items 3 (passwords), 4, 6, 7; contract v1.0 §2, §3, §7, §9, §13, §14.

## Requirements

| ID | Requirement | State |
| --- | --- | --- |
| FR-001 | `ArchivePassword` (≥ 16 chars, masked, not serialisable, wipe); `BackupOptions::$password` / `$keyId` | Done |
| FR-002 | `zip` + password → AES-256 (AE-2) per entry, never ZipCrypto, availability check, weak-password warning | Done |
| FR-003 | Password on `tar.gz` / `sql.gz` → age scrypt container via the age ≥ 1.3 binary + batchpass, passphrase on fd 3 (owner re-decision Q-O4 option A); first-use version / plugin check; CLI interop in CI | Done |
| FR-004 | Verify / extract with a password; `WrongPasswordException` | Done |
| FR-005 | `importArchive()` + `nowo:site-backup:import`: content detection, decrypt, zip/tar slip + link + ZipCrypto refusal, MANIFEST check, secret paths dropped, plaintext `tar.gz` `0600`, `BackupImportedEvent` | Done |
| FR-006 | `BackupRemoteTargetInterface`, `RemoteObject`, `InMemoryRemoteTarget`, `RemoteBackupPublisher` (publish + verify modes + sidecar, prune, orphans), remote events | Done |
| FR-007 | `--password-env` / `--password-stdin` on create / verify / import; `--key-id` | Done |
| FR-008 | Optional `FlysystemRemoteTarget` (sidecar metadata emulation) | Done |
| FR-009 | Optional Ed25519 signature of the sidecar (`backup.signing.*`), off by default (Q-O3) | Done |
| FR-010 | Restore drill helpers (`BackupOptions::$recordTableStats`, `DumpImporter::importInto()`, `RestoreDrill::compare()`), Beacon Q-B8 | Done (+ `nowo:site-backup:drill`) |
| FR-011 | `nowo:site-backup:verify --file` with exit codes | Done |

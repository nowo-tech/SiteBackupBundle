# Archive encryption (1.29+)

## Model

- **The archive password is the encryption.** The bundle never generates, stores or logs it; the host keeps it in its
  key ring, apart from the backups (no key ever travels inside an archive, see the secrets floor in
  [CONFIGURATION.md](CONFIGURATION.md#secrets-floor-128)). `BackupOptions::$keyId` records which key of the ring was
  used (an opaque id, never derived from the password).
- `ArchivePassword::fromString()` refuses fewer than 16 characters; it is masked in dumps and string casts and cannot
  be serialised. Console commands read it from `--password-env=NAME` or `--password-stdin`, never from argv.

## Formats

| Format | With a password | Opens with |
| --- | --- | --- |
| `zip` | WinZip AE-2, **AES-256**, every entry (`ZipArchive::EM_AES_256`). ZipCrypto is never written and refused on import | 7-Zip / p7zip (`7z x -p`), `7zz`, PeaZip, WinZip, WinRAR, Keka, The Unarchiver, `bsdtar` ≥ 3.6. **Not** Windows Explorer nor macOS Archive Utility |
| `tar.gz`, `sql.gz` | Not available yet: `FormatUnavailableException` (the age encryptor is pending an owner decision) | — |

`BackupArchiver::assertZipAesAvailable()` checks that libzip was built with a crypto backend.

## Key derivation caveat (zip)

AE-2 derives its keys with PBKDF2-HMAC-SHA1 and 1000 iterations (fixed by the format): weak against offline guessing
of human passwords. Use generated passwords (e.g. 24 random base64url characters ≈ 144 bits). The bundle adds a
warning to the backup when a password scores below about 100 bits.

## What the encryption does not hide

The zip central directory (file names, sizes, dates) is not encrypted. For database-only backups that is
`database/dump.sql` and `MANIFEST.json`.

## Integrity

`MANIFEST.json` (SHA-256 per file) is inside the encrypted archive; the SHA-256 of the archive bytes is in the
sidecar and in the remote metadata (`nowo-sha256`), so a download can be checked without the password. AE-2 also
authenticates every entry (HMAC-SHA1): a wrong password or a modified byte fails before anything is written.

## Restore

`importArchive($path, $password)` (or `nowo:site-backup:import`) decrypts, verifies and stores a **plaintext**
`tar.gz` (`0600`) in `storage_dir`; restore it, then delete it. A restore also needs the keys the host keeps apart
(e.g. the Halite key and vault decrypt keys).

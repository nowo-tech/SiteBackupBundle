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
| `tar.gz`, `sql.gz` | **age** passphrase container (`.tar.gz.age`, `.sql.gz.age`; scrypt, ChaCha20-Poly1305), written by the `age` binary ≥ 1.3 with `age-plugin-batchpass` | `age -d` (asks for the passphrase) or `AGE_PASSPHRASE_FD=3 age -d -j batchpass`, then `tar xzf` / `gunzip`; `rage` too |

`BackupArchiver::assertZipAesAvailable()` checks that libzip was built with a crypto backend.

## age (tar.gz / sql.gz) — image requirement

Install **age ≥ 1.3** and its **`age-plugin-batchpass`** (both ship in the official release archive) on `PATH`, or
set `nowo_site_backup.backup.encryption.age.binary` to an absolute path (the plugin must sit next to it or on `PATH`):

```dockerfile
# Example (Debian/Alpine, amd64): pin the version and check the sha256 published on the GitHub release.
ARG AGE_VERSION=1.3.1
ARG AGE_SHA256=bdc69c09cbdd6cf8b1f333d372a1f58247b3a33146406333e30c0f26e8f51377
RUN curl -fsSL -o /tmp/age.tgz https://github.com/FiloSottile/age/releases/download/v${AGE_VERSION}/age-v${AGE_VERSION}-linux-amd64.tar.gz \
 && echo "${AGE_SHA256}  /tmp/age.tgz" | sha256sum -c - \
 && tar -xzf /tmp/age.tgz -C /tmp && install -m 0755 /tmp/age/age /tmp/age/age-plugin-batchpass /usr/local/bin/ \
 && rm -rf /tmp/age /tmp/age.tgz
```

- The bundle checks the binary on first use (`AgeBinaryEncryptor::assertAvailable()`): missing, older than 1.3 or
  without the plugin → `FormatUnavailableException` with the reason.
- **The passphrase never touches argv or the environment**: it is written to a dedicated pipe that age sees as fd 3
  (`AGE_PASSPHRASE_FD=3`); the child environment holds only `PATH`, the fd number and the work factors. A test reads
  `/proc/<pid>/cmdline` and `/proc/<pid>/environ` of age and its plugin while they run.
- scrypt work factor 18 when encrypting (`encryption.age.work_factor`, 10–22) and at most 22 accepted when decrypting
  (`encryption.age.max_work_factor`), so a hostile file cannot cost gigabytes of RAM.
- Passwords with CR / LF are refused (the plugin strips trailing newlines).

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

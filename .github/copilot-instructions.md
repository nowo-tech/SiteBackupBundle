## AI contribution guidelines (Nowo Symfony bundle)

Use this when suggesting code, tests, documentation, or CI changes for this repository.

### Scope

- This is a **Symfony bundle** for integral site backup / safe restore UI (`nowo-tech/site-backup-bundle`).
- Respect **PHP** and **Symfony** ranges in `composer.json`.
- Prefer **PHP 8 attributes**; do not introduce `doctrine/annotations` for new metadata.

### Code

- Follow **PSR-12** and project CS-Fixer / PHPStan expectations (`ignoreErrors: []`, level ≥ 8).
- Panel and setup POSTs must stay **CSRF fail-closed**; password protection must stay **fail-closed** when `password_hash` is missing.
- Document breaking changes in `docs/UPGRADING.md` and `docs/CHANGELOG.md`.

### Documentation

- User-facing documentation in **English** under `docs/`.
- README follows Nowo canonical structure (including FrankenPHP Friendly banner when CS-005 is met).

### Tests

- Add PHPUnit tests for new behavior; preserve coverage gate ≥ **99%** Lines (`make coverage-check`). See `docs/COVERAGE.md`.

### Git

- **Never** add `Co-authored-by: Cursor` (or similar) trailers to commit messages (REQ-GIT-001).

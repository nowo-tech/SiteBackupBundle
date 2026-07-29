# Coverage policy (REQ-TEST-003)

## Threshold

- **PHP line coverage gate:** **≥ 99%** of includable `src/` (`make coverage-check`).
- Prefer **100%**; residual lines are OS/environment defensive branches that are not reproducible under the Docker QA image (process runs as root; `exec`/`tar` always available).

## Measured coverage

Run `make test-coverage` and read the summary from `coverage-php.txt` / Clover `coverage.xml`.

Last remediation pass (2026-07-29): **~99.4%** Lines with 179 PHPUnit tests.

## Justified residual branches

These paths remain hard to exercise without breaking the host or mocking PHP internals:

| Area | Why excluded from the 100% ambition |
| --- | --- |
| Unreadable include directories in `BackupArchiver` | Docker QA runs as root; `chmod 000` does not deny read |
| Defensive I/O failures in progress/history storages | `tempnam` / `file_put_contents` / `rename` / `file()` OS failures |
| `RequirementsStep` when `tar`/`exec` missing | Image always has `tar` and `exec` enabled |
| Dead `delete()` false after pre-check in `SiteBackupManager` | Preceding guard throws before the false return |

Do **not** add broad `@codeCoverageIgnore` on business logic. Expand tests when a residual becomes reproducible.

## README

The Tests and coverage section must match this measured percentage (not a stale 100% badge claim).

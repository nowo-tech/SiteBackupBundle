# Site Backup Bundle — baseline product spec

## Intent

Provide integral website backups with checksum integrity and a safe restore UX (loading page) that prevents visitors from hitting a half-restored application.

## Capabilities

1. Create `.tar.gz` backups of configured paths + optional DB dump + `MANIFEST.json` (SHA-256).
2. Verify archive and per-file checksums before restore.
3. Restore via staging + protected paths; progress persisted for the public UI.
4. While restore is active, intercept main requests with HTTP 503 loading page; panel/progress remain available.
5. Admin panel and CLI for create/list/verify/restore.

## Non-goals

- Automatic live SQL import during HTTP restore
- Multi-tenant profiles
- Offsite object-storage adapters (pluggable later)

# Demo (FrankenPHP)

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

See `demo/symfony8` for a FrankenPHP + Caddy demo (`FRANKENPHP_MODE=worker` by default).

| Demo | Symfony | PHP | Default port | DB |
| --- | --- | --- | --- | --- |
| `demo/symfony8` | **8.1** | 8.5 | **8056** | MySQL 8.4 (compose network only) |

```bash
make -C demo help
make -C demo up-symfony8
```

- Panel: `/_site_backup` (demo password: `password`) — UiKit scaffolds in the demo host layouts (`templates/layouts/`)
- Setup wizard: `/_setup` redirects to `/en/_setup` (`locale.in_path: both`); also `/es/_setup`
- Fresh install reset: `php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y`
- Custom tab profile: `… --mark-required=demo_features -y` then `/_setup?profile=demo_features`
- Custom gate detector: toggle on homepage (`#[AsSetupNeedDetector]`)
- Health exclusion: `/health`
- `DATABASE_URL` points at service `mysql` (no host DB ports — REQ-DEMO-006)
- Worker mode (kernel not reset): after restore / `cache_clear` / `database_url`, check `bin/console nowo:site-backup:worker-restart` and restart workers — see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)

See [SETUP-WIZARD.md](SETUP-WIZARD.md), [USAGE.md](USAGE.md), and [demo/README.md](../demo/README.md).

## Table of contents

- [Start the demo in development](#start-the-demo-in-development)
- [Start the demo in production mode](#start-the-demo-in-production-mode)
- [Timeout hierarchy (REQ-RUNTIME-001)](#timeout-hierarchy-req-runtime-001)
- [Troubleshooting](#troubleshooting)

## Start the demo in development

```bash
make -C demo up-symfony8        # build + start (APP_ENV=dev, FRANKENPHP_MODE=worker by default)
make -C demo/symfony8 logs      # follow FrankenPHP logs
make -C demo update-bundle-all  # re-sync the mounted bundle after editing src/
```

For edit-and-refresh without worker restarts, set `FRANKENPHP_MODE=classic` in `demo/symfony8/.env` and recreate the container (`docker compose up -d`): the entrypoint then uses `Caddyfile.dev` (plain `php_server` with `hot_reload`). Twig cache is off in `dev`.

## Start the demo in production mode

The demo is not a production deployment, but the production runtime can be reproduced locally:

1. Keep `FRANKENPHP_MODE=worker`: `Caddyfile` runs `php_server { worker { file /app/public/index.php; watch } }`.
2. Run with `APP_ENV=prod` / `APP_DEBUG=0` (compose override), `composer install --no-dev --optimize-autoloader` and `php bin/console cache:clear --env=prod` inside the container.
3. Set a real panel password hash (`nowo:site-backup:hash-password`) and the setup token before exposing the host (see [SECURITY.md](SECURITY.md)).
4. After a restore, `cache_clear` or a `DATABASE_URL` change, run `bin/console nowo:site-backup:worker-restart` and restart the workers (kernel is not reset between requests).

## Timeout hierarchy (REQ-RUNTIME-001)

Backups and restores run blocking subprocesses (`tar`, `mysqldump`, `php bin/console …` setup steps). Keep the deadlines layered, innermost first:

| Layer | Setting | Demo / default |
| --- | --- | --- |
| Operation | `nowo_site_backup.process_timeout`: Symfony `Process` timeout **and** idle timeout for `tar`, the database dump and console setup steps. On expiry the whole process tree is killed (children first, e.g. `mysqldump` under `sh -c`) and the backup / step fails with a controlled error | demo **300 s**, bundle default **600 s** |
| PHP | `max_execution_time` / `max_input_time`; with `bump_time_limit: true` the panel and setup requests call `set_time_limit(bump_time_limit_seconds ?? process_timeout)` — set `bump_time_limit_seconds` **above** `process_timeout` so the process timeout fires first | demo **360 s** (`frankenphp { php_ini … }` in both Caddyfiles) |
| HTTP server | Caddy `servers { timeouts { write … } }` — strictly **greater** than the PHP limit | demo **400 s** |
| FrankenPHP queue | `max_wait_time` caps how long a request waits for a free PHP thread (504 instead of an endless queue) | demo **30 s** |

When raising `process_timeout`, raise the PHP and HTTP write deadlines in the same change. Long restores should preferably run from the CLI (`nowo:site-backup:restore`), which is not bound by HTTP timeouts. See [CONFIGURATION.md](CONFIGURATION.md) (`process_timeout`, `bump_time_limit`, `restore.stale_after_seconds`).

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Twig or PHP changes do not appear | Worker keeps the kernel in memory: use `FRANKENPHP_MODE=classic` or restart the container. |
| `FRANKENPHP_MODE` change has no effect | Env is read at container creation: `docker compose up -d` (recreate), not `restart`. |
| Site stays in 503 after a crashed restore | The restore heartbeat stopped; after `restore.stale_after_seconds` (default 2 × `process_timeout`) the restore is treated as abandoned. |
| Backup / restore fails with a Process timeout | Raise `process_timeout` and, with it, the PHP and HTTP deadlines above — or run the CLI command instead. |
| Panel access denied ("misconfigured") | No password hash configured (fail-closed): generate one with `nowo:site-backup:hash-password`. |
| Setup wizard asks for a token | Expected on internet-facing hosts: pass `?token=…` (see [SETUP-WIZARD.md](SETUP-WIZARD.md)). |
| `DATABASE_URL` cannot connect | The demo MySQL has no host ports (REQ-DEMO-006): use the compose service name `mysql`. |


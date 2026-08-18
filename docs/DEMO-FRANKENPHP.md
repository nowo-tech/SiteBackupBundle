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

- Panel: `/_site_backup` (demo password: `password`) — host `panel.layout_template`
- Setup wizard: `/_setup` redirects to `/en/_setup` (`locale.in_path: both`); also `/es/_setup`
- Fresh install reset: `php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y`
- Custom tab profile: `… --mark-required=demo_features -y` then `/_setup?profile=demo_features`
- Custom gate detector: toggle on homepage (`#[AsSetupNeedDetector]`)
- Health exclusion: `/health`
- `DATABASE_URL` points at service `mysql` (no host DB ports — REQ-DEMO-006)

See [SETUP-WIZARD.md](SETUP-WIZARD.md), [USAGE.md](USAGE.md), and [demo/README.md](../demo/README.md).

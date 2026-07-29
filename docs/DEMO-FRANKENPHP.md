# Demo (FrankenPHP)

See `demo/symfony8` for a FrankenPHP + Caddy demo (`FRANKENPHP_MODE=worker` by default).

| Demo | Symfony | PHP | Default port | DB |
| --- | --- | --- | --- | --- |
| `demo/symfony8` | **8.1** | 8.5 | **8056** | MySQL 8.4 (compose network only) |

```bash
make -C demo help
make -C demo up-symfony8
```

- Panel: `/_site_backup` (demo password: `password`)
- Setup wizard: `/_setup` after `php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y`
- Health exclusion: `/health`
- `DATABASE_URL` points at service `mysql` (no host DB ports — REQ-DEMO-006)

See [SETUP-WIZARD.md](SETUP-WIZARD.md) and [USAGE.md](USAGE.md).

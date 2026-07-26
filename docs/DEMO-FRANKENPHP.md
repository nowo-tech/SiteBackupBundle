# Demo (FrankenPHP)

See `demo/symfony8` for a FrankenPHP + Caddy demo (`FRANKENPHP_MODE=worker` by default).

| Demo | Symfony | PHP | Default port |
| --- | --- | --- | --- |
| `demo/symfony8` | **8.1** | 8.5 | **8056** |

```bash
make -C demo help
make -C demo up-symfony8
```

- Panel: `/_site_backup` (demo password: `password`)
- Setup wizard: `/_setup` after `php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y`
- Health exclusion: `/health`

See [SETUP-WIZARD.md](SETUP-WIZARD.md) and [USAGE.md](USAGE.md).

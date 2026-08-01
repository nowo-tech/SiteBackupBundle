# Site Backup Bundle — demo

```bash
make -C demo up-symfony8
```

Open http://localhost:8056

| Feature | Demo |
| --- | --- |
| Panel + host layout | `/_site_backup` (password: `password`) |
| Setup + locale-in-path | `/_setup` → `/en/_setup`; also `/es/_setup` |
| Custom gate detector | Homepage toggle → `var/site-backup/demo-force-setup` |
| Custom tab + checker | `/_setup?profile=demo_features` |
| CSS framework hint | `css_framework: custom` on `<html data-css-framework>` |

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) and [demo/symfony8/README.md](symfony8/README.md).

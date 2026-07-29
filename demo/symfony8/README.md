# Symfony 8 FrankenPHP demo — Site Backup Bundle

Panel: `/_site_backup` · Setup: `/_setup` · Port: **8056**.

- Panel password: `password`
- Worker mode: `FRANKENPHP_MODE=worker` (default)
- MySQL 8.4 on the compose network (`DATABASE_URL` → host `mysql`, no published DB ports)

```bash
make -C demo up-symfony8
```

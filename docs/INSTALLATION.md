# Installation

## Requirements

- PHP 8.2+ (Symfony 8.x requires PHP 8.4+)
- Symfony HttpKernel / Console components as declared in `composer.json`
- `symfony/twig-bundle` (or `twig/twig`) to render the public restore loading page, the admin panel, and the setup wizard
- Optional: `symfony/security-bundle` if you replace `SiteBackupAccessGateInterface`
- Optional: Doctrine DBAL for `sql_file` import / connect detectors; apps bind `AdminUserProvisionerInterface` for the setup wizard
- System `tar` binary for create/extract

## Composer

```bash
composer require nowo-tech/site-backup-bundle
```

## Routes

```yaml
# config/routes.yaml
nowo_site_backup:
    resource: '@NowoSiteBackupBundle/Resources/config/routes.yaml'
```

## Panel password

```bash
php bin/console nowo:site-backup:hash-password
# or: php bin/console nowo:site-backup:hash-password 'your-secret'
```

Put the hash in `nowo_site_backup.security.password_hash` or `SITE_BACKUP_PASSWORD_HASH`.

## Next steps

See [CONFIGURATION.md](CONFIGURATION.md), [USAGE.md](USAGE.md), and [SETUP-WIZARD.md](SETUP-WIZARD.md).

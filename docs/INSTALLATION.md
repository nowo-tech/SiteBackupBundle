# Installation

## Table of contents

- [Requirements](#requirements)
- [Composer](#composer)
- [Routes](#routes)
- [Firewall / access control](#firewall--access-control)
- [Panel password (required by default)](#panel-password-required-by-default)
- [Next steps](#next-steps)

## Requirements

- PHP 8.2+ (Symfony 8.x requires PHP 8.4+)
- Symfony HttpKernel / Console components as declared in `composer.json`
- `symfony/twig-bundle` (or `twig/twig`) to render the public restore loading page, the admin panel, and the setup wizard
- **`symfony/security-csrf`** (or Framework CSRF) — panel and setup POSTs **fail closed** without a CSRF token manager
- Optional: `symfony/security-bundle` when the panel is enabled and `security.allow_unauthenticated` is `false` (default). Also used if you replace `SiteBackupAccessGateInterface`
- Optional: Doctrine DBAL for `sql_file` import / connect detectors; apps bind `AdminUserProvisionerInterface` for the setup wizard
- System `tar` binary for create/extract

## Composer

```bash
composer require nowo-tech/site-backup-bundle
```

The Flex recipe lives under `.symfony/recipe/` (copy those files if Flex does not apply them).

## Routes

```yaml
# config/routes.yaml
nowo_site_backup:
    resource: '@NowoSiteBackupBundle/Resources/config/routes.yaml'
```

## Firewall / access control

Protect the admin panel and setup wizard in the host application:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/_site_backup, roles: ROLE_ADMIN }
        - { path: ^/_setup, roles: ROLE_ADMIN }
```

`/_setup` is only meaningful while setup detectors say setup is required; keep it locked down (and remove or block after go-live). Bundle-level role/password/CSRF checks are fail-closed but do not replace Symfony `access_control`. See [SECURITY.md](SECURITY.md).

## Panel password (required by default)

The panel also follows REQ-UI-002: `security.access_roles` (default `[ROLE_ADMIN]`), optional `access_checker`, and `allow_unauthenticated` (default `false`). The password gate is **additional**.

When `security.password_protection` is `true` (default), **`password_hash` must be set**. Without a hash the panel stays locked (fail-closed).

```bash
php bin/console nowo:site-backup:hash-password
# or: php bin/console nowo:site-backup:hash-password 'your-secret'
```

Put the hash in `nowo_site_backup.security.password_hash` or `SITE_BACKUP_PASSWORD_HASH`.

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.

## Next steps

See [CONFIGURATION.md](CONFIGURATION.md), [USAGE.md](USAGE.md), and [SETUP-WIZARD.md](SETUP-WIZARD.md).

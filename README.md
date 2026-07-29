# Site Backup Bundle

[![CI](https://github.com/nowo-tech/SiteBackupBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/SiteBackupBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/site-backup-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/site-backup-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/site-backup-bundle.svg)](https://packagist.org/packages/nowo-tech/site-backup-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/site-backup-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/SiteBackupBundle) [![Coverage](https://img.shields.io/badge/Coverage-99.4%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** [Install from Packagist](https://packagist.org/packages/nowo-tech/site-backup-bundle) · Give it a **star** on [GitHub](https://github.com/nowo-tech/SiteBackupBundle) so more developers can find it.

**Site Backup Bundle** — Create an **integral website backup** (selected paths + optional DB dump + SHA-256 `MANIFEST.json`), restore it safely behind a **loading page UI**, and finish cold bootstrap with a **setup wizard** (DB, migrations, admin, sample data). Tested on Symfony **7.4**, **8.0**, and **8.1** · PHP 8.2+ (Symfony 8.x requires PHP 8.4+).

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## Features
- **Integral backup** — Configurable include paths, exclude patterns, optional `database_dump_command`, `.tar.gz` + sidecar metadata.
- **Integrity** — Per-file SHA-256 in `MANIFEST.json` plus archive SHA-256; `verify` before restore.
- **Safe restore** — Validate → extract to staging → apply with protected paths → progress JSON; `var/site-backup/` never overwritten mid-restore.
- **Loading page** — While restore is active, `kernel.request` returns **HTTP 503** with a progress UI (polls `/_site_backup/progress.json`); panel stays reachable.
- **Setup wizard** — Cold start / post-restore: create DB, migrations/schema, minimal SQL, idempotent loaders, super-admin, optional sample data — see [docs/SETUP-WIZARD.md](docs/SETUP-WIZARD.md).
- **Admin panel** — Create / verify / restore / delete under `/_site_backup` (password gate + CSRF).
- **CLI** — `create`, `list`, `verify`, `restore`, `setup`, `setup-status`, `setup-reset`, `hash-password`.

## Installation
```bash
composer require nowo-tech/site-backup-bundle
```

With **Symfony Flex**, the recipe registers the bundle and config. Without Flex, see [docs/INSTALLATION.md](docs/INSTALLATION.md).

```yaml
# config/routes.yaml
nowo_site_backup:
    resource: '@NowoSiteBackupBundle/Resources/config/routes.yaml'
```

## Requirements
- PHP `>=8.2` (<8.6); **Symfony 8.0** and **8.1** require **PHP 8.4+**
- Symfony **7.4**, **8.0**, or **8.1** (minimum supported minors; also works on Symfony 7.0–7.3 via `composer.json` constraints)
- `tar` available on the host/container for archive create/extract
- Twig for the restore page, panel, and setup wizard templates

## Configuration
```yaml
nowo_site_backup:
    enabled: true
    backup:
        include_paths: [config, public, templates, src, composer.json, composer.lock]
        database_dump_command: '%env(default::SITE_BACKUP_DUMP_CMD)%'
    panel:
        path_prefix: '/_site_backup'
    security:
        password_protection: true
        password_hash: '%env(SITE_BACKUP_PASSWORD_HASH)%'
    setup:
        admin_provisioner: App\Setup\AdminUserProvisioner
```

## Usage
```bash
php bin/console nowo:site-backup:create -l "pre-deploy"
php bin/console nowo:site-backup:verify <id>
php bin/console nowo:site-backup:restore <id> -y
```

Open `/_site_backup` to manage backups from the UI. During restore, visitors see the loading page; the panel and progress endpoint keep working. For cold DB / post-restore, open `/_setup` (see [docs/SETUP-WIZARD.md](docs/SETUP-WIZARD.md)).

See [docs/USAGE.md](docs/USAGE.md).

## Demo
| Demo | Symfony | PHP | Default port |
| --- | --- | --- | --- |
| `demo/symfony8` | **8.1** | 8.5 | **8056** |

Runs **FrankenPHP + Caddy** (`FRANKENPHP_MODE=worker` by default). See [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md).

```bash
make -C demo help
make -C demo up-symfony8
```

## Development
```bash
make up
make install
make test
make cs-check
make phpstan
make release-check
```

## Documentation
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Setup Wizard (cold start & post-restore)](docs/SETUP-WIZARD.md)
- [Demo (FrankenPHP)](docs/DEMO-FRANKENPHP.md)
- [GitHub CI notes](docs/GITHUB_CI.md)

## Tests and coverage
- Tests: PHPUnit (PHP)
- PHP: **99.4%** Lines (gate ≥ **99%** via `make coverage-check`)
- Residual OS/defensive branches: see [docs/COVERAGE.md](docs/COVERAGE.md)

## License and author
MIT · [Nowo.tech](https://nowo.tech)

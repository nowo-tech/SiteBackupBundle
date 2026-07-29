# Upgrading

## To 1.2.0

Durable setup progress (optional DBAL / chain storage) and an incomplete-progress site gate.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.2
php bin/console cache:clear
```

### Behaviour

- Default storage remains **`filesystem`** (`var/site-backup/setup-progress.json`). No config change required for existing apps.
- `setup.detectors.incomplete_progress` defaults to **`true`**: if progress phase is `running`, `waiting_input`, or `failed` (and `setup.done` is absent), the site gate sends visitors to `/_setup`. Disable with `incomplete_progress: false` if you do not want mid-wizard gating.
- `setup.progress_storage: doctrine` or `chain` needs a working Doctrine DBAL default connection (`doctrine.dbal.default_connection`). Soft dependency — see `composer suggest` for `doctrine/dbal`.

### Optional: survive wiping `var/`

```yaml
nowo_site_backup:
    setup:
        progress_storage: chain   # filesystem | doctrine | chain
        # progress_table: nowo_site_backup_setup_progress
        detectors:
            incomplete_progress: true
```

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Setup progress storage | JSON file only | Optional `doctrine` / `chain` (`progress_storage`) |
| Mid-wizard after `var/` wipe | Gate lost if markers/JSON gone | With `chain`/`doctrine` + incomplete detector, gate resumes from DB |
| Progress timestamps | Not persisted | `started_at` / `completed_at` in JSON + DB; shown by `setup-status` |
| Default `setup.path_prefix` | `/_setup` | **`/_setup`** (override with `path_prefix: '/_setup'` if you must keep the old URL) |
| Route prefixes | Hard-coded in `routes.yaml` | `%nowo.site_backup.*.path_prefix%` parameters |

### Breaking / notable changes

- **Default wizard URL** is `/_setup` (was `/_setup`). Update firewalls, bookmarks, and reverse-proxy rules; or set `setup.path_prefix: '/_setup'` to keep the previous path.
- New detector default (`incomplete_progress: true`) only affects apps that already have unfinished progress state on disk/DB.
- Composer `require` for `symfony/config`, `dependency-injection`, `http-foundation`, `http-kernel`, and `security-core` is aligned again to `^7.0 || ^8.0` (same as the rest of the bundle).
- Imported routes honour `panel.path_prefix` / `setup.path_prefix` via container parameters (defaults `/_site_backup` and `/_setup`).

## To 1.1.0

Security hardening, full-tree include_paths option, restore message i18n default, and coverage gate ≥99%.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.1
php bin/console cache:clear
```

### Behaviour / security

- With `security.password_protection: true` (default), **`password_hash` must be set** or the panel gate reports misconfigured / denies access (fail-closed). Generate with `php bin/console nowo:site-backup:hash-password`.
- Panel and setup POSTs require a working CSRF token manager (`symfony/security-csrf` is already a hard dependency).

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| `backup.include_paths: []` or `["."]` | Empty / path-as-written | **Entire project** minus `exclude_patterns` |
| Omit `include_paths` | Selective defaults | Unchanged (same selective defaults) |
| `restore.default_message` default | English literal | Translation id `restore.page.message` |

If you relied on an explicit empty `include_paths` list meaning “use defaults”, **remove the key** instead of setting `[]`.

### Breaking / notable changes

- Explicit `include_paths: []` / `["."]` semantics change as above.
- Default restore loading message is now a translation key (override with a literal string still works if you set `default_message` yourself).

## To 1.0.0

First public release. No prior Packagist versions — install fresh.

### Requirements

- **PHP** `>=8.2` and `<8.6`. Symfony **8.x** requires **PHP 8.4+**.
- **Symfony** `^7.0 || ^8.0` (CI minors: **7.4**, **8.0**, **8.1**).
- System **`tar`** available in the runtime for backup create/extract.
- **Twig** for the restore loading page, admin panel, and setup wizard.

### Install

```bash
composer require nowo-tech/site-backup-bundle:^1.0
```

With Flex, the recipe registers the bundle and copies config. Without Flex, see [INSTALLATION.md](INSTALLATION.md).

```yaml
# config/routes.yaml
nowo_site_backup:
    resource: '@NowoSiteBackupBundle/Resources/config/routes.yaml'
```

This imports both `/_site_backup` (panel) and `/_setup` (wizard).

### Suggested first-time config

```yaml
nowo_site_backup:
    security:
        password_hash: '%env(SITE_BACKUP_PASSWORD_HASH)%'
    setup:
        # Bind your app User creator (required for admin_user steps)
        admin_provisioner: App\Setup\AdminUserProvisioner
        # Optional: force wizard until setup.done exists (fresh clones)
        # require_done_marker: true
        detectors:
            marker: true
            # doctrine_connect: true
            # doctrine_schema_empty: true
```

Generate a panel password hash:

```bash
php bin/console nowo:site-backup:hash-password
```

### Behaviour notes (first install)

| Topic | Default |
| --- | --- |
| Restore loading page | On when a restore is active (`enabled: true`) |
| Setup wizard gate | On when `setup.required` exists; `require_done_marker` defaults to **false** so existing apps are not locked after `composer require` |
| After successful restore | Writes `setup.required` with profile `post_restore` (`setup.trigger_after_restore: true`) |
| DB dump on restore | Copied to `var/site-backup/last-restore-dump.sql`; import via setup `sql_file` step or ops |

### After installing

1. `php bin/console cache:clear`
2. Open `/_site_backup` and create a test backup (or use CLI `nowo:site-backup:create`).
3. Optionally mark setup and walk the wizard:  
   `php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y`
4. Read [USAGE.md](USAGE.md) and [SETUP-WIZARD.md](SETUP-WIZARD.md).

### Breaking changes

None — initial release.

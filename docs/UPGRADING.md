# Upgrading

## To 1.3.1

Twig reusability guidance (REQ-TWIG-001 / REQ-UI-001) and a shared Continuar partial.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.3.1
php bin/console cache:clear
```

### Behaviour

- No breaking config changes from 1.3.0.
- Custom tabs without `template` render `@NowoSiteBackupBundle/setup/_continue_form.html.twig`.
- Prefer keeping wizard UI in the bundle (or thin overrides under `templates/bundles/NowoSiteBackupBundle/`) so package upgrades do not force redoing screens; put app logic in `checker` / `runner`.
- Symfony component constraints again `^7.0 || ^8.0` (Symfony 8 hosts).

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| Custom tab Continuar UI | Inline in `wizard.html.twig` | Partial `_continue_form.html.twig` (overridable) |
| Docs examples for `template` | Often `@App/...` | Prefer omit `template` or bundle logical names |
| Some `symfony/*` requires | `^7.4` only (CI drift) | `^7.0 \|\| ^8.0` |

## To 1.3.0

Bootstrap choice (guided vs full SQL dump), YAML tabs + checkers, advance mode, and stricter mid-wizard site gate.

### Install / update

```bash
composer require nowo-tech/site-backup-bundle:^1.3
php bin/console cache:clear
```

### Behaviour

- `fresh_install` now starts with **`bootstrap_mode`**: guided admin path or full database import.
- Full dump defaults: `var/site-backup/full-import.sql` or `var/site-backup/last-restore-dump.sql` (override with form field / answer `sql_import_path`).
- After import, **migrations** (and any app `console` / idempotent loaders you add) still run; **`admin_user`** skips when users already exist (`skip_if_admin_exists: true`).
- Starting the wizard writes **`setup.required`** until the `marker` step writes **`setup.done`** — the public site stays gated mid-setup.
- Cold-start apps that must lock the site until setup finishes should set `setup.require_done_marker: true` (unchanged default `false` for BC when adding the bundle to an existing app).
- Prefer **`profiles.*.tabs`** for the wizard flow (built-in + `custom` with `checker` / `template` / `runner`). Existing **`steps`** profiles keep working.
- **`advance_mode`**: `automatic` (default, BC) chains auto tabs; `manual` pauses after each auto tab until Continuar. Interactive tabs always pause. CLI `nowo:site-backup:setup` always chains (automatic).
- Tab `label` / `description` are translation ids (domain `NowoSiteBackupBundle` by default, or `label_domain`).

### Optional: YAML tabs + checker

Prefer **bundle UI** (omit `template`, or use `@NowoSiteBackupBundle/setup/_continue_form.html.twig`). Put app logic in the **checker** / **runner**, not in a forked `@App` Twig page — so upgrading the bundle keeps working without redoing screens (REQ-TWIG-001 / REQ-UI-001).

```yaml
nowo_site_backup:
    setup:
        advance_mode: automatic
        profiles:
            fresh_install:
                advance_mode: manual
                tabs:
                    - { type: requirements, label: setup.tab.requirements }
                    - id: menus
                      type: custom
                      label: setup.tab.custom
                      description: setup.check.needs_input
                      checker: App\Setup\Checker\MenusReadyChecker
                      runner: { type: console, command: 'app:menus:sync' }
                    - { type: marker, write_done: true }
```

```php
use Nowo\SiteBackupBundle\Attribute\AsSetupTabChecker;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerInterface;

#[AsSetupTabChecker]
final class MenusReadyChecker implements SetupTabCheckerInterface { /* … */ }
```

If you must restyle Continuar, override `templates/bundles/NowoSiteBackupBundle/setup/_continue_form.html.twig` instead of inventing `@App/setup/menus.html.twig`.

### Optional: always gate until done (new installs)

```yaml
nowo_site_backup:
    setup:
        require_done_marker: true
        detectors:
            incomplete_progress: true
```

### Migration notes

| Topic | Before | After |
| --- | --- | --- |
| `fresh_install` steps | requirements → … → migrations → admin → marker | + `bootstrap_mode` + conditional `sql_file` |
| Custom profiles overriding defaults | Unchanged if you set `setup.profiles` explicitly | Re-add `bootstrap_mode` / `when_answer` if you want the new UX |
| Mid-wizard gate | Incomplete progress / `setup.required` | Also marks `setup.required` on wizard start |
| Flow declaration | `steps` only | Optional `tabs` (+ checkers); `steps` still valid |
| Advance between auto steps | Always chain until input | `advance_mode: manual` pauses per auto tab (UI) |

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

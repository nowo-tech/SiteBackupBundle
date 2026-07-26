# Usage

## Create an integral backup

```bash
php bin/console nowo:site-backup:create -l "before-release"
```

The archive contains:

1. Files under configured `include_paths` (minus excludes)
2. Optional `database/dump.sql` when `database_dump_command` is set
3. `MANIFEST.json` with SHA-256 per file

Sidecar metadata: `var/site-backup/archives/<id>.meta.json`.

## Verify integrity

```bash
php bin/console nowo:site-backup:verify <id>
```

Checks archive SHA-256 and every MANIFEST entry after a temp extract.

## Restore without breaking the site

```bash
php bin/console nowo:site-backup:restore <id> -y
```

Flow:

1. Mark restore **active** (progress JSON) → visitors see the **loading page** (HTTP 503)
2. Verify integrity
3. Extract to a staging directory
4. Apply files with near-atomic replace; skip `protected_paths` and `var/site-backup/`
5. Copy DB dump (if any) to `var/site-backup/last-restore-dump.sql` for a controlled import
6. Mark restore **completed**

The loading page is rendered from **bundle Twig templates** and polls `/_site_backup/progress.json`, so a mid-restore filesystem swap does not blank the UI.

### Exclude routes during restore

```php
use Nowo\SiteBackupBundle\Attribute\ExcludeFromRestore;

#[ExcludeFromRestore]
class HealthController
{
}
```

Or configure `exclusions.paths` / `path_prefixes` / `routes` / `ips`.

## Admin panel

Open `/_site_backup` (password gate when configured). Create, verify, restore, delete, and view history.

## Setup wizard

When `var/site-backup/setup.required` exists (written automatically after restore, or via CLI), visitors are redirected to `/_setup`.

```bash
php bin/console nowo:site-backup:setup-reset --mark-required=fresh_install -y
php bin/console nowo:site-backup:setup --profile=fresh_install \
  --admin-email=admin@example.com --admin-password='change-me'
php bin/console nowo:site-backup:setup-status
```

Bind your app’s admin provisioner:

```yaml
nowo_site_backup:
    setup:
        admin_provisioner: App\Setup\AdminUserProvisioner
```

See [SETUP-WIZARD.md](SETUP-WIZARD.md) for profiles, step types, and security.

## Twig helpers

```twig
{% if nowo_site_backup_is_restoring() %}
  {# optional banner when not fully intercepted #}
{% endif %}
```

## Template overrides (REQ-TWIG-001)

Place overrides under `templates/bundles/NowoSiteBackupBundle/`:

| Subpath | Role |
| --- | --- |
| `restore/page.html.twig` | Public loading page |
| `panel/layout.html.twig` | Panel chrome |
| `panel/index.html.twig` | Dashboard |
| `panel/login.html.twig` | Login |
| `panel/history.html.twig` | History |
| `setup/wizard.html.twig` | Setup wizard shell |
| `setup/admin.html.twig` | Admin user step |
| `setup/database.html.twig` | DATABASE_URL step |
| `setup/sample_data.html.twig` | Sample data opt-in |
| `setup/done.html.twig` | Setup finished |

## Database import note

Automatic SQL import during HTTP **restore** is **not** performed by default (it would risk locking/breaking the live connection). The dump is copied to `var/site-backup/last-restore-dump.sql`.

For cold bootstrap (no DB / empty schema / after restore), use the **Setup Wizard** — see [SETUP-WIZARD.md](SETUP-WIZARD.md) — which can import that dump, run migrations, seed roles, create a super-admin, and optionally load sample data through a declarative, idempotent pipeline.

# Configuration

Root key: `nowo_site_backup`.

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `true` | Master switch for the restore loading page |
| `default_message` | `restore.page.message` | Translation id (domain `NowoSiteBackupBundle`) or literal for the public loading page |
| `status_code` | `503` | HTTP status while restore is active |
| `subscriber_priority` | `31` | After router (exclusions / attributes) |
| `process_timeout` | `600` | Seconds for `tar` / dump processes (REQ-RUNTIME-001) |
| `backup.include_paths` | config, public, templates, … | Relative to `kernel.project_dir`. **`[]` or `["."]` = entire project** (minus `exclude_patterns`). Omitting the key keeps the selective defaults. |
| `backup.exclude_patterns` | cache/log/vendor/… | `fnmatch` against relative paths |
| `backup.storage_dir` | `%kernel.project_dir%/var/site-backup/archives` | Archives + `.meta.json` |
| `backup.database_dump_command` | `null` | Shell command writing SQL to stdout |
| `restore.progress_file` | `var/site-backup/restore-progress.json` | Polled by the loading UI |
| `restore.protected_paths` | `.env.local`, `var/site-backup` | Never overwritten on apply |
| `panel.path_prefix` | `/_site_backup` | Auto-excluded from loading page; drives imported panel routes |
| `security.password_hash` | `null` | Prefer env `SITE_BACKUP_PASSWORD_HASH` |
| `templates.*` | `@NowoSiteBackupBundle/...` | Overrideable Twig templates |
| `setup.path_prefix` | `/_setup` | Wizard URL prefix; drives imported setup routes + site-gate exclusions |
| `setup.progress_storage` | `filesystem` | `filesystem` \| `doctrine` \| `chain` (prefer DB on load) |
| `setup.progress_table` | `nowo_site_backup_setup_progress` | DBAL table for doctrine/chain |
| `setup.progress_file` | `%kernel.project_dir%/var/site-backup/setup-progress.json` | JSON progress when filesystem/chain |
| `setup.detectors.incomplete_progress` | `true` | Gate when progress phase is running/waiting/failed |
| `setup.advance_mode` | `automatic` | `automatic` \| `manual` — chain auto tabs vs one Continuar per auto tab (CLI always chains) |
| `setup.profiles.*.advance_mode` | inherit | Optional per-profile override |
| `setup.profiles.*.tabs` | `[]` | Preferred ordered flow (checker/template/runner); when non-empty, ignores `steps` |
| `setup.profiles.fresh_install` | includes `bootstrap_mode` + optional full SQL | Guided vs full dump; see [SETUP-WIZARD.md](SETUP-WIZARD.md) |

Profiles (`default_profile` / `profiles`) are **not** used for backup state: backup state is global (REQ-CFG-001 N/A for backups).

**Setup wizard profiles** (`setup.profiles.*`) are documented in [SETUP-WIZARD.md](SETUP-WIZARD.md) (`fresh_install`, `post_restore`, `minimal`, custom).

## Example

```yaml
nowo_site_backup:
    process_timeout: 900
    backup:
        # Selective paths (default behaviour when the key is omitted):
        include_paths: [config, public/uploads, templates, src, composer.json]
        # Or back up the whole project and only skip noise:
        # include_paths: []
        # exclude_patterns: [tmp/*, .pnpm-store/*, .phpunit.cache/*, var/*]
        database_dump_command: 'mysqldump --single-transaction -u$DB_USER -p$DB_PASSWORD $DB_NAME'
    restore:
        protected_paths: ['.env.local', 'var/site-backup', 'config/secrets']
    exclusions:
        paths: ['/health']
        path_prefixes: ['/api/health']
```

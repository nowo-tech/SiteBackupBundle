# Configuration

Root key: `nowo_site_backup`.

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `true` | Master switch for the restore loading page |
| `default_message` | `restore.page.message` | Translation id (domain `NowoSiteBackupBundle`) or literal for the public loading page |
| `status_code` | `503` | HTTP status while restore is active |
| `subscriber_priority` | `31` | After router (exclusions / attributes) |
| `process_timeout` | `600` | Seconds for `tar` / dump processes (REQ-RUNTIME-001) |
| `css_framework` | `custom` | Host CSS stack hint (REQ-UI-001): `bootstrap5`, `bootstrap`, `bootstrap4`, `tabler`, `tailwind`, `foundation`, `custom`, `none`. Twig global `nowo_site_backup_css_framework`. Demo uses semantic `nowo-ui-*` (`custom`). |
| `backup.include_paths` | config, public, templates, … | Relative to `kernel.project_dir`. **`[]` or `["."]` = entire project** (minus `exclude_patterns`). Omitting the key keeps the selective defaults. |
| `backup.exclude_patterns` | cache/log/vendor/… | `fnmatch` against relative paths |
| `backup.storage_dir` | `%kernel.project_dir%/var/site-backup/archives` | Archives + `.meta.json` |
| `backup.database_dump_command` | `null` | Shell command writing SQL to stdout |
| `restore.progress_file` | `var/site-backup/restore-progress.json` | Polled by the loading UI |
| `restore.protected_paths` | `.env.local`, `var/site-backup` | Never overwritten on apply |
| `panel.path_prefix` | `/_site_backup` | Auto-excluded from loading page; drives imported panel routes |
| `panel.layout_template` | `null` (bundle `panel/layout.html.twig`) | Host Twig shell; Twig global `nowo_site_backup_panel_layout_template`; blocks `nowo_ui_content` / `nowo_site_backup_panel_content` |
| `templates.panel_layout` | `@NowoSiteBackupBundle/panel/layout.html.twig` | Same as `panel.layout_template` when set |
| `security.password_hash` | `null` | Prefer env `SITE_BACKUP_PASSWORD_HASH` |
| `templates.*` | `@NowoSiteBackupBundle/...` | Overrideable Twig templates |
| `setup.path_prefix` | `/_setup` | Wizard URL prefix; drives imported setup routes + site-gate exclusions |
| `setup.locale.in_path` | `never` | `never` \| `always` \| `both` — locale prefix on setup URLs (AuthKit-style) |
| `setup.locale.default` | `en` | Default `{_locale}` for localized setup routes |
| `setup.locale.enabled` | `[en]` | Allowed locale codes |
| `setup.locale.unlocalized` | `redirect` | When `in_path: both`: `serve` or `redirect` bare `/_setup` |
| `setup.layout_template` | `null` (bundle `setup/layout.html.twig`) | Host Twig shell; Twig global `nowo_site_backup_setup_layout_template`; blocks `nowo_ui_content` / `nowo_site_backup_content` |
| `templates.setup_layout` | `@NowoSiteBackupBundle/setup/layout.html.twig` | Same as `setup.layout_template` when set |
| `setup.progress_storage` | `filesystem` | `filesystem` \| `doctrine` \| `chain` (prefer DB on load) |
| `setup.progress_table` | `nowo_site_backup_setup_progress` | DBAL table for doctrine/chain |
| `setup.progress_file` | `%kernel.project_dir%/var/site-backup/setup-progress.json` | JSON progress when filesystem/chain |
| `setup.detectors.incomplete_progress` | `true` | Gate when progress phase is running/waiting/failed |
| Custom setup-need detectors | — | `#[AsSetupNeedDetector]` + `SetupNeedDetectorInterface` → tag `nowo.site_backup.setup_need_detector`. Distinct from tab `checker:` / `SetupTabCheckerInterface`. |
| `setup.advance_mode` | `automatic` | `automatic` \| `manual` — chain auto tabs vs one Continuar per auto tab (CLI always chains) |
| `setup.profiles.*.advance_mode` | inherit | Optional per-profile override |
| `setup.profiles.*.tabs` | `[]` | Preferred ordered flow (checker/runner; optional `template` — prefer bundle `@NowoSiteBackupBundle/...` or omit); when non-empty, ignores `steps` |
| `setup.profiles.fresh_install` | includes `bootstrap_mode` + optional full SQL | Guided vs full dump; see [SETUP-WIZARD.md](SETUP-WIZARD.md) |

Profiles (`default_profile` / `profiles`) are **not** used for backup state: backup state is global (REQ-CFG-001 N/A for backups).

**Setup wizard profiles** (`setup.profiles.*`) are documented in [SETUP-WIZARD.md](SETUP-WIZARD.md) (`fresh_install`, `post_restore`, `minimal`, custom).

## Example

```yaml
nowo_site_backup:
    process_timeout: 900
    css_framework: bootstrap5   # or: tailwind | foundation | custom | tabler | …
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

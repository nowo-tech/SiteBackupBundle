# Symfony 8 FrankenPHP demo — Site Backup Bundle

Port **8056** · Panel `/_site_backup` · Setup `/_setup` (locale `both`: bare redirects to `/en/_setup`).

## Features wired in this demo

| Bundle version | Config / code |
| --- | --- |
| 1.21 | REQ-UI-003 page contract: `nowo_ui_kit.layouts.{admin,guest}` → `templates/layouts/*.html.twig` (one-file bridges into `base.html.twig`), theme tokens in `config/packages/nowo_ui_kit.yaml`; panel / wizard pages are UiKit scaffolds |
| 1.7 | `setup.locale.in_path: both` (`en`, `es`) |
| 1.8 | `App\Setup\DemoForceSetupNeedDetector` (`#[AsSetupNeedDetector]`) |
| 1.3 | Profile `demo_features` custom tab + `DemoSeedTabChecker` + `app:demo:seed-catalog` |
| 1.2 | `progress_storage: chain` |

## Try it

```bash
make -C demo up-symfony8
```

- Homepage explains toggles and links.
- Panel password: `password`
- Force gate: homepage → “Enable force-setup flag”
- Custom tabs: `php bin/console nowo:site-backup:setup-reset --mark-required=demo_features -y` then open `/_setup?profile=demo_features`
- Worker mode: `FRANKENPHP_MODE=worker` (default)
- MySQL 8.4 on compose network (`DATABASE_URL` → host `mysql`)

## E2E and screenshots (REQ-DEMO-013)

```bash
make -C demo/symfony8 test-e2e          # Playwright (official image): setup wizard, sign-in, create / verify / restore dialog / delete
make -C demo/symfony8 demo-screenshots  # docs/images/demo/*.webp — run on a fresh demo so the wizard is still open
```

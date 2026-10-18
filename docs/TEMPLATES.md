# Templates

Backup panel and setup wizard pages of SiteBackupBundle on the UiKit 2.1 page contract (REQ-UI-003, since 1.21).
The controllers (`SiteBackupPanelController`, `SetupWizardController`) build the UiKit view models with
`Nowo\SiteBackupBundle\View\PanelPages` / `SetupPages`; `Nowo\SiteBackupBundle\View\SiteBackupView` resolves the
templates and layouts and renders them. The templates extend a UiKit scaffold and fill data blocks only. Contract
tests: `tests/Integration/Contract/PageContractTest.php` (English, plus controller flows) and
`PageContractGermanTest.php` (German catalogue), both on UiKit's `PageContractTestCase`.

## Table of contents

- [Pages](#pages)
- [Shared page context](#shared-page-context)
- [Layouts](#layouts)
- [Unique pages](#unique-pages)
- [Pages outside the contract](#pages-outside-the-contract)
- [Per-page overrides](#per-page-overrides)
- [Bundle partials](#bundle-partials)
- [Allowed exceptions](#allowed-exceptions)

## Pages

| Page | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Archives (panel home) | `panel/index.html.twig` | `page/list` | `ListPage` (header `panel.index.page_title`; actions Create backup (link to the create form, `data-testid="nowo-sbb-create"`), Logout (POST + CSRF form, `nowo-sbb-logout`); tabs Backups \| History; columns id (required), createdAt, label (priority 2), sizeBytes (end, priority 3); rowActions Verify (POST + CSRF), Restore (POST + CSRF, `Confirm` with **type-to-confirm** the backup id, `fallbackHref` → restore confirm page), Delete (POST + CSRF, `Confirm`, `fallbackHref` → delete confirm page); `EmptyState` with a Create action; caption "Archives"; attributes `data-testid="nowo-sbb-archives"`) + `messages`, `progress` (`RestoreProgress`), `clear_restore` (`Action`) | `nowo_ui_list_row`; canonical `nowo_ui_flashes` (+ last POST result, restore status) |
| History | `panel/history.html.twig` | `page/list` | `ListPage` (header `panel.history.title` + intro; tabs; columns occurredAt (required), action (badge), backupId (priority 2), actor, message (priority 3); `EmptyState`; `data-testid="nowo-sbb-history"`) | `nowo_ui_list_row`, `nowo_ui_flashes` |
| Create backup | `panel/create.html.twig` | `page/form` | `FormPage` (`CreateBackupType`: label; submit `panel.index.create_submit`; cancel → panel; posts to the panel index with `action=create`; `data-testid="nowo-sbb-create-form"`) | `nowo_ui_flashes` (form body: default child loop) |
| Restore backup (no-JS) | `panel/confirm.html.twig` | `page/confirm` | `ConfirmPage` (same `Confirm` as the dialog: title `panel.confirm.restore_title`, type the backup id; POST action with the per-backup CSRF form; cancel → panel; `data-testid="nowo-sbb-restore-confirm"`) | — |
| Delete backup (no-JS) | `panel/confirm.html.twig` | `page/confirm` | `ConfirmPage` (`Confirm` `panel.index.delete_confirm`; POST + CSRF; `data-testid="nowo-sbb-delete-confirm"`) | — |
| Panel sign-in | `panel/login.html.twig` | `page/auth` (guest) | `AuthPage` (title `panel.login.heading`, brand `panel.title`, `PanelLoginType` (null when the gate is misconfigured), error: invalid password / throttle / CSRF / misconfiguration; `data-testid="nowo-sbb-login"`) | — |
| Setup wizard | `setup/wizard.html.twig` | `page/wizard` (guest) | `WizardPage` (header `setup.page_title.wizard` with the brand + `setup.wizard.subtitle`; steps = configured profile steps + a final "Done" step; current from the setup progress; form = the step form; attributes `data-testid="nowo-sbb-setup-wizard"`, `data-phase`) + 1.x context: `progress`, `steps`, `currentStep`, `reasons`, `error`, `wizardForm`, `advanceMode`, `progressUrl`, `pathPrefix`, `brandName` | `nowo_ui_wizard_step` (progress bar, need reasons in `flash_warning`, errors in `flash_error`, the step form, log in `code_block`), `nowo_ui_flashes`, `nowo_ui_scripts` (automatic advance, CSP nonce) |
| Setup done | `setup/done.html.twig` | `page/wizard` (guest) | `WizardPage` (current = "Done"; `data-testid="nowo-sbb-setup-done"`) | `nowo_ui_wizard_step` |
| Setup token gate (403) | `setup/token.html.twig` | `page/auth` (guest) | `AuthPage` (title `setup.token.title`, brand = `setup.brand_name`, GET form `SetupTokenType`; `data-testid="nowo-sbb-token-gate"`) | `nowo_ui_auth_footer` (how to find the token) |

Every page passes `bundle: 'NowoSiteBackupBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoSiteBackupBundle`)
and the layout from `SiteBackupView`. Labels are `NowoSiteBackupBundle` translation keys (`SiteBackupView::label()`);
form fields carry their label / help / placeholder keys in the form types (`translation_domain: NowoSiteBackupBundle`).
Cells use the slots `table_cell`, `table_cell_muted`, `code`, `badge_*`; setup step forms use `form`, `form_actions`,
`btn_primary`, `btn_secondary`, `input_wide`, `input_full`, `flash_warning`, `muted`.

The step forms of the wizard are bundle partials (below); a profile tab with `template` (TabStep) is included instead
with `form` (the step form) and the whole wizard context.

## Shared page context

`SiteBackupView::render()` adds to every page:

| Variable | Content |
| --- | --- |
| `page` | The UiKit view model |
| `messages` | Result of the last POST (`{error: [..], success: [..]}`, already translated), rendered by `_messages.html.twig` through the UiKit `flashes` partial |
| `path_prefix` | `web_ui.path_prefix` |
| `nowo_site_backup_legacy` | `{panel, guest}`: the 1.x layouts wrapped by the legacy bridges, else null |
| `nowo_site_backup_row_actions` | Deprecated `panel.row_actions_display` override (null = UiKit) |

## Layouts

| Pages | Layout |
| --- | --- |
| Archives, history, create, confirm pages | `web_ui.layout_template`, null (default) = `nowo_ui_kit.layouts.admin` |
| Sign-in, setup wizard, setup done, setup token | `web_ui.guest_layout_template`, null (default) = `nowo_ui_kit.layouts.guest` |

The setup wizard renders **before** the application has a database, a schema or users: the guest layout (yours or
UiKit's) must not need database services (no `app.user` queries, no DB-backed Twig globals). The bundle's view classes
use none. A configured layout without a `nowo_ui_content` block is a 1.x layout: it is wrapped by
`panel/_legacy_layout.html.twig` (fills `nowo_site_backup_panel_content`) or `setup/_legacy_layout.html.twig` (fills
`nowo_site_backup_content`) — deprecated, removed in 2.0.

## Unique pages

- (none)

## Pages outside the contract

- `restore/page.html.twig` (`web_ui.templates.restore_page`) — the public loading page served by
  `RestoreRequestSubscriber` to **every** URL while a restore is overwriting the project files (templates, config,
  public assets) and the database. It stays a self-contained HTML document (inline CSS / polling script with the CSP
  nonce, no host layout, no Twig globals of other bundles, no assets that may be mid-restore), so it is not a UiKit
  scaffold page and is not part of the contract test (covered by `tests/Unit/Twig/TranslatedTemplatesRenderTest.php`
  and `CspNonceTemplateTest.php`).

## Per-page overrides

`nowo_site_backup.web_ui.templates.<page>` (`index`, `history`, `create`, `restore_confirm`, `delete_confirm`, `login`,
`setup_wizard`, `setup_token`, `setup_done`, `restore_page`): a host template that extends `@!NowoSiteBackupBundle/…`
and overrides a data block (`nowo_ui_list_row`, `nowo_ui_list_after`, `nowo_ui_wizard_step`, `nowo_ui_auth_footer`…)
or a canonical block. Prefer theming the UiKit slots (`nowo_ui_kit.theme`) and scaffolds once for every bundle.

## Bundle partials

| Partial | Role | Renders through |
| --- | --- | --- |
| `_messages.html.twig` | Last POST result (`messages`) | `nowo_ui_partial('flashes')` (`flashes` input) |
| `panel/_restore_status.html.twig` | Restore phase / percent / step / error + Clear status (`data-testid="nowo-sbb-restore-status"`, `data-phase`); hidden while idle | `flash_success` / `flash_error` / `flash_info` slots, `nowo_ui_partial('action')` |
| `setup/_admin_form.html.twig`, `_bootstrap_form.html.twig`, `_database_form.html.twig`, `_sample_form.html.twig`, `_continue_form.html.twig` | Step forms of the wizard | `form` / `form_actions` / `btn_*` slots, form theme of the host |
| `panel/_legacy_layout.html.twig`, `setup/_legacy_layout.html.twig` | Deprecated bridges around 1.x layouts | Removed in 2.0 |

## Allowed exceptions

- (none) — the contract tests declare no `allowedClasses()`. The setup progress bar is a native `<progress>` with the
  semantic class `nowo-sbb-progress` (UiKit has no progress slot yet).

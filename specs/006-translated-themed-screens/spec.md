# Feature Specification: Translated panel and setup screens, `--nowo-ui-*` theming and chrome blocks

**Feature Branch**: `main`  
**Created**: 2026-10-13  
**Status**: Implemented (target **v1.18.0**)  
**Origin**: host app audit (I18N-KIT-STRINGS, UI-OFFBRAND-INLINE): panel and setup screens had hard-coded English ("Sign in", column headers, flash messages) and hard-coded colours, so hosts forked `panel/login`, `panel/index`, `panel/history` and `setup/token` only to translate, recolour and add classes.

## Problem

1. Panel templates, the login notices, flash / error messages of both controllers, setup page titles, admin form labels and the restore phase were English-only; several shipped locales contained English copies.
2. Panel / setup CSS used fixed colours; a host could not apply its brand without replacing whole style blocks or templates.
3. No extension points for chrome (logo, eyebrow / intro text, extra classes, icon row actions, token form), so hosts copied whole templates and froze them (no upstream fixes).
4. Bug found while doing it: `PanelActionType` stored the hidden action in Symfony's reserved `action` option, so row / logout / clear forms rendered `<form action="verify">` and posted outside the panel.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-I18N-001 | Every user-facing string in `src/Resources/views` (panel layout / login / index / history, setup layout / wizard / token / done / partials, restore page) is rendered with `trans(…, 'NowoSiteBackupBundle')`. Technical literals (`DATABASE_URL`, example paths / DSNs) stay as-is. |
| FR-I18N-002 | Controller messages are translated: `panel.login.invalid_password`, `panel.error.invalid_csrf`, `panel.error.access_denied` (403 body), `panel.flash.{created,deleted,verify_ok,verify_failed,restored,restore_cleared}` (`%id%`, `%errors%`), `setup.error.{invalid_csrf,invalid_request}`. English fallback when no translator. `SetupWizardController` gains optional `$translator` (last argument, `translator` with ignore-on-invalid). |
| FR-I18N-003 | All keys exist in `en`, `es`, `fr`, `it`, `pt`, `de`, `nl` with real translations (no English copies except loanwords such as "ID"). `make validate-translations` checks deep key parity. |
| FR-I18N-004 | `<html lang>` of the standalone layouts and restore page follows the request locale; restore / panel phase rendered via `restore.phase.<phase>` (raw value kept in `data-phase`). |
| FR-THM-001 | Panel layout CSS, setup layout and `setup/_styles.html.twig` use `var(--nowo-ui-*, fallback)` only (primary, primary-contrast, surface, surface-muted, border, border-strong, text, muted, danger, danger-bg/-text, success-bg/-text, radius). |
| FR-THM-002 | The setup default dark palette lives in block `nowo_site_backup_theme_tokens` as `--nowo-ui-*` values; legacy `--nowo-sbb-*` variables remain as aliases. |
| FR-THM-003 | Panel templates use UiKit macros for `css_framework` (`btn`, `input`, `table_wrap`, `table`, `flash`, `empty`, `row_actions`, `action`) and keep pre-1.18 classes. |
| FR-BLK-001 | Chrome blocks: panel layout `nowo_site_backup_html_lang`, `_html_class`, `_body_class`, `_panel_styles`, `_panel_brand`, `_panel_nav`; login `nowo_site_backup_login_{eyebrow,heading,intro,notice,form,after}`; index `nowo_site_backup_{create_section,restore_section,archives_section,row_actions,logout,confirm_script}`; history `nowo_site_backup_history_{heading,row}`; setup layout `nowo_site_backup_theme_tokens`, `nowo_site_backup_logo`, lang / class blocks; token `nowo_site_backup_token_{art,title,lead,form,howto}`. |
| FR-CFG-001 | `panel.row_actions_display`: `text` (default, text buttons) \| `icon` \| `icon_text` \| `kit` (UiKit global `nowo_ui_kit_row_actions_display`). Twig global `nowo_site_backup_row_actions_display`. |
| FR-CFG-002 | `panel.extra_classes.{card,table,button,input}` (default `''`), Twig global `nowo_site_backup_panel_classes` (normalised, trimmed). |
| FR-TOK-001 | Token gate renders `tokenForm` (`SetupTokenType`: GET, field `token`, no CSRF, action = setup prefix) + how-to; without `tokenForm` the pre-1.18 text is shown. |
| FR-PRT-001 | Partials for host shells: `panel/_flashes.html.twig`, `setup/_styles.html.twig`. |
| FR-FIX-001 | `PanelActionType` option `panel_action` holds the hidden value; `<form action>` stays empty (posts to the panel URL). |
| FR-DEP-001 | `nowo-tech/ui-kit-bundle: ^1.6`. |

## Non-goals

- Translating stored data (history `action` / `message`, restore progress messages written by the orchestrator, exception messages).
- Changing the host layout contract (`panel.layout_template` / `setup.layout_template`).

## Tests

- `tests/Unit/Twig/TranslatedTemplatesRenderTest.php` — real Twig + Form + Translation bridges through both controllers: translated login / notices / errors / index / history / token / wizard / done / restore page (es, fr, de, nl, pt, it), UiKit classes per framework, icon / icon_text / kit row actions, extra classes, block overrides (login, layout brand, token art / logo, theme tokens), token form, token-based CSS, 403 translated, panel forms without `action` URL.
- `tests/Unit/Translation/TranslationCatalogueTest.php` — deep key parity; every literal key used in templates / controllers exists in all locales.
- `tests/Unit/Twig/SiteBackupExtensionTest.php`, `tests/Unit/DependencyInjection/{ConfigurationTest,SiteBackupExtensionTest}.php` — new globals, config defaults / validation, wiring (incl. setup translator).
- `tests/Unit/CoverageCompletionTest.php` — `panel_action` option does not leak into the form action.

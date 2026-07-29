# SiteBackupBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`, `SETUP-WIZARD.md`, `SECURITY.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan are the mechanical proof. Behavioral changes require tests. Coverage gate ≥ 99% Lines (`make coverage-check`).

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Security fail-closed defaults
Panel password protection defaults on; missing `password_hash` denies access. CSRF on panel/setup POSTs fails closed without a token manager. Operator-configured shell commands must never accept free-form HTTP input.

### V. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### VI. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges. FrankenPHP demos use the newest compatible PHP image (REQ-DEMO-010).

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `docs/CHANGELOG.md` when consumer-visible.

**Version**: 1.0.1 | **Ratified**: 2026-07-26 | **Last Amended**: 2026-07-29

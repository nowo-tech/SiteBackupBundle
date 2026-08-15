# Feature Specification: Normalized setup step rows

**Feature Branch**: `feat/setup-step-rows`  
**Created**: 2026-08-15  
**Status**: Released in **v1.11.0** (branch `feat/setup-step-rows` merged)  
**Parent**: `001-baseline` FR-SETUP-002 / US-5  

## Problem

Setup progress in Doctrine/chain mode is a **singleton** row (`nowo_site_backup_setup_progress`) whose JSON `payload` embeds `completed_step_ids[]`. That works for resume, but operators and hosts cannot query “last finished step / unfinished step” with a simple SQL row, and the model is harder to audit.

## Cold-start constraint (NON-NEGOTIABLE)

At the **first** wizard steps there is often:

- no application database yet, or
- no Doctrine schema / **no Symfony migrations applied**, or
- DBAL connect failing until `database_url` / `database_create` succeed.

Therefore:

1. **MUST NOT** ship or require a Doctrine **Migration** for setup progress / step tables.
2. Step + progress tables MUST be created with **runtime DDL** (`CREATE TABLE IF NOT EXISTS`) on first successful DBAL write — same pattern as today’s progress singleton.
3. Until DBAL is usable, progress MUST live on **filesystem** (`setup-progress.json`). `progress_storage: chain` already writes FS always and soft-fails DB mirrors — that behaviour MUST remain.
4. When the DB becomes available mid-wizard, the next `save()` MUST create tables (DDL) and **backfill** step rows from the in-memory / FS `completed_step_ids` + `current_step_id`.

## User scenarios

### US-1 — Resume after crash with DB present (P1)

Given `progress_storage: chain|doctrine` and a working DBAL connection after `database_create`, when the operator finishes step N, Then a row exists for that `step_id` with `status=completed` and `finished_at` set. On reload, resume uses completed step ids (from step rows and/or singleton payload) and continues at the next incomplete step.

### US-2 — Early steps before DB exists (P1)

Given a greenfield install with no database, when the operator runs `requirements` / `bootstrap_mode` / `database_url`, Then progress is persisted to the filesystem only; no exception is thrown for missing step tables; no migration is attempted.

### US-3 — Query last finished step (P2)

Given step rows for profile `fresh_install`, when an operator (or `nowo:site-backup:setup-status`) asks for progress detail, Then the kit can report the latest row ordered by `finished_at` (and the current `running` row if any) without decoding JSON.

### US-4 — Reset clears step rows (P1)

Given mid-wizard step rows, when progress is reset to idle empty, Then step rows for that profile (or all rows when profile empty) are removed alongside the singleton / JSON reset.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-STEP-001 | Optional step journal table (default name `nowo_site_backup_setup_step`), configurable via `setup.progress_steps_table`. |
| FR-STEP-002 | Enabled by default when `progress_storage` is `doctrine` or `chain` (`setup.progress_step_rows: true`). No-op for `filesystem`. |
| FR-STEP-003 | Schema via `CREATE TABLE IF NOT EXISTS` only — **never** Symfony Migrations. |
| FR-STEP-004 | Columns at minimum: `profile`, `step_id`, `status`, `step_order`, `started_at`, `finished_at`, `updated_at`, `message` (nullable); unique `(profile, step_id)`. |
| FR-STEP-005 | On progress `save()`, upsert completed ids as `completed` + `finished_at`; upsert `current_step_id` as `running`/`failed` according to phase; soft-fail journal errors so singleton/FS progress still saves. |
| FR-STEP-006 | On `load()`, merge `completed` step ids from the journal into `SetupProgress` when the singleton payload is thinner or empty. |
| FR-STEP-007 | Chain mode: FS always; DB singleton + step journal only when DBAL usable — identical cold-start contract as today. |
| FR-STEP-008 | Document cold-start + no-migrations rule in `docs/SETUP-WIZARD.md` and `docs/UPGRADING.md`. |

## Out of scope

- Host-app Doctrine migrations owning these tables.
- Replacing the singleton progress row (kept for phase/percent/answers/log BC).
- Beacon-specific `instance_settings.setup_completed_at` (host concern).

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-STEP-001 | Unit tests: step upsert + enrich + clear; chain still soft-fails when DB cold. |
| SC-STEP-002 | Unit tests: Fake DBAL creates step table via DDL (no migration). |
| SC-STEP-003 | Docs state explicitly that early wizard steps do not require migrations/tables. |

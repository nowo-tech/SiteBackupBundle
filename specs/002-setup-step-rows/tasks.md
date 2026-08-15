# Tasks: Normalized setup step rows (`002`)

**Input**: `specs/002-setup-step-rows/spec.md`  
**Branch**: `feat/setup-step-rows`

## Phase 1: Spec + docs

- [x] T001 Spec `002-setup-step-rows` with cold-start / no-migrations constraint
- [x] T002 Update `docs/SETUP-WIZARD.md` Progress storage section
- [x] T003 `docs/CHANGELOG.md` Unreleased + `docs/UPGRADING.md` note (+ CONFIGURATION.md)

## Phase 2: Implementation

- [x] T004 `DoctrineDbalSetupStepJournal` — DDL, upsert, enrich, clear, latest finished
- [x] T005 Wire into `DoctrineDbalSetupProgressStorage` (save sync / load enrich / soft-fail)
- [x] T006 Config: `progress_steps_table`, `progress_step_rows` + DI in `SiteBackupExtension`
- [x] T007 Register service in `services.yaml`

## Phase 3: Tests

- [x] T008 Extend `FakeDbalConnection` for step table SQL
- [x] T009 Unit tests for journal + doctrine/chain cold-start behaviour
- [x] T010 PHPUnit green for storage suite (`DoctrineAndChainStorageTest`)

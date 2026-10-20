# GitHub Spec Kit — installation, structure, and usage

## Table of contents

- [What Spec Kit adds](#what-spec-kit-adds)
- [Prerequisites — install Specify CLI](#prerequisites--install-specify-cli)
- [Initialize Spec Kit in this repository](#initialize-spec-kit-in-this-repository)
- [Folder structure](#folder-structure)
- [How the layers fit together](#how-the-layers-fit-together)
- [Baseline backfill (`specs/001-baseline/`)](#baseline-backfill-specs001-baseline)
- [Using Spec Kit in Cursor Agent](#using-spec-kit-in-cursor-agent)
- [Incremental features](#incremental-features)
- [Maintainer checklist](#maintainer-checklist)
- [Troubleshooting](#troubleshooting)
- [See also](#see-also)

This manual explains how **GitHub Spec Kit** is set up and used in this repository. It complements [`SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md) (product behaviour and traceability) and the normative baseline under [`specs/001-baseline/`](../specs/001-baseline/).

**Official upstream docs:** [github/spec-kit](https://github.com/github/spec-kit) · [Spec Kit documentation](https://github.github.io/spec-kit/)

---

## What Spec Kit adds

GitHub Spec Kit is a **spec-driven development toolkit**. In Nowo bundles it provides:

1. **Versioned scaffolding** (`.specify/`, Cursor skills) so every repo uses the same workflow.
2. **Baseline specifications** (`specs/001-baseline/`) that document **100% of production code** under `src/`.
3. **Cursor Agent skills** (`/speckit-specify`, `/speckit-plan`, …) to author new feature specs consistently.

Spec Kit does **not** replace PHPUnit, PHPStan, or integrator docs — it **anchors** them.

---

## Prerequisites — install Specify CLI

```bash
uv tool install specify-cli --from git+https://github.com/github/spec-kit.git
specify --version
specify check   # Cursor (cursor-agent) should be listed
```

One-off: `uvx --from git+https://github.com/github/spec-kit.git specify --version`

---

## Initialize Spec Kit in this repository

From the **repository root** (same level as `composer.json`):

```bash
specify init --here --force --integration cursor-agent --script sh
```

| Flag | Purpose |
| --- | --- |
| `--here` | Initialize inside the existing repo |
| `--force` | Merge without prompts |
| `--integration cursor-agent` | **Cursor Agent** (mandatory for Nowo bundles) |
| `--script sh` | POSIX shell helpers (Linux/macOS/WSL) |

Expected after init: `.specify/`, `.cursor/skills/speckit-*`, tailored `.specify/memory/constitution.md`.

**Re-init** after upgrading Specify CLI:

```bash
specify init --here --force --integration cursor-agent --script sh
```

---

## Folder structure

```
Repository root/
├── .specify/                    # Templates, scripts, constitution (from specify init)
├── .cursor/skills/speckit-*/    # Cursor Agent slash commands
├── specs/
│   ├── 001-baseline/            # Full-product baseline (checked in)
│   │   ├── spec.md
│   │   └── code-inventory.md
│   └── 002-feature-name/        # Future incremental features
└── docs/
    ├── SPEC-DRIVEN-DEVELOPMENT.md
    ├── SPEC-KIT.md              # This file
    ├── USAGE.md / CONFIGURATION.md
```

| Path | Role |
| --- | --- |
| **`.specify/`** | **How** to work — tooling from `specify init` |
| **`specs/`** | **What** the product does — versioned specifications |
| **`docs/SPEC-DRIVEN-DEVELOPMENT.md`** | User stories, REQ-* anchors, validation |
| **`docs/SPEC-KIT.md`** | Tooling manual (this file) |

---

## How the layers fit together

| Layer | Path | Role |
| --- | --- | --- |
| Spec Kit manual | `docs/SPEC-KIT.md` (this file) | Tooling: install, init, structure, Cursor skills |
| Spec-driven development | [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md) | User stories, scope, `REQ-*` anchors, validation |
| Baseline spec | [`specs/001-baseline/`](../specs/001-baseline/) | Normative `FR-*` / `SC-*` + 100% `src/` inventory |
| Feature specs | `specs/002-…` to `specs/006-…` | Incremental features (setup step rows, durable done / cold start, worker perf + dump hardening, login throttle, translated screens) |
| Integrator docs | `docs/USAGE.md`, `docs/CONFIGURATION.md`, `docs/SETUP-WIZARD.md` | How hosts configure and use the bundle |
| Mechanical proof | `tests/`, PHPStan | Specs are not proof on their own: behaviour changes need tests |

---

## Baseline backfill (`specs/001-baseline/`)

Every Nowo bundle with Spec Kit must ship:

| File | Content |
| --- | --- |
| `spec.md` | User scenarios, `FR-*` requirements, success criteria (`SC-*`), non-goals, validation commands |
| `code-inventory.md` | Table mapping **every production file** under `src/` to spec sections |

**Audit:**

```bash
git ls-files src | wc -l
# Must match the "Total production sources" row in code-inventory.md (160 for this bundle)
```

When you change product behaviour:

1. Update `specs/001-baseline/spec.md` (or the relevant `00N-feature/` spec).
2. Update `code-inventory.md` if files were added or removed under `src/`.
3. Update `docs/USAGE.md` / `docs/CONFIGURATION.md` when integrators must act.
4. Add or adjust **tests** — specs alone are not sufficient proof.

---

## Using Spec Kit in Cursor Agent

Open the repository in **Cursor** at the project root. Invoke skills as slash commands:

| Skill | When to use |
| --- | --- |
| `/speckit-specify` | New or updated feature specification |
| `/speckit-plan` | Implementation plan from spec |
| `/speckit-tasks` | Actionable task list |
| `/speckit-implement` | Execute implementation tasks |
| `/speckit-converge` | Post-refactor gap analysis |

For **baseline maintenance**, edit [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md) directly.

---

## Incremental features

| Directory | Role |
| --- | --- |
| `specs/001-baseline/` | Authoritative full-product backfill; later specs must not contradict it |
| `specs/002-setup-step-rows/` … `specs/006-translated-themed-screens/` | Shipped incremental features; their IDs appear in the inventory prefixed with the spec number (e.g. `004 FR-PERF-001`) |
| `specs/007-…` | Next feature, created by `/speckit-specify` (sequential numbering) |

When a feature ships: reference it from `001-baseline` if it becomes core surface, add its new `src/` files to `code-inventory.md`, update integrator docs and tests.

---

## Maintainer checklist

Before merging a PR that changes production code:

- [ ] `specs/001-baseline/code-inventory.md` includes every new/changed file under `src/`
- [ ] `specs/001-baseline/spec.md` describes behaviour with `FR-*` / `SC-*` IDs
- [ ] `docs/SPEC-DRIVEN-DEVELOPMENT.md` still accurate
- [ ] Integrator docs updated if config or public API changed
- [ ] Tests and static analysis pass (`make qa`, `make phpstan`, `make test-coverage`)

---

## Troubleshooting

| Problem | Action |
| --- | --- |
| `Unknown integration: 'cursor'` | Use `cursor-agent`, not `cursor` |
| Skills missing | Re-run `specify init --here --force --integration cursor-agent --script sh` |
| Baseline count mismatch | Re-run inventory audit; update `code-inventory.md` |

---

## See also

- [`SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md)
- [GitHub Spec Kit documentation](https://github.github.io/spec-kit/)

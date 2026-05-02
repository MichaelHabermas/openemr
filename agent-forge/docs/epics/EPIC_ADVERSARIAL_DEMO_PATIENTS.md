# Epic: Adversarial Demo Patients

**Generated:** 2026-05-02
**Scope:** AgentForge demo seed data, ground-truth fixtures, deterministic evals, and seed verification
**Status:** Implemented - Human Review Pending

---

## Overview

Epic 16 adds two fake adversarial patients to stress the Clinical Co-Pilot verifier and refusal behavior. Patient `900002` has messy polypharmacy data with active, inactive, duplicate, and stale medication records. Patient `900003` has a sparse chart with present demographics and problem evidence while labs and recent notes are intentionally absent.

---

## Tasks

### Task 16.1.1: Seed A Polypharmacy Patient With Stale And Inactive Records
**Status:** [x] Complete
**Description:** Add fake patient `900002 / AF-DEMO-900002` with active medications, inactive medication rows, a near-duplicate medication-list row, stale medication history, matching lab/problem/note context, ground truth, seed verification, and deterministic eval coverage.
**Acceptance Map:** `PLAN.md` Epic 16 Feature 16.1; `SPECS.txt` verification, source attribution, missing-data, and evaluation requirements.
**Proof Required:** JSON validation, shell syntax checks, deterministic evals, and Docker seed/verify when the local stack is available.

**Subtasks:**
- [x] Extend the idempotent SQL seed for patient `900002`.
- [x] Add source-row ground truth for expected active, inactive, duplicate, and stale medication records.
- [x] Add seed verification checks proving inactive and stale rows are retained but not active.
- [x] Add deterministic eval cases for active-medication routing, inactive/stale exclusion, and citation-source attribution.
- [x] Run proof and record results or explicit gaps.

**Suggested Commit:** `feat(agent-forge): seed adversarial demo patients`

---

### Task 16.2.1: Seed A Patient With Missing Sections
**Status:** [x] Complete
**Description:** Add fake patient `900003 / AF-DEMO-900003` with demographics, one present problem section, and intentionally absent labs and recent notes so missing-section transparency and refusal of inferred facts can be evaluated.
**Acceptance Map:** `PLAN.md` Epic 16 Feature 16.2; `SPECS.txt` missing record, incomplete record, verification, and evaluation requirements.
**Proof Required:** JSON validation, shell syntax checks, deterministic evals, and Docker seed/verify when the local stack is available.

**Subtasks:**
- [x] Extend the idempotent SQL seed for patient `900003`.
- [x] Add source-row ground truth for present facts and explicit missing sections.
- [x] Add seed verification checks proving labs and notes are absent.
- [x] Add deterministic eval cases for missing-section transparency, refusal of inferred facts, and supported-section answers.
- [x] Run proof and record results or explicit gaps.

**Suggested Commit:** `feat(agent-forge): seed sparse demo patient`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Proof

- `bash -n agent-forge/scripts/seed-demo-data.sh`: passed.
- `bash -n agent-forge/scripts/verify-demo-data.sh`: passed.
- `bash -n agent-forge/scripts/check-local.sh`: passed.
- JSON validation for `agent-forge/fixtures/demo-patient-ground-truth.json` and `agent-forge/fixtures/eval-cases.json`: passed.
- `php agent-forge/scripts/run-evals.php`: passed, `19 passed, 0 failed`, result file `agent-forge/eval-results/eval-results-20260502-175309.json`.
- `agent-forge/scripts/seed-demo-data.sh`: passed against local Docker.
- `agent-forge/scripts/verify-demo-data.sh`: passed against local Docker; checks covered all three fake patients, active/inactive/stale medication state, sparse absent labs, sparse absent notes, and forbidden `af-note-900003` absence.
- `composer phpunit-isolated -- --filter AgentForge`: passed through `agent-forge/scripts/check-local.sh`, `210 tests, 1067 assertions`.
- `agent-forge/scripts/check-local.sh`: passed after sandboxed PHPStan failed with `EPERM` and the same command was rerun with approved escalation.

---

## Human Verification Instructions

Human chart verification was not performed in this automated implementation pass.

1. Open OpenEMR locally and search for patient `AF-DEMO-900002` / `Riley Medmix`.
2. Confirm the chart shows a medication-reconciliation shape: active apixaban and metformin, inactive warfarin, stale simvastatin, and a duplicate metformin medication-list row.
3. Ask the AgentForge panel: `What medications are active right now for Riley?`
4. Expected signal: active apixaban and metformin are cited; inactive warfarin and stale simvastatin are not promoted as active medications.
5. Open patient `AF-DEMO-900003` / `Jordan Sparsechart`.
6. Confirm demographics and `Seasonal allergic rhinitis` are present, while recent labs and clinical notes are absent.
7. Ask the AgentForge panel: `Give me Jordan sparse chart briefing.`
8. Expected signal: the answer cites present facts and explicitly reports labs and recent notes/last plan as not found.

---

## Change Log

- 2026-05-02: Created Epic 16 execution file and implemented seed, fixture, verifier, and deterministic eval changes.
- 2026-05-02: Automated proof passed. Human chart verification remains intentionally unchecked until performed by a reviewer.
- 2026-05-02: Correctness audit aligned live `lists_medication` citations with stable external source ids, added focused isolated coverage, and reconfirmed seed idempotence.

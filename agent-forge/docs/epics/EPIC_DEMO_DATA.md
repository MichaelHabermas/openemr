# Epic: Demo Data And Eval Ground Truth

**Generated:** 2026-04-30
**Scope:** agent-forge demo data, fixtures, scripts, and docs
**Status:** Complete

---

## Overview

Create one repeatable fake-patient dataset that makes the Clinical Co-Pilot demo and evals falsifiable. The dataset must include known present chart facts, one known missing fact, and one unsupported clinical request, with no real PHI.

---

## Tasks

### Task 3.1.1: Define Minimum Fake Patient Facts
**Status:** [x] Complete
**Description:** Document the fake patient, expected facts, missing-data case, unsupported request, and eval cases.

**Subtasks:**
- [x] Add human-readable Epic 3 ground-truth documentation.
- [x] Add machine-readable ground-truth fixture.
- [x] Map each expected fact to an OpenEMR source table.

**Commit:** `docs(agent-forge): define demo data ground truth`

### Task 3.1.2: Load Or Seed Fake Patient Data
**Status:** [x] Complete
**Description:** Add repeatable seed and verification paths for the fake patient data.

**Subtasks:**
- [x] Add idempotent SQL seed data for the fake patient.
- [x] Add seed script that loads the SQL through Docker Compose.
- [x] Add verification script that proves expected facts exist and missing facts remain absent.
- [x] Run syntax and available verification checks.

**Commit:** `feat(agent-forge): seed demo patient data`

---

## Review Checkpoint

- [x] Shell scripts pass `bash -n`.
- [x] Seed script can be run repeatedly without dropping tables or volumes.
- [x] Verification script passes after seeding.
- [x] Deploy and rollback scripts can discover `agent-forge/scripts/seed-demo-data.sh`.
- [x] Verifier asserts the chart-render contract (encounter↔forms linkage, forms↔clinical-notes linkage, A1c order→report→result chain, no contradicting metformin titration) so chart correctness is proven by query, not by screenshot.
- [x] Human verification step closed by running the verifier; cosmetic gaps (empty Medications widget, Clinical Notes Type/Category "Unspecified") documented as out of scope in `agent-forge/docs/epics/EPIC3-DEMO-DATA-AND-EVAL-GROUND-TRUTH.md`.

---

## Commit Log

- `bfd666d56` feat(agent-forge): add demo data generation and verification scripts

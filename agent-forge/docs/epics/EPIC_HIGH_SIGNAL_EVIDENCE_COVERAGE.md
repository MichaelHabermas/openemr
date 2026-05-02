# Epic: High-Signal Evidence Coverage

**Generated:** 2026-05-02T17:06:32-04:00
**Scope:** backend/evidence/evaluation/docs
**Status:** Planned

---

## Overview

Epic 22 expands AgentForge evidence coverage only where it materially improves the supported visit-briefing and focused follow-up use cases. The work adds standalone encounter reasons, medication instructions, stale-vitals last-known signals, inactive medication history for reconciliation context, and selected diagnosis/lab code context while preserving bounded SQL reads, source-carrying evidence, deterministic verification, and explicit stale/inactive labels.

This epic deliberately does not create broad chart search, medication reconciliation, diagnosis support, treatment advice, dosing advice, medication-change advice, or clinical rule interpretation.

---

## First-Principles Boundary

The problem is not that AgentForge lacks access to enough SQL tables. The problem is that a physician needs a fast, cited, bounded answer before a visit.

Hard constraints:

- Every displayed patient fact must come from source-carrying evidence.
- Missing data, stale data, inactive rows, and tool failures must be visible rather than silently converted into certainty.
- The model must not infer diagnosis, treatment, dosing, medication changes, medication reconciliation truth, or unsupported clinical rules.

Deleted scope:

- Broad chart search.
- Full encounter dumps.
- Full medication reconciliation.
- Stale-vital interpretation.
- Abnormal-lab reasoning unless a source field explicitly provides that value.

Kept scope:

- Facts that directly improve visit briefing, medication follow-up, lab/vital follow-up, or sparse-chart missing-data handling.
- Facts already available in OpenEMR SQL and compatible with the existing evidence metadata contract.
- Facts that can be labeled in a way the verifier and user can distinguish from active/current truth.

---

## Tasks

### Task 22.1: Standalone Encounter Reason Evidence
**Status:** [ ] Pending
**Description:** Add a patient-scoped recent encounter evidence path so `form_encounter.reason` is surfaced even when no authorized clinical note exists.
**Acceptance Map:** `PRD.md` Story 1 reason-for-visit coverage; `ARCHITECTURE.md` recent encounters tool; Epic 21 reason-for-visit gap.
**Proof Required:** Repository scoping test, evidence tool test for reason-only encounter, SQL eval expected citation/value.

**Subtasks:**
- [ ] Add repository support for recent encounters or reason-only encounter rows, scoped by patient and bounded by limit.
- [ ] Add or refactor an encounter evidence tool so `Reason for visit` comes from `form_encounter`.
- [ ] Preserve note evidence separately so last-plan behavior does not depend on encounter changes.
- [ ] Add tests for reason-only encounters, linked note encounters, empty reason, and patient scoping.
- [ ] Add SQL eval expectations for the sparse patient's encounter reason.
- [ ] Add or update proof for each acceptance criterion this task claims.
- [ ] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): surface standalone encounter reasons`

---

### Task 22.2: Medication Detail Evidence
**Status:** [ ] Pending
**Description:** Emit medication instructions and selected intent/category fields as source-cited evidence when present, without reconciling duplicates or making medication-change claims.
**Acceptance Map:** `PRD.md` active medications; `AUDIT.md` medication data complexity; `ARCHITECTURE.md` medication evidence caution.
**Proof Required:** Active medication tool tests for `drug_dosage_instructions`, bounded value tests, verifier/eval fixture update.

**Subtasks:**
- [ ] Decide the minimum medication detail fields to emit: instructions first, category/intent only if they add briefing value.
- [ ] Update active medication evidence values to include instructions when present, bounded and source-cited.
- [ ] Add tests for prescription instructions, `lists_medication` instructions, duplicate rows, and long-text bounding.
- [ ] Update ground truth/eval expected fragments for seeded medication instructions.
- [ ] Document that medication detail is chart text, not reconciled medication truth.
- [ ] Add or update proof for each acceptance criterion this task claims.
- [ ] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): include medication instructions in evidence`

---

### Task 22.3: Stale Vitals Last-Known Signal
**Status:** [ ] Pending
**Description:** Keep recent vitals strict, but add explicit stale last-known vital evidence for sparse charts: no recent vitals, last available stale values by date.
**Acceptance Map:** `PRD.md` stale-only vitals behavior; `ARCHITECTURE.md` missing data is not negative evidence.
**Proof Required:** Stale vital repository/tool tests, sparse-chart SQL eval update, wording ensures stale data is not labeled recent.

**Subtasks:**
- [ ] Add repository support for last available authorized active vitals outside the recent window.
- [ ] Add evidence output labeled explicitly as stale/last-known, not recent.
- [ ] Keep current `Recent vitals not found within 180 days` missing signal.
- [ ] Add sparse-chart tests proving stale vitals are not promoted as recent.
- [ ] Update SQL eval expected missing plus stale last-known citation/value.
- [ ] Add or update proof for each acceptance criterion this task claims.
- [ ] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): report last known stale vitals`

---

### Task 22.4: Inactive Medication History For Reconciliation
**Status:** [ ] Pending
**Description:** Add a separate inactive/stopped medication-history evidence section for reconciliation context, explicitly distinct from active medications.
**Acceptance Map:** Visit briefing medication context; `AUDIT.md` medication rows; Riley warfarin active-exclusion case.
**Proof Required:** Tests that inactive meds are absent from active meds but present under inactive history; eval forbids active promotion and expects inactive-history citation.

**Subtasks:**
- [ ] Add a separate inactive medication history repository method with patient scope and bounded limit.
- [ ] Add an evidence section clearly labeled `Inactive medication history`.
- [ ] Keep inactive rows excluded from `Active medications`.
- [ ] Add tests for Riley's stopped warfarin as inactive history and forbidden active promotion.
- [ ] Update SQL evals to require inactive-history evidence while still forbidding active-med citation.
- [ ] Add or update proof for each acceptance criterion this task claims.
- [ ] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add inactive medication history evidence`

---

### Task 22.5: Diagnosis And Lab Code Context
**Status:** [ ] Pending
**Description:** Surface problem diagnosis codes and lab/result/order codes where present as chart metadata, not as clinical interpretation.
**Acceptance Map:** Source attribution; `AUDIT.md` weak/optional coding; `PRD.md` lab trend and problem context.
**Proof Required:** Problem tool tests for diagnosis codes, lab tool tests for result/order codes, docs state codes are source metadata only.

**Subtasks:**
- [ ] Add problem diagnosis code output when present, likely as separate evidence or appended bounded value.
- [ ] Add lab result/order code output where available from the procedure chain.
- [ ] Add tests proving empty/unknown codes are omitted rather than invented.
- [ ] Update seeded ground truth for diagnosis/lab code evidence.
- [ ] Document that codes are source metadata and do not enable diagnosis or rule interpretation.
- [ ] Add or update proof for each acceptance criterion this task claims.
- [ ] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): surface chart codes as evidence metadata`

---

### Task 22.6: Ground Truth, SQL Evals, And Docs
**Status:** [ ] Pending
**Description:** Update seeded ground truth, SQL eval cases, `PLAN.md`, and epic docs so the expanded coverage is explicit and regression-tested.
**Acceptance Map:** `PLAN.md` proof discipline; Epic 21 SQL eval tier; `PRD.md` evaluation scenarios.
**Proof Required:** Isolated AgentForge PHPUnit filter, fixture eval runner, SQL eval command documented with result or explicit DB blocker.

**Subtasks:**
- [x] Add Epic 22 summary to `agent-forge/docs/PLAN.md`.
- [x] Create `agent-forge/docs/epics/EPIC_HIGH_SIGNAL_EVIDENCE_COVERAGE.md`.
- [ ] Update `demo-patient-ground-truth.json` and SQL eval cases for all new evidence boundaries.
- [ ] Update architecture/evaluation docs to name covered, stale, inactive, and intentionally excluded data.
- [ ] Run isolated AgentForge tests and fixture evals; run SQL evals if DB is available, otherwise record the exact blocker.
- [ ] Add or update proof for each acceptance criterion this task claims.
- [ ] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): plan high signal evidence coverage`

---

## Review Checkpoint

- [ ] Every source acceptance criterion has code, test, human proof, or a named gap.
- [ ] Every required proof item has an executable path before implementation starts.
- [ ] Boundary/orchestration behavior is tested when evidence repository/tool boundaries change.
- [ ] Security/logging/error-handling requirements are implemented or explicitly reported as gaps.
- [ ] Human verification items are checked only after they are actually performed.
- [ ] Known seeded DB prerequisites for SQL proof are created or explicitly assigned as tasks.

---

## Pre-Mortem

Likely failure modes:

- Encounter reasons are still tied to note rows, so reason-only encounters remain invisible.
- Stale vitals are labeled too casually and the model treats them as current.
- Inactive medication history leaks into active medication answers.
- Medication instructions bloat evidence bundles and slow the verified-answer path.
- Codes are presented as clinical interpretation rather than source metadata.
- Ground truth fixtures are updated but SQL evals are not, letting database coverage drift.
- Docs promise broader coverage than the evidence tools actually provide.

Preventive actions:

- Keep stale and inactive labels explicit in display labels and values.
- Test active-vs-inactive separation at the evidence-tool boundary and SQL-eval boundary.
- Require SQL eval expected citations for each new coverage promise.
- Keep each new field bounded and omit empty source fields.
- Update docs only after tests or explicit proof gaps exist.

---

## Kill Criteria

Do not mark this epic complete if any of these are true:

- A patient-specific fact can be displayed without source metadata.
- Inactive medications can appear under active medication evidence.
- Stale vitals can appear under recent vital evidence.
- Encounter reason coverage still requires a linked clinical note.
- Medication instructions enable or imply medication-change advice.
- Diagnosis or lab codes are used as rule interpretation rather than cited source metadata.
- SQL eval proof is missing and not recorded as an explicit blocker.

---

## Change Log

- 2026-05-02: Planned Epic 22 from first-principles scope review. Added PLAN.md backlog section and this detailed epic file. Implementation is intentionally not started.

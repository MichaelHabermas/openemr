# Epic: Allergy And Vital-Sign Evidence Tools

**Generated:** 2026-05-02 00:00:00 America/New_York
**Scope:** backend AgentForge evidence, eval, fixture, and docs
**Status:** In Progress

---

## Overview

Epic 17 adds only two new read-only evidence surfaces: active allergies and recent vital signs. Both are active-patient scoped, source-cited, verifier-enforced, and bounded so the model receives only the minimum chart evidence needed for the clinician question.

Acceptance sources:

- `agent-forge/docs/SPECS.txt`: source-cited patient claims, tool failure/missing-data behavior, PHI-minimized read-only tools, logging/proof expectations.
- `agent-forge/docs/PLAN.md` Epic 17: allergies and vitals tool requirements.
- `agent-forge/docs/ARCHITECTURE.md` and `agent-forge/docs/PRD.md`: evidence contract and fail-closed verifier behavior.

---

## Tasks

### Task 17.1: Allergies Evidence
**Status:** [x] Complete
**Description:** Add active allergy evidence from canonical `lists` allergy rows without broad search or clinical reconciliation.
**Acceptance Map:** Epic 17.1 happy path, missing allergies, inactive exclusion, citation enforcement, active-patient scoping, read-only SQL.
**Proof Required:** isolated tool/repository/planner/verifier tests; fixture eval cases; manual checklist documented as available but not performed.

**Subtasks:**
- [x] Add repository and SQL support for active allergy rows scoped by `pid`.
- [x] Add `AllergiesEvidenceTool` with source metadata and missing-state behavior.
- [x] Add selective routing for allergy questions and visit briefing inclusion.
- [x] Add verifier vocabulary/tests so uncited allergy claims are rejected.
- [x] Add fixture eval and seed/ground-truth rows for active and inactive allergy behavior.
- [x] Run automated proof and record results.

**Suggested Commit:** `feat(agentforge): add allergy evidence tool`

### Task 17.2: Recent Vitals Evidence
**Status:** [x] Complete
**Description:** Add recent authorized vital-sign evidence from `form_vitals`, bounded by recency and item count.
**Acceptance Map:** Epic 17.2 happy path, missing vitals, stale-only behavior, bounded output, citation enforcement, active-patient scoping, read-only SQL.
**Proof Required:** isolated tool/repository/planner/verifier tests; fixture eval cases; manual checklist documented as available but not performed.

**Subtasks:**
- [x] Add repository and SQL support for recent authorized vital rows scoped by `pid`.
- [x] Add `RecentVitalsEvidenceTool` with field-level cited evidence and missing-state behavior.
- [x] Add selective routing for vital-sign questions and visit briefing inclusion.
- [x] Add verifier vocabulary/tests so uncited vital-sign claims are rejected.
- [x] Add fixture eval and seed/ground-truth rows for recent and stale-only vitals behavior.
- [x] Run automated proof and record results.

**Suggested Commit:** `feat(agentforge): add recent vitals evidence tool`

### Task 17.3: Integration Proof And Docs
**Status:** [x] Complete
**Description:** Keep docs, fixtures, and proof traceability honest after adding the new evidence tools.
**Acceptance Map:** Epic 17 docs update, eval proof, demo seed proof, human verification checklist.
**Proof Required:** JSON validation, focused PHPUnit, eval runner, seed/verify when Docker is available, explicit manual verification checklist.

**Subtasks:**
- [x] Update `ARCHITECTURE.md`, `PRD.md`, and `PLAN.md` where capability lists were stale.
- [x] Update demo ground truth for Alex, Riley, and Jordan.
- [x] Run JSON validation, focused PHPUnit, and eval proof.
- [x] Run seed/verify proof if Docker is available, or record the blocker.
- [x] Update this epic file with completed proof or explicit gaps.

**Suggested Commit:** `docs(agentforge): record epic 17 evidence proof`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [ ] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Change Log

- 2026-05-02: Added `AllergiesEvidenceTool`, `RecentVitalsEvidenceTool`, SQL repository methods, factory wiring, planner routing, verifier vocabulary/tests, fixture evals, demo seed rows, demo verification checks, ground-truth updates, and docs.
- 2026-05-02: JSON validation passed for `agent-forge/fixtures/eval-cases.json` and `agent-forge/fixtures/demo-patient-ground-truth.json`.
- 2026-05-02: Added a corrective planner test/fix so allergy wording takes precedence over medication wording when questions mention both, e.g. allergic reactions to medications.
- 2026-05-02: Focused isolated PHPUnit passed: `composer phpunit-isolated -- --filter 'EvidenceToolsTest|EvidenceToolFactoryTest|SqlChartEvidenceRepositoryIsolationTest|VerifiedAgentHandlerTest|DraftVerifierTest|ChartEvidenceCollectorTest|EvidenceBundleTest|ChartQuestionPlannerTest'` -> 72 tests, 291 assertions.
- 2026-05-02: AgentForge evals passed: `php agent-forge/scripts/run-evals.php` -> 24 passed, 0 failed. Latest result: `agent-forge/eval-results/eval-results-20260502-181732.json`.
- 2026-05-02: Docker demo seed passed: `agent-forge/scripts/seed-demo-data.sh`.
- 2026-05-02: Docker demo verification passed: `agent-forge/scripts/verify-demo-data.sh`, including active allergy, inactive allergy exclusion, recent vitals, and sparse stale-only vitals checks.
- 2026-05-02: Full `composer phpunit-isolated` was attempted for broader confidence. It reached all 2,952 tests but failed 8 `FrontControllerRoutingTest` cases because no server was listening on `127.0.0.1:8765`; this is an environment prerequisite failure, not an AgentForge assertion failure.
- Manual verification remains intentionally unchecked because no browser/manual reviewer workflow was performed in this task. Required fixture/data prerequisites exist for: Alex active allergies, Alex recent vitals, Riley inactive allergy exclusion, Jordan missing allergies, and Jordan stale-only vitals.

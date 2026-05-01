# Epic: Conversation Scope And Citation Surfacing

**Generated:** 2026-05-01 18:53:59 EDT
**Scope:** AgentForge docs, chart-panel UI, fixture eval metadata, isolated tests
**Status:** Implemented, Pending Manual Browser Smoke

---

## Overview

Epic 11 corrects the single-turn versus multi-turn mismatch and surfaces citations visibly in the physician chart panel. This implementation keeps the runtime API backward compatible: no live `conversation_id` is added, and persistent PHI conversation storage remains deferred until server-owned patient-bound state, retention, and follow-up evals are designed and implemented.

---

## Tasks

### Task 11.1.1: Document Current V1 As Single-Shot Constrained RAG

**Status:** [x] Complete
**Description:** Update reviewer-facing materials so they do not claim implemented multi-turn behavior.
**Acceptance Map:** `PLAN.md` Task 11.1.1; `USERS.md` Use Case 2; `ARCHITECTURE.md` Current Status And Remediation Roadmap.
**Proof Required:** Document diffs plus isolated document tests where applicable.

**Subtasks:**
- [x] Confirm current v1 docs say each question is independent and active-chart scoped.
- [x] Update release/demo notes so citation UI is no longer described as planned.
- [x] Preserve multi-turn follow-up as a target use case, not an implemented capability.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Proof:** `composer phpunit-isolated -- --filter AgentForge` passed with 125 tests and 499 assertions. `composer phpunit-isolated -- --filter 'AgentForgePanelCitationUiTest|EvaluationTiersDocumentTest|AgentResponseTest'` passed with 9 tests and 70 assertions.

**Suggested Commit:** `docs(agent-forge): clarify epic 11 conversation scope`

### Task 11.1.2: Plan Minimum Multi-Turn Conversation State

**Status:** [x] Complete
**Description:** Define the minimum safe future multi-turn contract before any transcript storage is added.
**Acceptance Map:** `PLAN.md` Task 11.1.2; `PRD.md` Story 2; `ARCHITECTURE.md` Public Interfaces.
**Proof Required:** Documented planned contract plus planned eval cases that are not runtime-claimed.

**Subtasks:**
- [x] Document planned server-owned `conversation_id` behavior.
- [x] Document patient/user binding, expiration, turn limits, retention, and no cross-patient carryover.
- [x] Document that prior answer text is context only and every follow-up factual claim needs current citations.
- [x] Add planned eval case metadata for safe follow-up behavior.
- [x] Update this epic file with completed proof or an explicit gap.

**Proof:** `agent-forge/docs/ARCHITECTURE.md` contains the planned minimum multi-turn contract. `agent-forge/fixtures/eval-cases.json` contains planned-not-runtime-claimed follow-up cases. `EvaluationTiersDocumentTest::testMultiTurnEvalCasesArePlannedWithoutRuntimeClaim` covers both.

**Suggested Commit:** `docs(agent-forge): define planned multi-turn contract`

### Task 11.2.1: Surface Citation Payloads In The Chart Panel

**Status:** [x] Complete
**Description:** Render structured citations visibly from the AgentForge response payload, outside model-authored answer text.
**Acceptance Map:** `PLAN.md` Task 11.2.1; `SPECS.txt` source-attribution requirement; `ARCHITECTURE.md` Source-cited answer display.
**Proof Required:** Isolated UI regression test, Twig compilation, deterministic evals, and manual smoke gap if browser proof is not run.

**Subtasks:**
- [x] Render `payload.citations` under Sources without rewriting source strings.
- [x] Render missing/unchecked sections and warnings separately.
- [x] Show an explicit no-source state for successful responses with no citations and no missing/warning context.
- [x] Add isolated regression coverage for citation UI behavior.
- [x] Update this epic file with completed proof or an explicit gap.

**Proof:** `AgentForgePanelCitationUiTest` covers structured citation rendering, separate missing/warning rendering, and the no-source state. `composer phpunit-isolated -- --filter TwigTemplateCompilationTest --group twig` passed with 269 tests and 538 assertions. `php agent-forge/scripts/run-evals.php` passed 13 evals with 0 failures and preserved existing citation counts.

**Suggested Commit:** `feat(agent-forge): render chart-panel citations`

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

- 2026-05-01 18:56 EDT: Implemented citation UI rendering from `payload.citations`, separate missing/warning display, and explicit no-source state.
- 2026-05-01 18:56 EDT: Updated reviewer docs and release/demo notes to keep v1 scoped as single-shot constrained RAG while reflecting visible citation UI.
- 2026-05-01 18:56 EDT: Added planned-not-runtime-claimed follow-up eval metadata for future server-owned conversation state.
- 2026-05-01 18:56 EDT: Automated proof passed:
  - `composer phpunit-isolated -- --filter 'AgentForgePanelCitationUiTest|EvaluationTiersDocumentTest|AgentResponseTest'`
  - `composer phpunit-isolated -- --filter AgentForge`
  - `composer phpunit-isolated -- --filter TwigTemplateCompilationTest --group twig`
  - `php agent-forge/scripts/run-evals.php`
- Manual browser smoke is not checked because it was not performed in this turn. Exact action: open fake patient `900001`, ask `Show me the recent A1c trend.`, confirm answer values and visible Sources list, then run the missing-data and refusal questions described in the plan.

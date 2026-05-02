# Epic: Server-Bound Multi-Turn Conversation State

**Generated:** 2026-05-02
**Scope:** AgentForge backend, chart-panel request UI, deterministic evals, documentation
**Status:** Complete

---

## Overview

Epic 19 implements the smallest SPECS-compliant multi-turn path: a server-issued `conversation_id`, bound to the active OpenEMR session user and patient, with short-lived server-side state. Prior turn context is compact planner context only; every turn re-fetches chart evidence and patient-specific claims must cite source ids from the current evidence bundle.

No persistent transcript storage, database schema, browser-owned memory, or broad chat history was added.

---

## Tasks

### Task 19.1: Conversation Identity And Patient Binding
**Status:** [x] Complete
**Description:** Add server-owned conversation primitives, bind ids to user/patient, validate follow-up ids before tools/model execution, and log the id with request metadata.
**Acceptance Map:** PLAN.md Task 19.1.1; ARCHITECTURE.md Minimum Multi-Turn Contract.
**Proof Required:** Isolated PHPUnit and deterministic evals.

**Subtasks:**
- [x] Add conversation value objects and store interfaces under `src/AgentForge/Conversation`.
- [x] Wire the request handler and endpoint to issue and validate server-owned ids.
- [x] Refuse missing, expired, and cross-patient conversation reuse before evidence or model work.
- [x] Add `conversation_id` to response and request-log context.
- [x] Add automated proof for issuance, same-patient follow-up, cross-patient refusal, and expiry.

**Proof:** `composer phpunit-isolated -- --filter 'AgentForge'` passed 233 tests / 1170 assertions. `php agent-forge/scripts/run-evals.php` passed 28 evals.

---

### Task 19.2: Follow-Up Grounding Discipline
**Status:** [x] Complete
**Description:** Let compact prior-turn state guide follow-up planning without becoming evidence, and keep current evidence/citation verification as the factual boundary.
**Acceptance Map:** PLAN.md Task 19.2.1; SPECS.txt multi-turn requirement; ARCHITECTURE.md safety rule.
**Proof Required:** Isolated PHPUnit and deterministic evals.

**Subtasks:**
- [x] Pass compact conversation context into planning and prompt composition.
- [x] Keep evidence tools re-fetching on every follow-up turn.
- [x] Ensure verifier rejects stale or uncited prior-answer reuse.
- [x] Keep chart panel conversation id in JS memory only.
- [x] Add automated proof for ambiguous follow-up routing and stale-prior-answer rejection.

**Proof:** `ChartQuestionPlannerTest` and `VerifiedAgentHandlerTest` cover ambiguous follow-up routing and current-source citation. Eval `stale_prior_answer_reuse_rejected` proves stale context cannot support an unverifiable factual claim.

---

### Task 19.3: Eval And Documentation Proof
**Status:** [x] Complete
**Description:** Promote planned multi-turn fixture cases to runtime evals and update docs that previously described multi-turn state as absent.
**Acceptance Map:** PLAN.md Epic 19 Definition of Done; evaluation tier docs.
**Proof Required:** Eval runner output and documentation tests.

**Subtasks:**
- [x] Promote planned multi-turn eval metadata into runtime fixture cases.
- [x] Update eval runner support for multi-turn scenarios.
- [x] Update evaluation taxonomy and document tests.
- [x] Update PLAN, USERS, PRD, and ARCHITECTURE language for the implemented narrow scope.
- [x] Create this epic progress file with proof mapping.

**Proof:** `agent-forge/eval-results/eval-results-20260502-191917.json` records 28 passed / 0 failed evals.

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested for the request endpoint path.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human verification items are not checked here because no live browser manual verification was performed in this pass.
- [x] Known fixture/data/user prerequisites for automated proof are covered by deterministic fixture cases.

---

## Change Log

- 2026-05-02: Implemented server-bound conversation ids, follow-up validation, current-evidence grounding, UI id carry-forward, runtime evals, and documentation updates. Git commits are left to the user unless explicitly requested.

# Epic: Server-Bound Multi-Turn Conversation State

**Generated:** 2026-05-02
**Scope:** AgentForge backend, chart-panel request UI, deterministic evals, documentation
**Status:** Complete

---

## Overview

Epic 19 implements the smallest SPECS-compliant multi-turn path: a server-issued `conversation_id`, bound to the active OpenEMR session user and patient, with short-lived server-side state. From the user perspective, this means same-chart follow-up questions during the open panel session; it does not mean a persistent transcript or durable chat room. Prior turn context is compact planner context only; every turn re-fetches chart evidence and patient-specific claims must cite source ids from the current evidence bundle.

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

**Proof:** `composer phpunit-isolated -- --filter 'AgentForge'` passed during implementation; the latest local AgentForge slice after manual-test fixes passed 239 tests / 1200 assertions. `php agent-forge/scripts/run-evals.php` passed 28 evals.

**Manual Proof:** Local browser/session proof on 2026-05-02 showed patient `900001` first turn returned/logged `conversation_id=483e30144b95db814ba33e74b635ad98`, patient `900002` switch issued a new id `f68ce87128d7494a7168b8e922a7f7cb`, forced reuse of the old id from patient `900002` returned HTTP 403 before tools/model, invalid id returned a generic 400, and valid-format missing id returned a generic conversation refusal before tools/model.

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

**Manual Proof:** Local browser/session proof on patient `900002` showed same-session lab follow-up requests `4c9e6dc2-5a42-4ca2-b8e5-7f28d6279338` and `2719a053-d3cd-4b04-9f5d-f3b9775adc56` reused `conversation_id=090b14698f2334b319eb2b11c8e11363`, re-ran `Recent labs`, cited only `lab:procedure_result/agentforge-egfr-900002-2026-05@2026-05-10`, and passed verification. Earlier follow-ups for allergies and medications also re-fetched only the relevant section and cited current sources.

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

**Proof:** `agent-forge/eval-results/eval-results-20260502-195708.json` records 28 passed / 0 failed evals after manual-test hardening.

**Manual-Test Fixes:** Manual testing found and fixed three issues before closing the epic: provider timeout now falls back to verifier-checked deterministic evidence instead of losing the answer; visit-briefing fallback no longer duplicates medication lines; parser validation details such as invalid conversation-id format no longer leak to the clinician. UI testing also clarified the product model: the textarea is for the next single question and clears after response, while the submitted question is displayed separately above the answer.

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested for the request endpoint path.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human verification items were checked with local browser/session proof on 2026-05-02.
- [x] Known fixture/data/user prerequisites for automated proof are covered by deterministic fixture cases.

---

## Change Log

- 2026-05-02: Implemented server-bound conversation ids, follow-up validation, current-evidence grounding, UI id carry-forward, runtime evals, and documentation updates. Git commits are left to the user unless explicitly requested.
- 2026-05-02: Completed local manual browser/session acceptance. Recorded same-patient follow-up, patient-switch reset, forced stale-id refusal, invalid/missing id refusals, UI input clearing, and fresh evidence/citation behavior.

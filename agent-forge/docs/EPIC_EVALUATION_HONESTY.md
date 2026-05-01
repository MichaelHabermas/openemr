# Epic: Evaluation Honesty And Live-Path Eval Tiers

**Generated:** 2026-05-01 22:30:00 EDT
**Scope:** documentation, isolated regression proof, reviewer-facing evaluation taxonomy
**Status:** Complete

---

## Overview

Epic 10 preserves the existing deterministic fixture evals while making their proof boundary explicit. It adds a reviewer-facing evaluation tier taxonomy for seeded SQL evidence, live model contract checks, browser smoke checks, deployed smoke checks, and release gating without pretending those live tiers have been run in this pass.

First-principles premise check: the problem is not a lack of green numbers. The bottleneck is proof honesty. A cheap fixture run is useful only if reviewers can see exactly what it proves and what it does not prove.

---

## Tasks

### Task 10.1.1: Label Existing Fixture Evals Honestly
**Status:** [x] Complete
**Description:** Classify current evals as deterministic fixture/orchestration evals and prevent fixture-only results from being described as full live-agent proof.
**Acceptance Map:** `PLAN.md` Task 10.1.1; `PRD.md` evaluation strategy; `GAUNTLET-INSTRUCTOR-REVIEWS.md` eval shortfall.
**Proof Required:** Isolated document regression test and reviewer-readable eval docs.

**Subtasks:**
- [x] Add evaluator-facing taxonomy language for current fixture evals.
- [x] Update eval result docs and reviewer guide language.
- [x] Add regression proof that fixture-only green is not live-agent proof.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): label fixture eval proof`

### Task 10.1.2: Plan Seeded SQL Evidence Evals
**Status:** [x] Complete
**Description:** Define the SQL-backed eval tier against fake seeded OpenEMR data with deterministic expected evidence and pass/fail criteria.
**Acceptance Map:** `PLAN.md` Task 10.1.2; `SPECS.txt` eval failure-mode requirements; `USERS.md` chart-orientation use cases.
**Proof Required:** Isolated document regression test covering required SQL cases and expected proof boundary.

**Subtasks:**
- [x] Define SQL tier prerequisites and data-source expectations.
- [x] Add required cases for visit briefing, medications, A1c trend, missing data, last plan, sparse chart, dense chart, unauthorized access, and cross-patient leakage.
- [x] State that result files are created only after the tier is implemented and actually run.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): plan sql eval tier`

### Task 10.2.1: Plan Live Model Contract Evals
**Status:** [x] Complete
**Description:** Define a small live-provider eval tier that captures model contract, token/cost/latency telemetry, verifier results, and citation completeness.
**Acceptance Map:** `PLAN.md` Task 10.2.1; `PRD.md` AI system requirements; instructor review finding that fixture evals stub the LLM.
**Proof Required:** Isolated document regression test covering cases and required telemetry fields.

**Subtasks:**
- [x] Define live-provider prerequisites and credential boundary.
- [x] Add required live-provider cases for supported, missing-data, refusal, hallucination pressure, prompt injection, and malformed/unsupported-output paths.
- [x] Require model name, token usage, estimated cost, latency, verifier result, and citation completeness.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): plan live model eval tier`

### Task 10.2.2: Plan Browser UI And Deployed Session Smoke Evals
**Status:** [x] Complete
**Description:** Define local and deployed browser/session smoke tiers for real UI, endpoint, session binding, citations, missing-data rendering, authorization mismatch, and sensitive audit-log inspection.
**Acceptance Map:** `PLAN.md` Task 10.2.2; `ARCHITECTURE.md` production-readiness blocker; `PRD.md` deployed app and evaluation requirements.
**Proof Required:** Isolated document regression test covering local/deployed smoke pass criteria and no-result-file-unless-run rule.

**Subtasks:**
- [x] Define local browser/session smoke checklist.
- [x] Define deployed browser/session smoke checklist.
- [x] Add release gate for captured results or documented gaps before live-agent evaluation claims.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): plan browser smoke evals`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [ ] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

Boundary/orchestration note: this epic changes documentation and isolated document regression tests only. It does not change runtime endpoint, authorization, SQL execution, model provider, browser UI, or deployed VM behavior.

Human verification gap: a reviewer can inspect `agent-forge/docs/EVALUATION-TIERS.md`, but no external human review has been performed in this session.

---

## Change Log

- 2026-05-01 22:30 EDT: Added `agent-forge/docs/EVALUATION-TIERS.md` with Tier 0 fixture/orchestration proof, planned SQL/live-model/browser/deployed tiers, pass criteria, and release rule.
- 2026-05-01 22:30 EDT: Updated reviewer-facing eval docs to avoid treating fixture-only green as full live-agent proof.
- 2026-05-01 22:30 EDT: Added `EvaluationTiersDocumentTest` to lock the proof taxonomy, live-tier pass criteria, required telemetry, and no-result-file-unless-run rule.
- 2026-05-01 22:30 EDT: Updated architecture, PRD, and PLAN references to point at the Epic 10 taxonomy.
- 2026-05-01 22:32 EDT: Proof run captured: `EvaluationTiersDocumentTest` passed 4/4; AgentForge isolated PHPUnit passed 122/122; `php agent-forge/scripts/run-evals.php` passed 13/13; `agent-forge/scripts/check-local.sh` passed after rerun with sandbox escalation for PHPStan local TCP binding.
- 2026-05-01 22:40 EDT: A repository-wide Docker clean sweep was started but deemed out of scope for this Epic 10 documentation/test change. Its unrelated Rector/API/e2e/service findings are not used as Epic 10 acceptance proof.

---

## Acceptance Matrix

| Requirement | Implementation | Automated proof | Human proof or gap |
| --- | --- | --- | --- |
| Current fixture evals are labeled deterministic fixture/orchestration proof. | `agent-forge/docs/EVALUATION-TIERS.md`; `agent-forge/eval-results/README.md`; `AGENTFORGE-REVIEWER-GUIDE.md`. | `EvaluationTiersDocumentTest::testFixtureEvalsAreLabeledWithoutOverclaimingLiveProof`. | Reviewer-readable; not externally reviewed. |
| Fixture-only green is not described as full live-agent proof. | Release rule and Tier 0 boundary language in `EVALUATION-TIERS.md`. | `EvaluationTiersDocumentTest::testFixtureEvalsAreLabeledWithoutOverclaimingLiveProof`. | Reviewer-readable; not externally reviewed. |
| Seeded SQL eval tier exists with expected evidence and pass/fail criteria. | Tier 1 section in `EVALUATION-TIERS.md`. | `EvaluationTiersDocumentTest::testLivePathTiersHavePassCriteriaAndRequiredCases`. | Reviewer-readable; not executed as live SQL in this epic. |
| Live model tier exists with cost/latency capture. | Tier 2 section in `EVALUATION-TIERS.md`. | `EvaluationTiersDocumentTest::testLiveModelTelemetryAndSmokeResultRulesAreExplicit`. | Reviewer-readable; not executed against provider in this epic. |
| Browser and deployed smoke tiers exist without fake result files. | Tier 3 and Tier 4 sections in `EVALUATION-TIERS.md`. | `EvaluationTiersDocumentTest::testLiveModelTelemetryAndSmokeResultRulesAreExplicit`. | Reviewer-readable; not manually run in this epic. |
| Release gate requires live-tier captured result or explicit gap before live-agent claims. | Purpose/release rule in `EVALUATION-TIERS.md`; reviewer-guide link. | `EvaluationTiersDocumentTest::testLiveModelTelemetryAndSmokeResultRulesAreExplicit`. | Reviewer-readable; not externally reviewed. |

---

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes for targeted Epic 10 and AgentForge proof
- Required manual checks executed and captured? no, external reviewer/manual browser or deployed checks were not performed in this documentation-backed scope
- Required fixtures/data/users for proof exist? yes, fake patient and seed/verify commands are referenced for planned live tiers
- Security/privacy/logging/error-handling requirements verified? yes for documentation requirements; no runtime trust boundary changed
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

# Epic: Verifier Hardening And PHI-Minimizing Tool Routing

**Generated:** 2026-05-01 19:06:29 EDT
**Scope:** backend
**Status:** Implemented, Pending Manual Browser Smoke

---

## Overview

Epic 12 strengthens the AgentForge trust boundary by making the deterministic verifier distrust model-supplied claim labels and by routing only the chart evidence needed for the active question. `SPECS.txt` is the controlling source: observability must show what the agent did, in order, which tools were used or failed, how long work took, and token/cost telemetry when model calls occur.

First-principles constraint: the cheapest and safest PHI is the PHI never retrieved. The implementation therefore deletes broad default evidence access for narrow medication, lab, plan, unsafe, and ambiguous requests before optimizing verifier behavior.

---

## Tasks

### Task 12.1.1: Require Verification For Factual Content Regardless Of Model Label
**Status:** [x] Complete
**Description:** Treat patient-specific factual content as requiring source grounding even when the model labels it as `warning`, `missing_data`, or `refusal`.
**Acceptance Map:** `PLAN.md` Task 12.1.1; `SPECS.txt` verification and observability requirements.
**Proof Required:** Automated verifier regression tests.

**Subtasks:**
- [x] Add regression cases for mislabeled patient facts.
- [x] Update verifier classification so model labels do not decide whether grounding is required.
- [x] Preserve allowlisted non-patient boilerplate for refusals, missing sections, and tool failures.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `fix(agentforge): harden verifier claim grounding`

### Task 12.1.2: Reduce Substring-Match Brittleness And Unsupported-Tail Risk
**Status:** [x] Complete
**Description:** Block displayed factual sentences when a grounded claim is only a substring of a longer unsupported sentence.
**Acceptance Map:** `PLAN.md` Task 12.1.2; `SPECS.txt` requirement that verification catches failure modes and known limitations are documented.
**Proof Required:** Automated verifier regression tests.

**Subtasks:**
- [x] Add regression cases for exact-value support, wrong source value, and unsupported tails.
- [x] Require grounded claim text to cover the displayed sentence after citation brackets are removed.
- [x] Document conservative limitation: semantic paraphrase verification remains deferred.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `fix(agentforge): reject unsupported factual tails`

### Task 12.2.1: Plan Selective Evidence Tool Routing
**Status:** [x] Complete
**Description:** Route medication, lab, last-plan, briefing, unsafe, and ambiguous questions to minimal server-selected evidence sections and log skipped chart areas.
**Acceptance Map:** `PLAN.md` Task 12.2.1; `SPECS.txt` observability questions for request order, tool failures, tokens, and cost.
**Proof Required:** Automated planner, collector, telemetry, request-log, and handler tests.

**Subtasks:**
- [x] Add routing cases for medications, prescriptions, labs, last plan, visit briefing, ambiguous queries, and unsafe refusals.
- [x] Preserve server-owned tool selection; the model still receives bounded evidence only.
- [x] Add `skipped_chart_sections` telemetry and sensitive-log allowlisting.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agentforge): log selective evidence routing`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [ ] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Proof

- Automated proof: `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'` passed 132 tests / 527 assertions on 2026-05-01.
- Eval proof: `php agent-forge/scripts/run-evals.php` passed 13/13 on 2026-05-01 and wrote `agent-forge/eval-results/eval-results-20260501-231527.json`.
- Full local proof: `agent-forge/scripts/check-local.sh` passed on 2026-05-01 after syntax checks, isolated PHPUnit, deterministic evals, focused PHPStan, and PHPCS.
- Verifier proof: `DraftVerifierTest` now blocks mislabeled patient facts without citations, verifies mislabeled facts with correct citations, blocks wrong source values, and blocks unsupported factual tails.
- Routing proof: `ChartQuestionPlannerTest` covers medication, prescription, labs, last-plan, visit briefing, ambiguous refusal before evidence access, and unsafe refusal before evidence access.
- Observability proof: telemetry and request-log tests cover `skipped_chart_sections` as PHI-minimized metadata.

## Known Limitations

- Semantic paraphrase verification remains conservative and mostly lexical. Unsupported tails are blocked, exact source values are enforced, multiple grounded claims can cover one displayed sentence, and broader semantic grounding is deferred rather than claimed.
- Manual reviewer comparison of live medication and lab logs remains unperformed in this local task; the automated request-log path proves the field shape.

---

## Change Log

- 2026-05-01: Implemented verifier label distrust, unsupported-tail blocking, selective routing telemetry, and focused automated proof. Git commits are left to the user unless explicitly requested.

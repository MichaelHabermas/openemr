# Epic: Verifier Hardening And PHI-Minimizing Tool Routing

**Generated:** 2026-05-01 19:06:29 EDT
**Scope:** backend
**Status:** Local And Deployed Browser/Log Verification Complete

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
- [x] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Proof

- Automated proof: `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'` passed 134 tests / 531 assertions on 2026-05-01.
- Eval proof: `php agent-forge/scripts/run-evals.php` passed 13/13 on 2026-05-01 and wrote `agent-forge/eval-results/eval-results-20260501-233730.json`.
- Full local proof: `agent-forge/scripts/check-local.sh` passed on 2026-05-01 after syntax checks, isolated PHPUnit, deterministic evals, focused PHPStan, and PHPCS.
- Verifier proof: `DraftVerifierTest` now blocks mislabeled patient facts without citations, verifies mislabeled facts with correct citations, blocks wrong source values, and blocks unsupported factual tails.
- Routing proof: `ChartQuestionPlannerTest` covers medication, prescription, labs, last-plan, visit briefing, ambiguous refusal before evidence access, and unsafe refusal before evidence access.
- Observability proof: telemetry and request-log tests cover `skipped_chart_sections` as PHI-minimized metadata.
- Local browser/log proof:
  - Lab question `Show me the recent A1c trend.` logged `request_id=57da1e1a-0ab4-44ed-800c-6ae296cff37d`, `question_type=lab`, `tools_called=["Recent labs"]`, skipped demographics/problems/prescriptions/notes, A1c source IDs only, `model=gpt-4o-mini`, token/cost telemetry, and `verifier_result=passed`.
  - Medication question `What medications are active?` returned `Metformin ER 500 mg` and `Lisinopril 10 mg` with visible prescription source IDs only. Log `request_id=598c4569-204a-405a-9c7a-4255972cd37d` showed `question_type=medication`, `tools_called=["Active prescriptions"]`, skipped unrelated chart sections, medication prescription source IDs only, and `verifier_result=passed`.
  - Clinical-advice refusal logged `request_id=8d1d8eb6-d24f-4795-8f62-8a7c35c3e6e6`, `question_type=clinical_advice_refusal`, `tools_called=[]`, `source_ids=[]`, `model=not_run`, `failure_reason=clinical_advice_refusal`, and `verifier_result=not_run`.
  - Ambiguous question refusal logged `request_id=2e35a40d-a58f-47fa-9f7a-03b6ff904ca1`, `question_type=ambiguous_question`, `tools_called=[]`, all chart sections skipped, `source_ids=[]`, `model=not_run`, `failure_reason=ambiguous_question`, and `verifier_result=not_run`.
- Deployed browser/log proof:
  - Health and seeded demo data passed on the deployed VM before browser smoke.
  - Lab question `Show me the recent A1c trend.` returned the expected A1c values and visible lab source IDs. After recreating the OpenEMR container to clear stale runtime code, log `request_id=37667cff-a97b-4fef-8259-492a2391c64e` showed `question_type=lab`, `tools_called=["Recent labs"]`, skipped unrelated sections, A1c source IDs only, token/cost telemetry, and `verifier_result=passed`.
  - Medication question `What medications are active?` returned `Metformin ER 500 mg` and `Lisinopril 10 mg` with visible prescription source IDs only. Log `request_id=1a758ecd-4612-42ff-b11f-1a5696a85d99` showed `question_type=medication`, `tools_called=["Active prescriptions"]`, skipped unrelated chart sections, medication prescription source IDs only, and `verifier_result=passed`.
  - Missing-data question `Has a urine microalbumin been checked recently?` returned `Urine microalbumin not found in the chart.` with the same text under `Missing or unchecked`. Log `request_id=50fa82b6-559b-4ce5-8c6c-ebd4bada7a9e` showed the bounded `Recent labs` route, existing A1c lab source IDs as checked evidence, and `verifier_result=passed`.
  - Clinical-advice refusal logged `request_id=fadf7901-64d5-4068-ab92-052ad9c06cf3`, `question_type=clinical_advice_refusal`, `tools_called=[]`, `source_ids=[]`, `model=not_run`, `failure_reason=clinical_advice_refusal`, and `verifier_result=not_run`.
  - Ambiguous question refusal logged `request_id=635cc0b7-29bb-4057-a5ac-ff864adff400`, `question_type=ambiguous_question`, `tools_called=[]`, all chart sections skipped, `source_ids=[]`, `model=not_run`, `failure_reason=ambiguous_question`, and `verifier_result=not_run`.

## Known Limitations

- Claim grounding is now token-set matching via `EvidenceMatcher` (no longer raw `str_contains` substring matching). All significant tokens of the cited evidence's `display_label` and `value` must appear as whole tokens in the claim text; numeric tokens must match exactly (`5` does not match inside `5.0`).
- Date paraphrase is canonicalized at the matcher: English-month dates (`April 12, 1976`, `12 Apr 1976`, `Apr 1976`) are rewritten to ISO tokens before comparison so they ground against stored values like `1976-04-12`. Day numbers are zero-padded; ambiguous regional formats (`4/12/1976`) are deliberately left unconverted.
- Broader semantic paraphrase verification remains deferred. Unsupported tails are still blocked, exact source values are still enforced, and multiple grounded claims can still cover one displayed sentence. Claims that paraphrase the label itself (e.g. "Born on April 12, 1976" against label `Date of birth`) require the prompt to keep the literal label prefix; `PromptComposer` includes explicit examples instructing the model to copy `display_label` verbatim.

---

## Change Log

- 2026-05-01: Implemented verifier label distrust, unsupported-tail blocking, selective routing telemetry, and focused automated proof. Git commits are left to the user unless explicitly requested.
- 2026-05-01: Local browser proof exposed and fixed the `Active medications` versus `Active prescriptions` route mismatch and the medication-name verifier false negative; full local AgentForge check passed after both fixes.
- 2026-05-01: Local lab, medication, clinical-advice refusal, and ambiguous-question logs were manually inspected and recorded as local proof.
- 2026-05-02: Deployed browser/log proof passed after OpenEMR container recreation cleared stale runtime code; deployed lab, medication, missing-data, clinical-advice refusal, and ambiguous-question checks were manually inspected and recorded.
- 2026-05-02: Replaced `DraftVerifier::claimMatchesAllSources` substring logic with `EvidenceMatcher` token-set matching. Added English-month → ISO date canonicalization so naturally-phrased dates ground against stored ISO values without weakening exact-value enforcement. Split `DemographicsEvidenceTool` into per-field items (Patient name / Date of birth / Sex) so individual claims can ground against distinct sourceIds. Shrunk `ProblemsEvidenceTool` value to literal `Active` (date preserved in `source_date`). Added `PromptComposer` examples enforcing literal `display_label` copying. Verified live "Tell me about this patient" grounds all 10 demo evidence items with no verifier warnings; isolated tests pass at 178/178.

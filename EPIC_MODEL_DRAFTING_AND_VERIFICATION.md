# Epic: Model Drafting And Deterministic Verification

**Generated:** 2026-04-30
**Scope:** AgentForge structured drafting, bounded evidence bundle, deterministic verification, and verified endpoint response
**Status:** Complete

---

## Overview

Epic 6 adds a fixture-first drafting and verification pipeline on top of the Epic 4 authorization gate and Epic 5 read-only evidence tools. The default path does not call an external LLM; it proves the contract, source matching, refusal behavior, malformed draft handling, visible missing-data behavior, and endpoint composition before any real provider is introduced.

---

## Tasks

### Task 6.1.1: Structured Draft Schema And Evidence Bundle
**Status:** [x] Complete
**Description:** Define the structured draft and bounded prompt evidence contracts needed before verification.
**Acceptance Map:** `PLAN.md` Task 6.1.1; `PRD.md` Verification Requirements; `ARCHITECTURE.md` evidence-bundle and verifier flow.
**Proof Required:** Isolated tests for valid drafts, malformed drafts, missing patient-claim source IDs, unsupported advice fields, and bounded prompt evidence shape.

**Subtasks:**
- [x] Add immutable draft sentence, claim, response, and usage DTOs.
- [x] Add bounded evidence bundle/item DTOs that omit source table, patient id, and full chart fields from prompt arrays.
- [x] Add isolated schema and evidence-bundle tests.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): define verified draft contract`

### Task 6.1.2: Feature-Flagged Fixture Drafting
**Status:** [x] Complete
**Description:** Add a model-off `DraftProvider` seam and deterministic fixture provider for the default Epic 6 path.
**Acceptance Map:** `PLAN.md` Task 6.1.2; `ARCHITECTURE.md` LLM is untrusted and receives bounded evidence only.
**Proof Required:** Isolated tests proving fixture drafting uses bounded evidence and records fixture token/cost metadata.

**Subtasks:**
- [x] Add `DraftProvider` interface and default fixture-first provider factory.
- [x] Add deterministic `FixtureDraftProvider`.
- [x] Prove default usage metadata is fixture-only with no estimated cost.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add fixture draft provider`

### Task 6.2.1: Block Unsupported Patient Claims
**Status:** [x] Complete
**Description:** Implement deterministic source-id and label/value verification for patient-specific draft claims.
**Acceptance Map:** `PLAN.md` Task 6.2.1; `PRD.md` zero unsupported patient-specific claims reach the physician.
**Proof Required:** Isolated verifier tests for supported, unsupported, partially supported, fabricated medication, and source-id/value-mismatch claims.

**Subtasks:**
- [x] Add `DraftVerifier` and `VerificationResult`.
- [x] Verify source ids exist in the evidence bundle.
- [x] Verify patient-fact claim text includes each cited source label and value.
- [x] Block fabricated or mismatched claims from final response.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): verify patient claims`

### Task 6.2.2: Refuse Clinical Advice
**Status:** [x] Complete
**Description:** Add deterministic refusal checks for diagnosis, treatment, dosing, medication-change, clinical-rule, and note-drafting requests.
**Acceptance Map:** `PLAN.md` Task 6.2.2; `USERS.md` and `PRD.md` non-goals.
**Proof Required:** Isolated tests proving unsafe questions are refused and safe chart-fact questions still return cited verified evidence.

**Subtasks:**
- [x] Add `ClinicalAdviceRefusalPolicy`.
- [x] Refuse unsafe questions before evidence collection.
- [x] Refuse unsafe draft claims during verification.
- [x] Preserve safe chart-fact answers.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): refuse clinical advice`

### Task 6.2.3: Missing Data, Tool Failure, And Malformed Draft Handling
**Status:** [x] Complete
**Description:** Ensure missing sections, failed tools, malformed drafts, and provider failures degrade visibly without leaking internals.
**Acceptance Map:** `PLAN.md` Task 6.2.3; `PRD.md` Missing Or Unclear Data; `ARCHITECTURE.md` Failure Modes.
**Proof Required:** Isolated handler tests for missing/failure visibility, retry-once malformed output, clear final failure, and generic unexpected-error responses.

**Subtasks:**
- [x] Add `VerifiedAgentHandler` orchestration.
- [x] Surface missing and failed evidence sections in the final response.
- [x] Retry malformed draft output once.
- [x] Return generic failure text after repeated malformed draft failures.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): return verified agent responses`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Change Log

- 2026-04-30: Added structured draft DTOs, usage metadata, bounded evidence bundle, fixture draft provider, clinical-advice refusal policy, verifier, and verified handler.
- 2026-04-30: Wired the AgentForge endpoint to use `VerifiedAgentHandler` with the default fixture-first provider factory and deterministic verifier.
- 2026-04-30: Added isolated tests for schema validation, bounded evidence prompt shape, fixture usage metadata, source/value verification, advice refusal, malformed draft retry/failure, and visible tool failure.
- 2026-04-30: Hardened verifier and schema validation after self-review: wrong element types now fail DTO validation, unsafe displayed sentence text is rejected even when claim text looks supported, mixed supported/unsupported claims reject the whole sentence, and new handler catches follow repository exception policy without catching `Error`.
- 2026-04-30: Added explicit known-missing-data handling for urine microalbumin questions so safe missing-data checks return a visible not-found section instead of silently omitting the missing fact.
- 2026-04-30: Reworked the AgentForge endpoint wrapper to use Symfony `Request` and local closures so the Epic 6 entrypoint passes the focused static-analysis rules.

## Proof Log

- `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'`: passed, 89 tests and 259 assertions.
- `php -l` across touched AgentForge source, tests, and `interface/patient_file/summary/agent_request.php`: passed.
- `vendor/bin/phpcs` across touched AgentForge source, tests, and `interface/patient_file/summary/agent_request.php`: passed.
- Focused PHPStan on the Epic 6 source, tests, and `interface/patient_file/summary/agent_request.php`: passed.
- `agent-forge/scripts/verify-demo-data.sh`: passed against the local Docker OpenEMR database.
- Live local endpoint smoke test for `Show me recent labs.` on Alex Testpatient: returned verified cited output including A1c `7.4 %` and `8.2 %`, with no missing sections after the temporary fake lab-tool failure was removed.

## Acceptance Traceability

| Requirement | Implementation/proof |
| --- | --- |
| Malformed draft output fails validation. | `DraftResponse`; `DraftResponseTest`; retry/fail tests in `VerifiedAgentHandlerTest`. |
| Valid draft output can be passed to verifier. | `DraftResponseTest`; `DraftVerifierTest`. |
| Patient-specific claims without source ids are invalid. | `DraftClaim`; `DraftResponseTest::testPatientSpecificClaimsWithoutSourceIdsAreInvalid`. |
| Full chart dumps and unrelated patient evidence are invalid prompt inputs. | `EvidenceBundleItem`; `EvidenceBundle::toPromptArray`; `EvidenceBundleTest` proves only bounded fields cross the model boundary. |
| Model-off mode returns deterministic fixture draft. | `FixtureDraftProvider`; `FixtureDraftProviderTest`; `DraftProviderFactoryTest`; endpoint wiring uses the fixture-first provider factory. |
| Token usage and cost fields are captured for fixture mode. | `DraftUsage::fixture`; `FixtureDraftProviderTest`; real provider token/cost is deferred. |
| Supported claims pass with citations. | `DraftVerifier`; `DraftVerifierTest::testSupportedClaimPassesWithCitation`. |
| Unsupported, fabricated, mismatched, or partially supported patient claims are blocked. | `DraftVerifierTest`; `VerifiedAgentHandlerTest::testUnverifiableDraftIsBlocked`. |
| Mixed supported and unsupported claims in one sentence do not leak unsupported text. | `DraftVerifierTest::testUnsupportedClaimRejectsWholeSentenceEvenWhenAnotherClaimIsSupported`. |
| Unsafe displayed sentence text cannot bypass a clean-looking claim. | `DraftVerifierTest::testUnsafeSentenceTextIsRejectedEvenWhenClaimTextLooksSupported`. |
| Diagnosis, treatment, dosing, medication-change, and note-drafting requests are refused. | `ClinicalAdviceRefusalPolicy`; `VerifiedAgentHandlerTest::testAdviceRequestIsRefusedBeforeEvidenceDrafting`. |
| Missing data and tool failures are visible without leaking internals. | `VerifiedAgentHandler`; `VerifiedAgentHandlerTest::testToolFailureIsVisibleWithoutLeakingInternalError`. |
| Known absent urine microalbumin evidence is reported visibly. | `KnownMissingDataPolicy`; `KnownMissingDataPolicyTest`; manual OpenEMR check for `Has Alex had a urine microalbumin result in the chart?`. |
| Malformed model output retries once, then fails clearly. | `VerifiedAgentHandler::draftWithOneRetry`; retry/fail tests. |

## Manual Verification

- [x] Open Alex Testpatient's chart in OpenEMR.
  - Observed Clinical Co-Pilot on Alex Testpatient's dashboard in the local OpenEMR browser session.
- [x] Ask a safe chart-fact question and confirm cited verified output.
  - `Show me the recent A1c trend.` returned cited verified chart facts including `Hemoglobin A1c: 7.4 % [lab:procedure_result/agentforge-a1c-2026-04@2026-04-10]` and `Hemoglobin A1c: 8.2 % [lab:procedure_result/agentforge-a1c-2026-01@2026-01-09]`.
- [x] Ask a fabricated medication/lab question and confirm unsupported content is blocked or marked not found.
  - `Has Alex had a urine microalbumin result in the chart?` returned `Urine microalbumin result not found in the chart.` and surfaced it in `Missing or unchecked`.
- [x] Ask `What dose should I prescribe?` and confirm refusal.
  - Returned the deterministic clinical-advice refusal: `AgentForge can summarize chart facts, but cannot provide diagnosis, treatment, dosing, medication-change advice, or note drafting.`
- [x] Trigger or fake a tool failure and confirm visible degraded output without internal error leakage.
  - Temporarily injected a fake `LabsEvidenceTool` failure, observed `Recent labs could not be checked.` and `Missing or unchecked: Recent labs could not be checked.` in the UI, with no internal exception text. The temporary injection was removed; `git diff -- src/AgentForge/LabsEvidenceTool.php` is empty; live endpoint recovery returned normal A1c citations.
  - Also temporarily stopped the local MySQL container and confirmed the UI displayed the generic client-side `The request failed. Please try again.` without SQL/internal error leakage. This was treated as an outage smoke check, not the primary evidence-tool degradation proof.

## Definition Of Done Gate

Can I call this done?

- Source criteria mapped to code/proof/deferral? yes.
- Required automated tests executed and captured? yes.
- Required manual checks executed and captured? yes.
- Required fixtures/data/users for proof exist? yes, Alex Testpatient demo fixture is assigned for manual proof.
- Security/privacy/logging/error-handling requirements verified? yes for isolated automated proof and live manual proof.
- Known limitations and deferred relationship/scope shapes documented? yes.
- Epic status updated honestly? yes.
- Git left unstaged and uncommitted unless user asked otherwise? yes.

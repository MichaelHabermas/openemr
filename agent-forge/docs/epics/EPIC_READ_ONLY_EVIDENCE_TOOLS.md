# Epic: Read-Only Evidence Tools

**Generated:** 2026-04-30
**Scope:** AgentForge evidence contract, read-only chart tools, and authorized non-model response
**Status:** Complete

---

## Overview

Epic 5 adds the minimum source-carrying chart evidence layer required before any LLM synthesis. The implementation reads only the active patient after Epic 4 authorization passes, returns cited evidence with missing sections, and keeps diagnosis, treatment, dosing, medication-change advice, note drafting, vector search, and model calls out of scope.

---

## Tasks

### Task 5.1.1: Evidence Contract
**Status:** [x] Complete
**Description:** Define immutable evidence DTOs that require source type, source table, source row id, source date, display label, and value.
**Acceptance Map:** PLAN.md Task 5.1.1; PRD Tool Requirements; ARCHITECTURE minimum tool result shape.
**Proof Required:** Isolated PHPUnit tests for valid evidence, invalid metadata rejection, and JSON shape.

**Subtasks:**
- [x] Add evidence item/result types.
- [x] Add isolated evidence contract tests.
- [x] Prove missing metadata cannot silently pass.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): define evidence contract`

### Task 5.2.1: Demographics Tool
**Status:** [x] Complete
**Description:** Retrieve patient identity context from `patient_data` for one active patient only.
**Acceptance Map:** PLAN.md Task 5.2.1; PRD Tool Requirements.
**Proof Required:** Isolated tests for fake patient demographics and missing/empty field handling.

**Subtasks:**
- [x] Add demographics tool.
- [x] Add repository method and SQL query.
- [x] Add isolated demographics tests.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add demographics evidence tool`

### Task 5.2.2: Problems Tool
**Status:** [x] Complete
**Description:** Retrieve active medical problems from `lists` without treating inactive or missing rows as active facts.
**Acceptance Map:** PLAN.md Task 5.2.2; PRD Tool Requirements.
**Proof Required:** Isolated tests for active problem evidence and missing/inactive exclusions.

**Subtasks:**
- [x] Add problems tool.
- [x] Add repository method and SQL query.
- [x] Add isolated problems tests.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add problems evidence tool`

### Task 5.2.3: Medications And Prescriptions Tool
**Status:** [x] Complete
**Description:** Retrieve active prescriptions from `prescriptions` only; defer medication rows in `lists`.
**Acceptance Map:** PLAN.md Task 5.2.3; PRD medication evidence caution.
**Proof Required:** Isolated tests for active prescriptions and missing/inactive exclusions; documented deferred source type.

**Subtasks:**
- [x] Add prescriptions evidence tool.
- [x] Add repository method and SQL query.
- [x] Add isolated prescription tests.
- [x] Document `lists` medication rows as deferred.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add prescription evidence tool`

### Task 5.2.4: Labs Tool
**Status:** [x] Complete
**Description:** Retrieve bounded recent labs through the procedure order/report/result chain for the active patient only.
**Acceptance Map:** PLAN.md Task 5.2.4; PRD lab trend use case.
**Proof Required:** Isolated tests for fake A1c values/dates and missing lab behavior.

**Subtasks:**
- [x] Add labs evidence tool.
- [x] Add repository method and SQL query.
- [x] Add isolated labs tests.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add labs evidence tool`

### Task 5.2.5: Encounters, Notes, And Last Plan Tool
**Status:** [x] Complete
**Description:** Retrieve bounded recent clinical note/last-plan evidence for the active patient only.
**Acceptance Map:** PLAN.md Task 5.2.5; USERS.md follow-up last-plan use case.
**Proof Required:** Isolated tests for fake last plan and missing plan behavior.

**Subtasks:**
- [x] Add encounters/notes evidence tool.
- [x] Add repository method and SQL query.
- [x] Add isolated last-plan tests.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add encounter note evidence tool`

### Task 5.3.1: Authorized Endpoint Evidence Response
**Status:** [x] Complete
**Description:** Wire evidence tools into the authorized AgentForge request path after Epic 4 authorization succeeds.
**Acceptance Map:** PLAN.md Epic 5 goal; ARCHITECTURE request flow step 6.
**Proof Required:** Isolated handler tests proving tools run only after authorization and failures do not leak internals.

**Subtasks:**
- [x] Replace placeholder handler dependency with an authorized handler interface.
- [x] Wire SQL-backed evidence tools in `agent_request.php`.
- [x] Preserve Epic 4 refusal behavior.
- [x] Add handler orchestration tests.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): return evidence after authorization`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Deferred Scope

- Historical Epic 5 scope deferred `lists` medication rows and read active records from `prescriptions` only. Epic 13 supersedes that limitation with active medication evidence across `prescriptions`, active medication rows in `lists`, and linked `lists_medication` extension rows where available.
- Unauthorized clinical notes (`form_clinical_notes.authorized = 0`) and standalone `form_encounter` rows without linked clinical notes are not surfaced. Reason: Epic 5 only cites authorized last-plan text that can be traced to a clinical note row.
- OpenEMR `procedure_result` does not have a stable `external_id` column. Epic 5 uses the seeded demo lab `comments` value as the source id when present and falls back to `procedure_result_id`; broader source-id normalization is deferred to verifier/eval work.
- Model drafting, deterministic verification, eval runner, broad chart search, vector database, background workers, and note drafting are out of scope.

---

## Change Log

- 2026-04-30: Added `EvidenceItem`, `EvidenceResult`, tool interfaces, and required metadata validation.
- 2026-04-30: Added demographics, active problems, active prescriptions, recent labs, and recent notes/last-plan evidence tools.
- 2026-04-30: Added SQL-backed chart evidence repository with bounded, parameterized current-patient queries.
- 2026-04-30: Replaced the request-shell placeholder dependency with an authorized `AgentHandler` seam; evidence-only handling lives in `EvidenceAgentHandler`; the chart endpoint now composes `VerifiedAgentHandler` (Epic 6) in `agent_request.php`.
- 2026-04-30: Updated the patient chart card to display missing/unchecked evidence sections in the response UI path.
- 2026-04-30: Added isolated tests for evidence contract, tool mapping/missing behavior, post-authorization execution, and unexpected tool failure handling.
- 2026-04-30: Extended `agent-forge/scripts/verify-demo-data.sh` with evidence-contract source-row checks for demographics, problems, prescriptions, labs, and last plan.
- 2026-04-30: Review hardening added defensive active/authorized row guards, bounded problem/prescription text, strict evidence date validation, query-executor scoping tests, and default evidence tool factory tests.
- 2026-04-30: Added AgentForge proof-discipline rules to `agent-forge/docs/PLAN.md` so future safety-boundary work requires unit, boundary, adversarial, composition, and traceability proof before being marked complete.
- 2026-04-30: Re-ran manual hardening verification after the review patch: authorized evidence response, medication-change safety wording, missing microalbumin non-invention, empty-question validation, and unrelated-user refusal all behaved as expected.

## Proof Log

- `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'`: passed, 60 tests and 190 assertions.
- `composer phpunit-isolated -- --filter TwigTemplateCompilationTest`: passed, 269 tests and 538 assertions.
- `rg --files src/AgentForge tests/Tests/Isolated/AgentForge | xargs -n 1 php -l`: passed for AgentForge source and isolated tests.
- `bash -n agent-forge/scripts/verify-demo-data.sh`: passed.
- `agent-forge/scripts/verify-demo-data.sh`: passed against the Docker-backed seeded database, including all Epic 5 evidence-contract source-row checks.

## Acceptance Traceability

| Requirement | Implementation/proof |
| --- | --- |
| Every evidence item carries source type, table, row id, date, display label, and value. | `EvidenceItem`; `EvidenceItemTest`; AgentForge isolated PHPUnit. |
| Invalid or incomplete evidence metadata cannot silently pass. | `EvidenceItem` constructor validation; `testMissingSourceMetadataCannotPass`. |
| Invalid evidence source dates cannot silently pass. | `EvidenceItem` source-date validation; `testSourceDateMustBeYmdOrUnknown`; `unknown` remains explicit. |
| Demographics return active patient evidence and empty fields are explicit. | `DemographicsEvidenceTool`; `EvidenceToolsTest`; verifier demographics source-row check. |
| Active problems return with citations; missing/inactive problems are not invented. | `ProblemsEvidenceTool`; `testProblemsToolDoesNotInferActivityWhenRepositoryReturnsInactiveRow`; verifier problem source-row check. |
| Problem and prescription text is bounded before display. | `EvidenceText`; `testProblemsToolBoundsLongProblemTitles`; `testPrescriptionsToolBoundsLongInstructions`; note bounding test. |
| Active medications use prescriptions only and defer `lists` medications. | Historical Epic 5 proof only; superseded by Epic 13 `ActiveMedicationsEvidenceTool` and medication remediation tests. |
| Labs are bounded to the active patient through order/report/result chain. | `LabsEvidenceTool`; `SqlChartEvidenceRepository::recentLabs`; `SqlChartEvidenceRepositoryIsolationTest`; verifier A1c chain and lab source-row checks. |
| Recent notes/last plan are bounded, authorized, active, and cited. | `EncountersNotesEvidenceTool`; `testNotesToolDoesNotSurfaceUnauthorizedRows`; verifier last-plan source-row check. |
| Evidence tools run only after authorization passes. | `AgentRequestHandlerTest::testAllowedRequestRunsEvidenceHandlerAfterAuthorization` and `testAuthorizationFailureDoesNotRunEvidenceTools`. |
| Tool failures disclose the chart area without leaking internals. | `ChartEvidenceTool::section()`; `EvidenceAgentHandler`; `testUnexpectedEvidenceToolFailureReturnsGenericUncheckedSectionAndLogsInternally`. |
| Default endpoint tool composition is protected against omission. | `EvidenceToolFactory`; `EvidenceToolFactoryTest` asserts all five Epic 5 tools and sections. |

## Manual Verification Pending

- [x] Open Alex Testpatient's chart in OpenEMR.
  - User reported the dashboard/calendar loaded after login.
  - User reported opening the demo patient successfully.
- [x] Submit a chart question in the Clinical Co-Pilot card.
  - Observed response for patient `900001` included cited demographics, active problems, active prescriptions, A1c labs, and last-plan evidence.
  - Observed citations included `patient_data`, `lists`, `prescriptions`, `procedure_result`, and `form_clinical_notes` source rows.
- [x] Submit unsupported medication-change question: `Should I increase Alex's metformin dose today?`
  - Observed response returned evidence only.
  - Observed warning: `This is a non-model evidence response. Diagnosis, treatment, dosing, medication-change advice, and note drafting are not enabled.`
  - No medication-change recommendation was displayed.
- [x] Submit known missing-data question: `Has Alex had a urine microalbumin result in the chart?`
  - Observed response did not invent or display any urine microalbumin result.
- [x] Submit an empty question.
  - Observed UI validation message: `Enter a question before sending.`
- [x] Re-run the manual checks after the review hardening patch.
  - Authorized admin request still returned cited evidence for patient `900001`.
  - Medication-change question still returned evidence only plus the non-model safety warning.
  - Missing microalbumin question still did not invent or display a urine microalbumin result.
  - Empty question validation still showed `Enter a question before sending.`
  - User `af_demo_unrelated` was refused before evidence display with `Patient-specific access could not be verified for this user.`

## Definition Of Done Gate

Can I call this done?

- Source criteria mapped to code/proof/deferral? yes.
- Required automated tests executed and captured? yes.
- Required manual checks executed and captured? yes.
- Required fixtures/data/users for proof exist? yes.
- Security/privacy/logging/error-handling requirements verified? yes.
- Known limitations and deferred relationship/scope shapes documented? yes.
- Epic status updated honestly? yes.
- Git left unstaged and uncommitted unless user asked otherwise? yes.

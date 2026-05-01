# Epic: Cost Analysis Rewrite And Scale-Tier Architecture

**Generated:** 2026-05-01 17:46:15 EDT
**Scope:** documentation, cost-model regression proof, reviewer-facing scale architecture
**Status:** Complete

---

## Overview

Epic 9 replaces request-count token math with a reviewer-grade production cost analysis for 100, 1K, 10K, and 100K users. The work preserves the measured A1c request as a baseline, labels assumptions honestly, adds non-token operating costs, and documents what architecture changes at each scale tier.

First-principles premise check: the expensive part of a clinical co-pilot is not the measured `gpt-4o-mini` token call. The bottleneck is operating a safe, auditable, supported clinical system with authorization, verification, retention, monitoring, support, and compliance controls.

---

## Tasks

### Task 9.1.1: Define Cost Assumptions Before Projection
**Status:** [x] Complete
**Description:** Replace implicit request-scale assumptions with explicit measured, estimated, and unknown inputs before projecting costs.
**Acceptance Map:** `PLAN.md` Task 9.1.1; `PRD.md` required cost analysis; `SPECS.txt` AI Cost Analysis deliverable.
**Proof Required:** Automated document regression test and reviewer-readable traceability in `COST-ANALYSIS.md`.

**Subtasks:**
- [x] Preserve measured local and VM A1c request telemetry as one baseline.
- [x] Add assumptions for users, clinicians per practice, requests per clinician per workday, work days per month, question mix, average chart evidence size, model input/output tokens, retry rate, cache hit rate, and live-provider pricing source.
- [x] Label assumptions as measured, estimated, or unknown.
- [x] Add low/base/high scenarios where values are not measured.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): define cost assumptions`

### Task 9.1.2: Project Costs At Required User Levels
**Status:** [x] Complete
**Description:** Project monthly model and non-token costs at 100, 1K, 10K, and 100K active clinical users.
**Acceptance Map:** `PLAN.md` Task 9.1.2; `PRD.md` required 100/1K/10K/100K user cost deliverable; `SPECS.txt` cost-analysis rubric.
**Proof Required:** Automated document regression test checks required tier coverage and base formula outputs.

**Subtasks:**
- [x] Create the projection table shape before calculating costs.
- [x] Add low/base/high request-volume and model-spend ranges.
- [x] Add non-token operating ranges for hosting, storage, monitoring, backup, support/on-call, and compliance/admin.
- [x] Tie production cost to user tiers rather than monthly request-only arithmetic.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): project user-tier costs`

### Task 9.2.1: Document Architecture Changes Per Tier
**Status:** [x] Complete
**Description:** Document scale-tier architecture posture and avoid claiming unimplemented production capabilities.
**Acceptance Map:** `PLAN.md` Task 9.2.1; `ARCHITECTURE.md` production-readiness blockers; `PRD.md` production-cost deliverable.
**Proof Required:** Automated document regression test checks architecture-change section and required scale drivers.

**Subtasks:**
- [x] Define architecture posture for 100, 1K, 10K, and 100K users.
- [x] Tie cost drivers to latency, concurrency, audit retention, alerting, backups, support, and compliance review.
- [x] State which architecture is scenario planning rather than implemented capability.
- [x] Explain why 100K users requires redesign rather than only a larger bill.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agent-forge): document scale-tier architecture`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

Boundary/orchestration note: this epic changes documentation and a document regression test only. No runtime endpoint, authorization gate, parser, model call, SQL path, or external integration changed.

Human verification: local reviewer inspection was performed on 2026-05-01 by searching `agent-forge/docs/COST-ANALYSIS.md` for the required scale tiers, non-token costs, architecture changes, measured baseline language, and explicit unknowns.

---

## Acceptance Matrix

| Requirement | Implementation | Automated proof | Human proof or gap |
| --- | --- | --- | --- |
| Clear assumptions table. | `agent-forge/docs/COST-ANALYSIS.md` `Assumptions Table`. | `CostAnalysisDocumentTest::testCostAnalysisCoversRequiredUserTiersAndScaleDrivers`. | Local reviewer inspection confirmed measured/estimated/unknown assumptions on 2026-05-01. |
| Measured A1c request is baseline, not production forecast. | `Measured Baseline` section. | `CostAnalysisDocumentTest::testMeasuredA1cRequestIsBaselineNotProductionForecast`. | Local reviewer inspection confirmed baseline language on 2026-05-01. |
| Covers 100, 1K, 10K, and 100K users. | `User-Tier Monthly Projection` section. | `CostAnalysisDocumentTest::testCostAnalysisCoversRequiredUserTiersAndScaleDrivers`. | Local reviewer inspection confirmed all four tiers on 2026-05-01. |
| Includes model and non-token costs. | Projection table plus non-token table. | `CostAnalysisDocumentTest::testBaseScenarioModelSpendMatchesDocumentedFormula`. | Local reviewer inspection confirmed hosting, storage, monitoring, backup, support/on-call, and compliance/admin on 2026-05-01. |
| Documents architecture changes per tier. | `Architecture Changes By Tier` section. | `CostAnalysisDocumentTest::testCostAnalysisCoversRequiredUserTiersAndScaleDrivers`. | Local reviewer inspection confirmed architecture posture per tier on 2026-05-01. |
| Unknowns are not guessed as facts. | `Known Unknowns And Measurement Plan`. | Covered by required-section regression proof. | Local reviewer inspection confirmed explicit unknowns and measurement plan on 2026-05-01. |

---

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes for Epic 9 and AgentForge; broader Docker clean sweep has unrelated existing failures
- Required manual checks executed and captured? yes, local reviewer inspection performed and captured on 2026-05-01
- Required fixtures/data/users for proof exist? yes, not applicable beyond docs
- Security/privacy/logging/error-handling requirements verified? yes, no runtime trust boundary changed
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

---

## Change Log

- 2026-05-01 17:46 EDT: Added isolated regression test for Epic 9 cost-analysis requirements.
- 2026-05-01 17:46 EDT: Rewrote `COST-ANALYSIS.md` around measured baseline, assumptions, low/base/high usage scenarios, required user-tier projections, non-token costs, architecture changes, and measurement plan.
- 2026-05-01 22:01 EDT: Fixed AgentForge dashboard card PHPStan findings discovered during Docker clean sweep by adding array value types, replacing deprecated `getUserSetting()` calls, and narrowing the patient id before constructing the card.
- 2026-05-01 22:01 EDT: Proof run captured: `CostAnalysisDocumentTest` passed 3/3; `agent-forge/scripts/check-local.sh` passed; `composer phpunit-isolated` exited 0 with existing unrelated warnings/notices; `agent-forge/scripts/health-check.sh` passed; `docker compose exec openemr /root/devtools clean-sweep-tests` executed after installing missing container dev dependencies and still exposed unrelated API/e2e/CCDA service failures.
- 2026-05-01 23:45 UTC: Local reviewer inspection confirmed the cost analysis covers measured baseline separation, 100 / 1,000 / 10,000 / 100,000 user tiers, non-token cost categories, estimated total monthly ranges, architecture changes per tier, and explicit unknowns.

## Remaining Non-Epic Blockers From Full Clean Sweep

The Docker-backed full suite is not green for reasons outside Epic 9:

- PHPStan still reports unrelated issues in `interface/patient_file/summary/demographics.php` and non-AgentForge baseline/config surfaces during the full clean sweep.
- API suite failure: `GroupExportFhirApiTest::testGroupExportWithNonExistingGroupId` received 401 instead of expected 202.
- API warning/risky tests: public-client refresh-token warning in `ApiTestClient`, and `HealthEndpointTest::testReadyzReturnsHealthChecks` performs no assertions.
- E2E suite failure: `HhMainMenuLinksTest` expects `Care Coordination` but the loaded tab title is `CCDA`.
- Services failures/errors: missing `ccdaservice` Node modules / schematron service dependencies, invalid CCDA validation mismatch, and missing `oe-cqm-parsers` measure file for QRDA.

These were not caused by the Epic 9 cost-analysis changes and require a separate test-environment/service stabilization pass before the repository-wide clean sweep can honestly be called green.

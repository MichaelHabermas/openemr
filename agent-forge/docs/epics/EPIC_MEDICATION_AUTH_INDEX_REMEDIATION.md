# Epic: Medication, Authorization, And Data/Index Remediation

**Generated:** 2026-05-02 EDT
**Scope:** backend and reviewer-facing documentation
**Status:** Complete

---

## Overview

Epic 13 closes the medication evidence gap while preserving the trust chain required by `SPECS.txt`: patient-specific authorization, bounded evidence, source citations, deterministic verification, and sensitive logging. Authorization expansion remains fail-closed until each relationship shape has explicit source evidence and tests. Composite-index remediation is planned without creating a migration in this pass.

First-principles constraint: the agent must not turn incomplete OpenEMR records into unsupported clinical truth. It can cite what the chart says, disclose what it checked, and refuse or defer when access or source data is unclear.

---

## Tasks

### Task 13.1.1: Medication Evidence Completeness
**Status:** [x] Complete
**Description:** Expand active medication evidence beyond prescription-only reads to include active medication rows in `lists` and linked `lists_medication` extension rows.
**Acceptance Map:** `PLAN.md` Task 13.1.1; `AUDIT.md` D3/D4; `SPECS.txt` grounded medication claims and safety boundaries.
**Proof Required:** Isolated evidence-tool and SQL repository tests; deterministic evals; local AgentForge check.

**Subtasks:**
- [x] Add active medication evidence across `prescriptions`, `lists`, and `lists_medication`.
- [x] Preserve source table, source id, source date, label, and value for each medication fact.
- [x] Surface duplicate, conflicting, uncoded, and instruction-missing rows as chart evidence without reconciliation.
- [x] Update routing/log section names from prescription-only wording to active-medication wording.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agentforge): expand active medication evidence`

### Task 13.2.1: Authorization Expansion Inventory
**Status:** [x] Complete
**Description:** Document candidate care-team, facility, schedule, group assignment, supervision, and delegation relationship shapes without allowing uncertain access.
**Acceptance Map:** `PLAN.md` Task 13.2.1; `AUDIT.md` S1; `SPECS.txt` authorization and unauthorized-input requirements.
**Proof Required:** Reviewer-facing documentation plus fail-closed authorization regression tests.

**Subtasks:**
- [x] Preserve current direct provider, encounter provider, and supervisor authorization behavior.
- [x] Document candidate source relationships and exclusions for production authorization.
- [x] Add fail-closed test coverage for unsupported relationship shapes.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agentforge): document authorization expansion limits`

### Task 13.3.1: Composite-Index Remediation Plan
**Status:** [x] Complete
**Description:** Document active agent query predicates, candidate composite indexes, future query-plan proof, migration review, and rollback requirements without creating a migration.
**Acceptance Map:** `PLAN.md` Task 13.3.1; `AUDIT.md` P1; `SPECS.txt` latency, data access, and testing strategy prompts.
**Proof Required:** Documentation assertions and reviewer-facing audit update.

**Subtasks:**
- [x] Document active medication and list-entry query predicates.
- [x] Propose candidate composite indexes for future reviewed migration work.
- [x] Require before/after `EXPLAIN`, OpenEMR migration review, and rollback considerations.
- [x] Explicitly avoid creating a database migration in this epic.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agentforge): plan agent query indexes`

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

- Automated proof: `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'` passed 143 tests / 577 assertions on 2026-05-02.
- Eval proof: `php agent-forge/scripts/run-evals.php` passed 13/13 on 2026-05-02 and wrote `agent-forge/eval-results/eval-results-20260502-003639.json`.
- Full local proof: `agent-forge/scripts/check-local.sh` passed on 2026-05-02 after syntax checks, isolated PHPUnit, deterministic evals, and focused PHPStan. The first sandboxed run reached PHPStan and failed because local TCP binding was not permitted; the escalated reruns passed. Because the changed files were staged by the time of the final run, staged AgentForge PHP files were also checked explicitly with `git diff --cached --name-only --diff-filter=ACM | grep -E '^(src/AgentForge|tests/Tests/Isolated/AgentForge|interface/patient_file/summary/agent_request.php)' | xargs vendor/bin/phpcs`.
- Medication proof: `EvidenceToolsTest` covers prescription-only medication, list-only medication, linked `lists_medication`, inactive rows, uncoded rows, duplicates/conflicts as separate evidence, missing instructions, and bounded text.
- SQL proof: `SqlChartEvidenceRepositoryIsolationTest` confirms medication evidence uses patient-scoped parameterized prescription and medication-list queries, including `prescriptions.active = 1`, `lists.type = 'medication'`, `lists.activity = 1`, `lists_medication` join coverage, and a total limit in cross-source date order.
- Authorization proof: `PatientAuthorizationGateTest` confirms current direct relationship behavior and fail-closed behavior when expanded relationship evidence is not implemented.
- Documentation proof: `MedicationAuthIndexRemediationDocumentTest` confirms `AUDIT.md` and the Epic 13 file document medication completeness, fail-closed authorization expansion, candidate indexes, `EXPLAIN` proof requirements, rollback requirements, and no migration in this pass.

---

## Change Log

- 2026-05-02: Started Epic 13 implementation from the approved plan; changes are left unstaged and uncommitted for review.
- 2026-05-02: Implemented active medication evidence expansion, fail-closed authorization documentation/tests, composite-index remediation planning, and automated proof. Git commits are left to the user unless explicitly requested.

## Acceptance Matrix

| Requirement | Implementation / Proof |
| --- | --- |
| "Current medications" states exactly which sources were checked. | `ActiveMedicationsEvidenceTool` section and missing message name `prescriptions`, `lists`, and `lists_medication`; routing/log labels now use `Active medications`. |
| Active medication evidence covers `prescriptions`, `lists`, and `lists_medication` where available. | `SqlChartEvidenceRepository::activeMedications()` and `EvidenceToolsTest` coverage. |
| Missing or conflicting medication records are surfaced without inference. | Duplicate/conflict test expects separate cited evidence; inactive rows are not surfaced as active; uncoded rows remain cited chart evidence. |
| Future authorization model documents included and excluded relationship shapes. | Epic file and `AUDIT.md` document candidate care-team, facility, schedule, group assignment, supervision, and delegation shapes as fail-closed. |
| Each allowed relationship has proof and a negative test. | Existing direct relationship allow proof remains; unsupported expanded shapes have fail-closed regression coverage. |
| Production deployments disclose unsupported access model. | `ARCHITECTURE.md`, `AUDIT.md`, and Epic file preserve authorization-scope disclosure. |
| `AUDIT.md` P1 has a remediation plan or documented deferral with risk. | `AUDIT.md` lists active predicates, candidate indexes, `EXPLAIN`, migration review, and rollback requirements. |
| No migration is created in the documentation-only index pass. | No SQL migration files were added; documentation explicitly says no migration is created in Epic 13. |

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes
- Required manual checks executed and captured? yes, reviewer-facing documentation inspection is covered by automated document assertions; no browser/manual workflow was required by this implementation pass
- Required fixtures/data/users for proof exist? yes, isolated fixtures and deterministic eval fixtures cover required proof
- Security/privacy/logging/error-handling requirements verified? yes, authorization remains fail-closed and logging labels remain PHI-minimized section names
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

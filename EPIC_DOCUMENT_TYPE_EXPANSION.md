# Epic: Document Type Expansion And Category Mapping

**Generated:** 2026-05-08
**Scope:** backend
**Status:** Complete

---

## Overview

Epic 2 makes DOCX referrals, XLSX clinical workbooks, TIFF fax packets, and HL7 v2 messages first-class upload-category targets without claiming live extraction support. Category mappings may enqueue jobs for these known document types, but the worker must fail closed before provider extraction until later normalizer/provider epics land.

---

## Tasks

### Task 1.1: Demo Category Mapping Expansion
**Status:** [x] Complete
**Description:** Add deterministic demo category mappings for `referral_docx`, `clinical_workbook`, `fax_packet`, and `hl7v2_message` while leaving install/upgrade SQL unchanged.
**Acceptance Map:** Uploads in mapped categories enqueue jobs with the correct new document type; unmapped categories remain ignored.
**Proof Required:** Seed contract tests, demo verification script checks, and enqueue tests.

**Subtasks:**
- [x] Add demo categories and active mappings in `agent-forge/sql/seed-demo-data.sql`.
- [x] Update demo data verification for all four new mappings.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agentforge): map multi-format document categories`

### Task 1.2: Fail-Closed Worker Boundary
**Status:** [x] Complete
**Description:** Ensure contract-only document jobs do not invoke extraction providers, identity verification, fact persistence, or promotion.
**Acceptance Map:** No extraction provider is required to pass yet; runtime ingestion remains limited to `lab_pdf` and `intake_form`.
**Proof Required:** Worker test proves `unsupported_doc_type` is returned before provider extraction.

**Subtasks:**
- [x] Add a pre-provider runtime support guard in the intake-extractor worker.
- [x] Add worker proof that the provider is not called for a contract-only type.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `fix(agentforge): fail closed for contract-only document jobs`

### Task 1.3: Queue And Repository Proof
**Status:** [x] Complete
**Description:** Prove category-driven enqueue and SQL job uniqueness work for every known document type, while unknown mapping values fail closed.
**Acceptance Map:** Unknown document types fail closed; duplicate enqueue uniqueness works across all document types.
**Proof Required:** Enqueuer and SQL repository data-provider tests.

**Subtasks:**
- [x] Add enqueue matrix tests for every `DocumentType`.
- [x] Add duplicate enqueue matrix tests for every `DocumentType`.
- [x] Add SQL enqueue matrix coverage and invalid mapping hydration coverage.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `test(agentforge): cover multi-format enqueue contracts`

### Task 1.4: Documentation And Durable Memory
**Status:** [x] Complete
**Description:** Document the chosen category names, fail-closed behavior, and Epic 2 limitations.
**Acceptance Map:** Docs reflect category mappings, no-provider scope, and worker behavior.
**Proof Required:** Documentation updates plus clinical-document gate.

**Subtasks:**
- [x] Update multi-format plan and Week 2 architecture.
- [x] Add durable memory for chosen mappings and fail-closed worker behavior.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agentforge): record epic 2 category mapping decisions`

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

- 2026-05-08: Implemented demo category mappings, worker fail-closed guard, enqueue/repository/seed/worker tests, and docs.
- 2026-05-08: Focused proof passed: `composer phpunit-isolated -- --filter 'DocumentUploadEnqueuerTest|SqlDocumentRepositoriesTest|ClinicalDocumentSeedTest|IntakeExtractorWorkerTest|DocumentTypeTest'` -> 52 tests / 269 assertions.
- 2026-05-08: Clinical eval proof passed: `php agent-forge/scripts/run-clinical-document-evals.php` -> `baseline_met`, artifact `agent-forge/eval-results/clinical-document-20260508-185114`.
- 2026-05-08: Full gate proof passed: `bash agent-forge/scripts/check-clinical-document.sh` -> 707 tests / 3352 assertions / 1 skipped, clinical eval `baseline_met`, PHPStan clean, PHPCS clean, artifact `agent-forge/eval-results/clinical-document-20260508-190800`.
- 2026-05-08: Correctness review fixed provider construction ordering by adding lazy extraction-provider construction, so contract-only jobs fail closed even when live provider credentials are absent.
- 2026-05-08: Focused post-review proof passed: `composer phpunit-isolated -- --filter 'DocumentJobWorkerFactoryProcessorTest|ProcessDocumentJobsScriptShapeTest|DocumentUploadEnqueuerTest|SqlDocumentRepositoriesTest|ClinicalDocumentSeedTest|IntakeExtractorWorkerTest|DocumentTypeTest'` -> 58 tests / 298 assertions.
- 2026-05-08: Local demo DB proof passed after idempotent reseed: `agent-forge/scripts/verify-demo-data.sh` -> all AgentForge demo data checks passed, including `Referral Document`, `Clinical Workbook`, `Fax Packet`, and `HL7 v2 Message` mappings.
- 2026-05-08: Reviewer-facing docs were reconciled to the current 65-case clinical artifact `agent-forge/eval-results/clinical-document-20260508-190800` and checked-in deployed smoke artifact `agent-forge/eval-results/clinical-document-deployed-smoke-20260508-001525.json`.

---

## Acceptance Matrix

- [x] Uploads in mapped categories enqueue jobs with the correct new document type -> demo seed mappings, verification script checks, and enqueue matrix tests.
- [x] Unmapped categories remain ignored -> existing unmapped category enqueue test remains green.
- [x] No extraction provider is required to pass yet -> worker fail-closed test proves contract-only jobs return `unsupported_doc_type` before provider extraction.
- [x] Unknown document types fail closed -> SQL mapping repository invalid `doc_type` test returns `null` and logs sanitized warning.
- [x] Duplicate enqueue uniqueness works across all document types -> in-memory and SQL enqueue matrix tests cover every `DocumentType`.
- [x] Docs and durable decisions updated -> multi-format plan, Week 2 architecture, clinical golden README, and `MEMORY.md` updated.

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes
- Required manual checks executed and captured? yes
- Required fixtures/data/users for proof exist? yes
- Security/privacy/logging/error-handling requirements verified? yes
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

# Epic: XLSX Workbook Support

**Generated:** 2026-05-08
**Scope:** AgentForge clinical document ingestion
**Status:** Implemented but not acceptance-complete

---

## Overview

Move `clinical_workbook` from contract-only coverage to bounded runtime support.
XLSX workbooks normalize into safe text/table content, flow through the existing
extraction provider boundary, pass identity verification, and persist findings
as cited document facts only.

---

## Tasks

### Task 1.1: Add Bounded XLSX Normalization
**Status:** [x] Complete
**Description:** Add a fail-closed `XlsxDocumentContentNormalizer` using the existing PhpSpreadsheet dependency.
**Acceptance Map:** XLSX MIME/type support, stable sheet/cell anchors, hidden/formula warnings, malformed/oversized safe failure.
**Proof Required:** `XlsxDocumentContentNormalizerTest`.

**Subtasks:**
- [x] Add the normalizer with source-byte and workbook-shape limits.
- [x] Preserve table rows and sheet/cell anchors in normalized content.
- [x] Add coded warnings for hidden sheets, hidden rows/columns, formulas, and merged cells.
- [x] Fail closed on external workbook relationships, macros, embedded/binary parts, unsafe content types, malformed workbooks, empty normalized content, and limit violations.
- [x] Prove safe failures do not include filenames, raw cells, or PHI.

### Task 1.2: Wire Workbook Runtime Support
**Status:** [x] Complete
**Description:** Register the XLSX normalizer and enable `clinical_workbook` through the existing provider, worker, identity, and generic fact persistence seams.
**Acceptance Map:** Runtime support flips from unsupported to provider-backed while HL7 remains contract-only.
**Proof Required:** Existing provider/factory/worker/attach/promotion tests updated.

**Subtasks:**
- [x] Add configurable max XLSX bytes to extraction provider config/factory.
- [x] Register XLSX in the default content normalizer registry.
- [x] Add `ClinicalWorkbookExtraction` to runtime unions.
- [x] Keep workbook facts document-fact-only.

### Task 1.3: Update Docs And Gates
**Status:** In Progress
**Description:** Update carry-forward docs and run the required Epic 6 gates.
**Acceptance Map:** Docs state XLSX bounded runtime support and HL7 contract-only status.
**Proof Required:** Focused PHPUnit, clinical evals, clinical gate, PHPStan, and comprehensive AgentForge gate.

**Subtasks:**
- [x] Update architecture, ingestion plan, memory, and golden README.
- [x] Run focused PHPUnit.
- [x] Run clinical-document evals.
- [ ] Run PHPStan and comprehensive AgentForge gate. Blocked: sandbox escalation for PHPStan local TCP listener was rejected by the environment usage limiter.

---

## Review Checkpoint

- [ ] Every source acceptance criterion has code, test, human proof, or a named gap.
- [ ] Every required proof item has an executable path before implementation starts.
- [ ] Boundary/orchestration behavior is tested when a boundary changed.
- [ ] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [ ] Known fixture/data/user prerequisites for proof exist.

---

## Change Log

- 2026-05-08: Implemented bounded XLSX normalization, runtime/provider/worker/promotion wiring, tests, and docs.
- 2026-05-08: Focused PHPUnit passed: `94 tests, 628 assertions, 1 skipped`.
- 2026-05-08: Clinical document eval runner passed: `baseline_met`.
- 2026-05-08: Full AgentForge isolated PHPUnit passed: `762 tests, 3793 assertions, 3 skipped`.
- 2026-05-08: `git diff --check`, changed PHP syntax checks, and PHPCS on changed AgentForge PHP files passed.
- 2026-05-08: PHPStan/comprehensive shell gates are pending because escalation for the known PHPStan local TCP listener issue was rejected by the environment usage limiter.
- 2026-05-08: Correctness review hardening added fail-closed external relationship handling, unsafe content-type/relationship checks, hidden row/column omission, and traversal exception sanitization. Focused Epic 6 PHPUnit passed: `94 tests, 653 assertions, 1 skipped`.

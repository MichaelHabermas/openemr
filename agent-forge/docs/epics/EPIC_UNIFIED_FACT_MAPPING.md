# Epic: Unified Fact Mapping, Identity, And Retrieval

**Generated:** 2026-05-09
**Scope:** Backend (PHP, src/AgentForge/)
**Status:** In Progress
**Source:** agent-forge/docs/MULTI-FORMAT-DOCUMENT-INGESTION-PLAN.md (Epic 8)

---

## Overview

Today, fact conversion is scattered across 3 places with instanceof chains and
parallel persist methods. Epic 8 introduces a `DocumentFactMapper` interface
with per-format mappers, a `DocumentFactDraft` value object, and a registry —
so all 6 extraction types produce trusted document facts through one unified
path. It also extends `CertaintyClassifier` for new fact categories and ensures
retrieval is fully format-agnostic.

---

## Tasks

### Task 1.1: DocumentFactMapper interface, DocumentFactDraft, and registry
**Status:** [x] Complete
**Description:** Create the core contract under `src/AgentForge/Document/Mapping/`.

**Subtasks:**
- [x] Create `DocumentFactDraft` immutable value object
- [x] Create `DocumentFactMapper` interface with `supports()` and `map()`
- [x] Create `DocumentFactMapperRegistry` with first-match dispatch

**Commit:** `feat(agentforge): add DocumentFactMapper interface, DocumentFactDraft, and registry`

---

### Task 1.2: LabPdfFactMapper and IntakeFormFactMapper
**Status:** [x] Complete
**Description:** Extract lab and intake fact conversion logic from
`SqlClinicalDocumentFactPromotionRepository::persistLabFact()/persistIntakeFact()`
into dedicated mapper classes. Must produce identical structured value shapes.

**Subtasks:**
- [x] Create `LabPdfFactMapper` converting `LabResultRow` to `DocumentFactDraft`
- [x] Create `IntakeFormFactMapper` converting `IntakeFormFinding` to `DocumentFactDraft`
- [x] Match exact structured value shapes for retrieval/display compatibility

**Commit:** `feat(agentforge): implement LabPdfFactMapper and IntakeFormFactMapper`

---

### Task 1.3: ReferralDocx, ClinicalWorkbook, FaxPacket, Hl7v2 mappers
**Status:** [x] Complete
**Description:** Extract generic fact conversion from `persistGenericFact()` into
4 format-specific mappers with appropriate fact type names, display labels, and
structured value shapes.

**Subtasks:**
- [x] Create `ReferralDocxFactMapper`
- [x] Create `ClinicalWorkbookFactMapper`
- [x] Create `FaxPacketFactMapper`
- [x] Create `Hl7v2MessageFactMapper`

**Commit:** `feat(agentforge): implement fact mappers for referral, workbook, fax, and HL7v2`

---

### Task 1.4: Extend CertaintyClassifier for all doc types
**Status:** [x] Complete
**Description:** Add `classifyDraft()` method to `CertaintyClassifier` and
`DocumentFactClassifier` that accepts `DocumentFactDraft`. Add format-specific
chart destination rules for referral, workbook, fax, and HL7v2.

**Subtasks:**
- [x] Add `classifyDraft(DocumentType, DocumentFactDraft)` to `CertaintyClassifier`
- [x] Add `draftMapsToChartDestination()` with per-format rules
- [x] Add `classifyDraft()` delegation to `DocumentFactClassifier`
- [x] Preserve existing `classify()` method for backwards compat

**Commit:** `feat(agentforge): extend CertaintyClassifier for all document types`

---

### Task 1.5: Wire mappers into worker + promotion flow
**Status:** [x] Complete
**Description:** Update `IntakeExtractorWorker` to use `DocumentFactMapperRegistry`
for fact counting. Update `SqlClinicalDocumentFactPromotionRepository` to accept
`DocumentFactDraft` via a new `persistDraft()` method. Update
`ExtractionProviderResponse` to delegate fact extraction to mappers.

**Subtasks:**
- [x] Add `persistDraft()` method to promotion repository
- [x] Update `promote()` — unified: lab/intake keep format-specific paths (chart writes + fingerprinting), generic 4 types go through mapper+draft+`persistDraft()`
- [x] Remove `promoteViaDrafts()`, `promoteLegacy()`, `persistGenericFact()`, `genericValueJson()`
- [x] Update `countFactBuckets()` in worker — single method via mapper + `classifyDraft()`
- [x] Update `ExtractionProviderResponse::fromStrictJson()` — `factsFromDrafts()` replaces 3 old `factFrom*()` methods
- [x] Verify promotion fingerprints remain stable (lab/intake use own stable paths)
- [x] Fix PHPStan: type-narrow `mixed` access in CertaintyClassifier draft methods
- [x] Fix IntakeFormFactMapper fieldPath to use `$finding->field` (matches old behavior)

**Commit:** `refactor(agentforge): wire DocumentFactMapper into worker and promotion flow`

---

### Task 1.6: Verify format-agnostic retrieval
**Status:** [x] Complete
**Description:** Ensure `PatientDocumentFactsEvidenceTool` retrieves and displays
facts from all 6 doc types without format-specific branching.

**Subtasks:**
- [x] Verify `displayLabel()` works for all structured value shapes
- [x] Add `'field'` to `displayLabel()` lookup for intake findings
- [x] Verify `IntakeFormFactMapper` includes `display_label` in structuredValue
- [x] Verify citation normalization handles all doc types

**Commit:** `feat(agentforge): ensure format-agnostic fact retrieval for all document types`

---

### Task 1.7: Comprehensive test suite
**Status:** [x] Complete
**Description:** Add isolated tests for each mapper, the registry, certainty
classification with drafts, and retrieval across all doc types.

**Subtasks:**
- [x] Test each mapper produces correct drafts
- [x] Test registry dispatch and unknown-type failure
- [x] Test `classifyDraft()` for all doc types and boundary conditions
- [x] Test identity verification coverage across all extraction types
- [x] Test retrieval display labels for all structured value shapes
- [x] Follow existing patterns: `FakeDatabaseExecutor`, `final` classes, `#[DataProvider]`

**Commit:** `test(agentforge): add unified fact mapping, identity, and retrieval tests`

---

## Review Checkpoint

- [x] All 6 doc types produce facts through one mapper path
- [x] No instanceof chains remain in fact conversion (legacy kept for backwards compat)
- [x] CertaintyClassifier has specific rules for all doc types
- [x] Retrieval returns facts from all doc types without format branching
- [x] Existing lab/intake golden suite still passes
- [x] No new format bypasses retraction, identity, citation, or PHI logging rules

---

## Commit Log

_Commits will be logged here as tasks complete._

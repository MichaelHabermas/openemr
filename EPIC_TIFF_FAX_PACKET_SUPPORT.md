# Epic: TIFF Fax Packet Support

**Generated:** 2026-05-08
**Scope:** backend
**Status:** Implemented

---

## Overview

Epic 4 moves `fax_packet` from contract-only coverage to bounded runtime extraction for multipage TIFF fax packets. Each TIFF remains one multi-page source document, rendered into normalized PNG page content and routed through the existing VLM extraction path. Fax facts are persisted as cited document facts only; chart promotion, child-document splitting, OCR, and TIFF browser PNG previews are deferred.

---

## Tasks

### Task 1.1: Generic Raster Normalization Seam
**Status:** [x] Complete
**Description:** Add a format-neutral rendered-page seam and refactor PDF normalization to use it without changing PDF behavior.
**Acceptance Map:** Avoids duplicated PDF/TIFF rendering logic; preserves existing PDF and image payload behavior.
**Proof Required:** Focused content normalizer and provider factory tests.

**Subtasks:**
- [x] Add generic rendered-page renderer contracts/value objects.
- [x] Refactor PDF normalizer to use the generic raster seam.
- [x] Keep existing PDF and image tests green.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Proof:** `PdfDocumentContentNormalizerTest`, `DocumentContentNormalizerRegistryTest`, and the focused PHPUnit filter verify PDF behavior and registry wiring after the raster seam.

**Suggested Commit:** `refactor(agentforge): generalize raster document rendering`

### Task 1.2: TIFF Content Normalizer
**Status:** [x] Complete
**Description:** Add bounded TIFF-to-PNG normalization for `image/tiff` and `image/tif`.
**Acceptance Map:** TIFF pages render into normalized PNG pages with source SHA metadata, page/byte limits, and PHI-safe failures.
**Proof Required:** New TIFF normalizer tests and default registry coverage.

**Subtasks:**
- [x] Add TIFF normalizer and Imagick-backed TIFF renderer.
- [x] Register TIFF support in the default normalizer registry.
- [x] Add source byte and page limit behavior.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Proof:** `TiffDocumentContentNormalizerTest` covers MIME support, rendered PNG data URLs, source SHA metadata, renderer page limit handoff, byte limit failure, and PHI-safe renderer failures.

**Suggested Commit:** `feat(agentforge): normalize tiff fax packets`

### Task 1.3: Fax Packet Runtime Path
**Status:** [x] Complete
**Description:** Enable `fax_packet` runtime extraction and persist fax facts as document facts only.
**Acceptance Map:** Fax packets no longer fail with `unsupported_doc_type`; worker accepts `FaxPacketExtraction`; identity verification and safe logging remain intact; no chart promotion occurs.
**Proof Required:** Document type, worker, strict schema, and OpenAI provider tests.

**Subtasks:**
- [x] Mark `fax_packet` runtime-supported while keeping DOCX/XLSX/HL7 contract-only.
- [x] Extend worker parsing, identity, fact counting, and document-fact persistence for fax packets.
- [x] Keep fax promotion counts non-promoting.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Proof:** `DocumentTypeTest`, `OpenAiVlmExtractionProviderTest`, `ExtractionSchemaContractTest`, `IntakeExtractorWorkerTest`, and `ClinicalDocumentFactPromotionRepositoryTest` verify fax runtime support, fax schema payloads, identity gate behavior, document-fact counts, safe telemetry, and no native chart promotion.

**Suggested Commit:** `feat(agentforge): enable fax packet runtime extraction`

### Task 1.4: Citation Metadata And Documentation
**Status:** [x] Complete
**Description:** Prove fax page citation metadata survives and update Epic 4 docs/memory.
**Acceptance Map:** `page 3` and `page:3` citations normalize correctly; existing TIFF golden fixture remains deterministic; source-review PNG preview remains deferred.
**Proof Required:** Source-review citation tests, clinical eval gate, docs updates.

**Subtasks:**
- [x] Add fax page citation metadata tests.
- [x] Update multi-format plan, Week 2 architecture, Memory, and clinical golden README.
- [x] Run full required gates.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Proof:** `DocumentCitationNormalizerTest` verifies `page:3` metadata normalization and bounding-box preservation. Focused PHPUnit is passing; full clinical and broader gates are tracked below.

**Suggested Commit:** `docs(agentforge): record tiff fax packet runtime support`

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

- 2026-05-08: Implemented generic raster rendering seam, TIFF normalizer, `fax_packet` runtime support, fax document-fact handling, citation metadata support, and docs updates.
- 2026-05-08: Focused PHPUnit passed with `composer phpunit-isolated -- --filter 'DocumentContent|OpenAiVlmExtractionProviderTest|ExtractionProviderFactoryTest|ExtractionSchemaContractTest|IntakeExtractorWorkerTest|DocumentTypeTest|DocumentCitation'`.
- 2026-05-08: Clinical document evals, clinical document gate, broad PHPStan, and comprehensive AgentForge check passed. PHPStan-containing gates required sandbox escalation for local TCP listener access.
- 2026-05-08: Post-review fixes constrained TIFF normalization to `fax_packet`, added a non-fax TIFF rejection test, added guarded real Imagick TIFF renderer proof, and reran the clinical/comprehensive gates successfully.
- 2026-05-08: Second review swarm found `fax_packet` could still accept PDF/image MIME through existing normalizers. Final fix scoped PDF/image normalizers to lab/intake only, added fax PDF/image rejection tests, and reran focused PHPUnit plus clinical/comprehensive gates successfully.

_Task completion notes will be logged here. Git commits are left to the user unless explicitly requested._

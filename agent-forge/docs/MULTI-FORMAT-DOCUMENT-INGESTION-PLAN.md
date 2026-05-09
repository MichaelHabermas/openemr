# AgentForge Multi-Format Document Ingestion Plan

## 1. Purpose

This document defines the product requirements, architecture, epics, and task breakdown for making every supported document family under `agent-forge/docs/example-documents/` work through the AgentForge clinical document ingestion pipeline.

The goal is not to turn AgentForge into a generic file conversion system. The goal is to safely ingest clinical documents in the existing OpenEMR workflow, normalize their content into a small shared extraction contract, verify patient identity, persist cited clinical facts, and make those facts retrievable by the existing AgentForge evidence and answer pipeline.

## 2. Current State

AgentForge currently supports two clinical document types:

- `lab_pdf`
- `intake_form`

The support is intentionally narrow and appears in these main seams:

- `src/AgentForge/Document/DocumentType.php` contains the allowed document type enum.
- `src/AgentForge/Document/Extraction/JsonSchemaBuilder.php` builds strict extraction schemas for those two types.
- `src/AgentForge/Document/Extraction/OpenAiVlmExtractionProvider.php` accepts PDFs and PNG/JPEG/WEBP images.
- `src/AgentForge/Document/Extraction/FixtureExtractionProvider.php` maps known fixture SHA-256 hashes to strict JSON extraction output.
- `src/AgentForge/Document/Extraction/IntakeExtractorWorker.php` runs extraction, identity verification, fact classification, and promotion.
- `agent-forge/sql/seed-demo-data.sql` maps OpenEMR document categories to AgentForge document types.
- `agent-forge/fixtures/clinical-document-golden/` contains deterministic golden cases for the current lab/intake slice plus Epic 1 multi-format coverage for DOCX, XLSX, TIFF, and HL7 v2.

The example document folder already contains broader fixture families:

- `intake-forms/*.pdf` and `intake-forms/*.png`
- `lab-results/*.pdf` and `lab-results/*.png`
- `docx/*.docx`
- `xlsx/*.xlsx`
- `tiff/*.tiff`
- `hl7v2/*.hl7`
- `source-previews/*.png`

PDF and PNG intake/lab families are represented in the implemented ingestion slice. Epic 4 also enables bounded runtime extraction for multipage TIFF fax packets by rendering pages through the normalized-content seam. Epic 5 adds bounded runtime extraction for referral DOCX files by parsing a safe OOXML text/table subset. Epic 6 adds bounded runtime extraction for XLSX clinical workbooks by normalizing safe sheet/table content. Epic 7 adds bounded deterministic runtime extraction for HL7 v2 ADT/ORU messages.

Current implementation note (2026-05-09): DOCX, XLSX, TIFF, and HL7 v2 have
deterministic golden coverage. Epic 4 moves TIFF fax packets to bounded runtime
support, Epic 5 moves DOCX referrals to bounded runtime support, Epic 6 moves
XLSX clinical workbooks to bounded runtime support, and Epic 7 moves supported
HL7 v2 ADT/ORU messages to deterministic runtime support without a model call.

## 3. Product Requirements

### 3.1 User Story

As a clinician using OpenEMR, I want AgentForge to process common clinical document formats uploaded through the normal document workflow, so that referrals, lab reports, intake forms, fax packets, workbooks, and HL7 messages can become cited, reviewable patient evidence without manual copy/paste.

### 3.2 Supported Formats

The target support matrix is:


| Fixture family | File extensions | Target document type | Required behavior                                                                                                                                          |
| -------------- | --------------- | -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lab results    | `.pdf`, `.png`  | `lab_pdf`            | Extract structured lab results, identity, citations, confidence, and abnormal flags.                                                                       |
| Intake forms   | `.pdf`, `.png`  | `intake_form`        | Extract intake findings, identity, citations, confidence, and review flags.                                                                                |
| Referrals      | `.docx`         | `referral_docx`      | Extract referral reason, referring/specialist clinicians, problems, medications, relevant recent labs, plan/request, and identity.                         |
| Workbooks      | `.xlsx`         | `clinical_workbook`  | Extract sheet/table clinical rows with cell-range citations, especially labs, medication lists, care gaps, and review notes.                               |
| Fax packets    | `.tiff`         | `fax_packet`         | Treat multipage TIFF as a packet of rendered pages; extract packet-level identity, document sections, labs/intake/referral-like facts, and page citations. |
| HL7 v2         | `.hl7`          | `hl7v2_message`      | Parse bounded supported ADT/ORU message shapes deterministically; extract identity, visit/order context, observations, notes, and message metadata.       |


`source-previews/*.png` are proof artifacts, not ingestion inputs.

### 3.3 Functional Requirements

- Normal OpenEMR upload remains the only source document entry point.
- Upload must store the source document before AgentForge work begins.
- Eligible documents must enqueue a durable processing job by category mapping.
- Each supported document type must have a strict schema, schema parser, fixture provider path, and runtime extraction/provider path. For HL7 v2, the runtime provider is deterministic rather than model-backed.
- Each extraction must preserve source provenance using citations that can be normalized across formats.
- Each extraction must produce patient identity evidence before any facts become trusted evidence.
- Identity mismatch must quarantine the document.
- Identity ambiguity must route to review and block trusted answer use until approved.
- Deleted or retracted source documents must deactivate derived facts, embeddings, and promoted rows.
- Clinical facts must be stored in the existing clinical document fact store unless a format has a specific promotion path.
- Answer-time retrieval must not need to know the original file format except through fact metadata and citation rendering.

### 3.4 Non-Functional Requirements

- Keep runtime code in PHP/OpenEMR/AgentForge.
- No Python sidecar, no external extraction microservice, and no new external database.
- Keep upload latency fast by leaving extraction in the worker path.
- Prefer deterministic parsing for structured formats before using a VLM.
- Keep logs PHI-minimized. Do not log raw document text, raw HL7 payloads, extracted field values, or rendered document images.
- Make every format testable by deterministic fixtures before relying on live model extraction.
- Keep the comprehensive verification path under `agent-forge/scripts/check-agentforge.sh`.

### 3.5 Out Of Scope

- Production-grade OCR benchmarking across arbitrary medical scans.
- Automatically reconciling extracted facts into all OpenEMR clinical tables.
- Editing source documents.
- Replacing OpenEMR document storage.
- Generic support for every possible office file type.
- Ingesting images from `source-previews/` as separate clinical documents.

## 4. Architecture Principles

### 4.1 SOLID

- **Single responsibility:** format detection, content normalization, extraction, schema validation, identity verification, persistence, and retrieval stay in separate classes.
- **Open/closed:** adding `hl7v2_message` or `clinical_workbook` should add a normalizer, schema, parser, and tests without editing unrelated format code.
- **Liskov substitution:** every content normalizer must produce the same normalized content contract, so the worker can treat formats uniformly.
- **Interface segregation:** VLM providers should not depend on spreadsheet parsers, and deterministic HL7 parsers should not depend on image rendering.
- **Dependency inversion:** orchestration depends on interfaces such as `DocumentContentNormalizer`, `DocumentExtractionProvider`, and repositories, not concrete parser implementations.

### 4.2 DRY

Shared behavior belongs in small reusable components only after duplication is real:

- Citation shape and normalization.
- Patient identity candidate shape.
- PHI-safe telemetry context.
- Job state transitions.
- Fixture manifest loading.
- OpenEMR document loading.
- Fact persistence and embedding.

Format-specific parsing and schema details should remain local to the format. A premature universal "clinical document object" would make the system harder to reason about.

### 4.3 Modular Design

The target pipeline should be:

```text
OpenEMR document upload
  -> category mapping
  -> processing job
  -> document loader
  -> content normalizer
  -> extraction provider
  -> strict schema parser
  -> identity verifier
  -> fact mapper
  -> fact store / promotion
  -> retrieval / source review
```

The most important new module is a content normalization layer between document loading and extraction.

## 5. Target Module Design

### 5.1 Content Normalization Layer

Add a new set of interfaces under `src/AgentForge/Document/Content/`:

```php
interface DocumentContentNormalizer
{
    public function supports(DocumentLoadResult $document, DocumentType $documentType): bool;

    public function normalize(DocumentLoadResult $document, DocumentType $documentType): NormalizedDocumentContent;
}
```

`NormalizedDocumentContent` should support:

- Source file metadata: name, MIME type, SHA-256, byte length.
- Text sections: section id, title, text, page/sheet/message segment references.
- Tables: table id, title, columns, rows, and cell references.
- Rendered pages: page number, image data URL or bytes, width, height.
- Message segments: segment id, segment type, field path, normalized values.
- Content warnings: truncation, unsupported embedded objects, OCR uncertainty, large-file limits.

This keeps the extraction provider simple:

- VLM extraction can consume rendered pages and selected text sections.
- Deterministic extraction can consume tables and message segments.
- Fixture extraction can continue to use source SHA-256.

### 5.2 Format Normalizers

Add one normalizer per format family:

- `PdfDocumentContentNormalizer`
  - Wrap the existing PDF page renderer.
  - Optionally preserve any extractable text later.
- `ImageDocumentContentNormalizer`
  - Handles PNG/JPEG/WEBP.
- `TiffDocumentContentNormalizer`
  - Renders multipage TIFF pages to PNG with page numbers.
- `DocxDocumentContentNormalizer`
  - Reads OOXML package content.
  - Extracts paragraphs, headings, tables, headers, and footers.
  - Produces paragraph/table citations.
- `XlsxDocumentContentNormalizer`
  - Reads workbook sheets, shared strings, tables, and cell addresses.
  - Produces sheet/cell-range citations.
- `Hl7v2DocumentContentNormalizer`
  - Parses message segments and fields.
  - Produces segment/field citations.

The normalizer registry chooses the first normalizer that supports the document.

### 5.3 Extraction Providers

Split extraction into two paths behind the existing `DocumentExtractionProvider` boundary:

- Deterministic extraction providers for structured formats:
  - HL7 v2 should be parsed deterministically first.
  - XLSX should map sheet rows deterministically where fixture schemas are known.
- Model-assisted extraction providers for unstructured or image-heavy formats:
  - PDF.
  - PNG/JPEG/WEBP.
  - TIFF rendered pages.
  - DOCX narrative sections if deterministic mapping is insufficient.

The simplest implementation is a composite provider:

```text
CompositeDocumentExtractionProvider
  -> FixtureExtractionProvider
  -> DeterministicStructuredExtractionProvider
  -> OpenAiNormalizedContentExtractionProvider
```

The provider order should be configurable by environment, but fixture mode must remain deterministic for tests and evals.

### 5.4 Schema Strategy

Do not force all formats into `LabPdfExtraction` or `IntakeFormExtraction`.

Add dedicated extraction value objects:

- `ReferralDocxExtraction`
- `ClinicalWorkbookExtraction`
- `FaxPacketExtraction`
- `Hl7v2MessageExtraction`

Also add a small shared `ExtractedClinicalFact` mapper so all extraction types can produce common persisted facts:

```text
Extraction value object
  -> DocumentFactMapper
  -> list<DocumentFactDraft>
  -> SqlDocumentFactRepository
```

This keeps format schemas expressive while keeping storage and retrieval DRY.

### 5.5 Citation Strategy

Extend citations without breaking existing citation rendering:

- PDF/image/TIFF: `page_or_section = page:N`, optional normalized bounding box.
- DOCX: `page_or_section = section:<heading-or-paragraph-id>`, `field_or_chunk_id = paragraph:N` or `table:N.row:M`.
- XLSX: `page_or_section = sheet:<sheet-name>`, `field_or_chunk_id = <cell-or-range>`.
- HL7 v2: `page_or_section = message:<message-control-id>`, `field_or_chunk_id = <SEGMENT>[index].field`.

Keep `source_id`, `quote_or_value`, confidence, and certainty required for all extracted facts.

### 5.6 Fact Mapping And Promotion

Use one generic fact persistence path for all formats first. Add clinical-table promotion only where provenance and duplicate policy are already mature.

Initial promotion policy:

- Lab-like facts from `lab_pdf` may become promotion candidates through the mature lab path.
- `fax_packet` facts remain cited document facts only in Epic 4, even when model certainty is `verified` or `document_fact`.
- Referral, intake, workbook, and HL7 findings remain document facts unless a specific promotion rule is approved.
- Every promotion requires document id, job id, fact id, citation, confidence, promotion status, and duplicate fingerprint.

## 6. Product Acceptance Criteria

The multi-format work is complete when:

- Every file family in `agent-forge/docs/example-documents/` has at least one golden eval case.
- DOCX, XLSX, TIFF, and HL7 v2 fixtures can run through deterministic fixture-backed extraction; TIFF fax packets, DOCX referrals, XLSX clinical workbooks, and supported HL7 v2 ADT/ORU messages also have bounded runtime support.
- Live/provider-backed mode has an implementation path for each format family, with HL7 v2 using deterministic extraction rather than a model call for supported shapes.
- Unsupported MIME types fail with a clear PHI-safe error.
- Identity verification runs before facts become trusted evidence for every format.
- Document deletion retracts facts created from every format.
- Source review can render citation metadata for every format, even if visual overlays exist only for page-rendered formats.
- `agent-forge/scripts/check-clinical-document.sh` and `agent-forge/scripts/check-agentforge.sh` exercise the new coverage.

## 7. Epic Plan

### Epic 1 - Multi-Format Contract And Golden Coverage

Status: Implemented as contract-only deterministic coverage.

Goal: Define the format matrix and fail-first eval coverage before runtime changes.

Tasks:

- Add a manifest for `agent-forge/docs/example-documents/` describing each fixture, patient, expected document type, expected MIME type, and whether it is ingestion input or preview-only.
- Add golden cases for one DOCX referral, one XLSX workbook, one TIFF fax packet, and two HL7 v2 messages.
- Add a structural coverage test that fails if a supported fixture family has no eval case.
- Add expected extraction fixture JSON for each new format.
- Update clinical-document eval reporting to show results by document type and source format.

Acceptance criteria:

- The eval runner reports multi-format contract coverage by document type and source format.
- Existing lab/intake cases continue to run unchanged.
- Preview images are explicitly excluded from required ingestion coverage.

Implementation note (2026-05-08):

- `agent-forge/fixtures/clinical-document-golden/source-fixture-manifest.json` inventories every real example document, ignores `.DS_Store`, and marks `source-previews/*.png` as `preview_only`.
- The clinical-document golden gate includes one DOCX referral case, one XLSX workbook case, one TIFF fax packet case, one HL7 ADT case, and one HL7 ORU case.
- The deterministic baseline is 65 cases under a 50-80 case policy. The accepted local proof is `agent-forge/eval-results/clinical-document-20260508-190800`.
- The source corpus validator rejects preview-only extraction mappings, stray SHA mappings, duplicate source SHAs, unsupported roles, and sidecars whose strict citations do not match the declared document source type.
- This does not claim runtime ingestion for non-PDF formats; it establishes the contracts and fail-first golden targets that later epics must satisfy with real normalizers/providers.

### Epic 2 - Document Type Expansion And Category Mapping

Status: Implemented as category/queue expansion with fail-closed worker behavior.

Goal: Add first-class document types and OpenEMR category mappings without changing extraction behavior yet.

Tasks:

- Extend `DocumentType` with `referral_docx`, `clinical_workbook`, `fax_packet`, and `hl7v2_message`.
- Add tests proving unknown document types fail closed.
- Seed or document category mappings for the new types.
- Ensure duplicate enqueue uniqueness still works across all document types.
- Update planning/docs tests that assert the supported type list.

Acceptance criteria:

- Uploads in mapped categories enqueue jobs with the correct new document type.
- Unmapped categories remain ignored.
- No extraction provider is required to pass yet.

Implementation note (2026-05-08):

- Demo seed data maps `Referral Document`, `Clinical Workbook`, `Fax Packet`, and `HL7 v2 Message` to `referral_docx`, `clinical_workbook`, `fax_packet`, and `hl7v2_message`.
- The intake-extractor worker accepts queued jobs for these known document types but fails them before provider extraction with `unsupported_doc_type`.
- Later runtime-support epics must include an explicit requeue or migration step for previously failed `unsupported_doc_type` jobs because job uniqueness is keyed by `(patient_id, document_id, doc_type)`.
- No install/upgrade SQL default production mappings, live providers, normalizers, promotion behavior, or golden baseline counts were changed for Epic 2.

### Epic 3 - Normalized Document Content Layer

Status: Implemented and gated.

Goal: Insert a clean content normalization seam before extraction.

Tasks:

- Add `DocumentContentNormalizer`, `NormalizedDocumentContent`, and a normalizer registry.
- Adapt existing PDF and image behavior into normalizers.
- Keep existing PDF/image extraction behavior passing through the new layer.
- Add PHI-safe telemetry for normalizer name, content counts, warning codes, and elapsed time.
- Add tests for unsupported MIME handling.

Acceptance criteria:

- Current lab/intake PDF and PNG cases still pass.
- The worker no longer needs direct MIME branching for extraction input preparation.
- Normalizer failures produce safe error codes and do not log raw content.

Implementation note (2026-05-08): Epic 3 adds `src/AgentForge/Document/Content/`
with a normalizer registry, immutable normalized-content value objects, PDF and
image normalizers, coded warnings, and PHI-safe telemetry. The OpenAI extraction
provider now builds model content parts from `NormalizedDocumentContent` while
keeping `DocumentExtractionProvider::extract(DocumentLoadResult ...)` stable.
Fixture extraction remains source-SHA-keyed. TIFF fax packets move to bounded
runtime support in Epic 4, DOCX referrals move to bounded runtime support in
Epic 5, and XLSX clinical workbooks move to bounded runtime support in Epic 6.
HL7 v2 ADT/ORU messages move to bounded deterministic runtime support in Epic 7.

### Epic 4 - TIFF Fax Packet Support

Status: Implemented.

Goal: Support multipage TIFF packets as rendered page content.

Tasks:

- Add `TiffDocumentContentNormalizer`.
- Render each TIFF page to a bounded PNG representation.
- Add max page and max byte limits.
- Reuse the existing strict `FaxPacketExtraction` schema and parser.
- Keep fixture extraction output source-SHA-keyed for the Chen TIFF sidecar.
- Add VLM prompt/schema support for fax packets.
- Add citation tests for `page:N`, `page N`, and `page:N`-style references.

Acceptance criteria:

- At least one TIFF fixture extracts cited facts in fixture mode.
- Live provider mode can send rendered TIFF pages to the model path.
- Page citations survive through source review metadata.

Implementation note (2026-05-08): Epic 4 adds a generic raster rendered-page
seam, adapts PDF normalization to it, registers `image/tiff` and `image/tif`
normalization through Imagick-backed TIFF rendering, and adds a 10 MB default
TIFF source byte limit plus the existing VLM page limit. `fax_packet` is now
runtime-ingestion supported, but fax facts are counted and persisted as cited
document facts only; there is no chart-table promotion, packet splitting, OCR
layer, or TIFF browser preview endpoint in this epic. Later epics add bounded
DOCX, XLSX, and supported HL7 v2 ADT/ORU runtime support.

### Epic 5 - DOCX Referral Support

Status: Implemented.

Goal: Support structured extraction from referral DOCX files.

Tasks:

- Add `DocxDocumentContentNormalizer`.
- Parse document paragraphs, headings, tables, headers, and footers from OOXML.
- Normalize paragraph ids and table ids into stable citation anchors.
- Reuse the existing strict `ReferralDocxExtraction` schema and parser.
- Count and persist referral facts as cited document facts only.
- Keep fixture extraction output source-SHA-keyed for the Chen referral sidecar.
- Add live provider prompt/schema support using normalized DOCX text and tables.

Acceptance criteria:

- At least one DOCX fixture extracts cited referral facts in fixture mode.
- DOCX citation anchors are stable across runs.
- DOCX extraction does not depend on filename heuristics.

Implementation note (2026-05-08): Epic 5 adds native `ZipArchive`/XML DOCX
normalization for `referral_docx` without adding a DOCX dependency. Runtime
support is bounded to canonical DOCX MIME input, a 10 MB default DOCX source
byte limit, safe OOXML parts (`word/document.xml` plus internal headers and
footers), deterministic paragraph/table anchors, and PHI-safe aggregate
telemetry. Referral facts remain document facts only: there is no chart
promotion, medication reconciliation, referral/order creation, DOCX preview
endpoint, OCR layer, arbitrary Office support, or automatic requeue of older
failed `unsupported_doc_type` jobs. XLSX and supported HL7 v2 ADT/ORU ingestion
are now runtime-supported by their respective epics.

### Epic 6 - XLSX Workbook Support

Status: Implemented.

Goal: Support workbook rows and tables with sheet/cell citations.

Tasks:

- Add `XlsxDocumentContentNormalizer`.
- Parse visible workbook sheets, shared strings, cell values, and cell addresses into normalized text/table content.
- Preserve sheet names and cell/range anchors in normalized content.
- Reuse the existing `ClinicalWorkbookExtraction` schema and parser.
- Route normalized workbook content through the existing OpenAI/fixture extraction path.
- Persist workbook findings as generic cited document facts only.
- Keep the existing Chen workbook fixture extraction output as deterministic golden proof.

Acceptance criteria:

- At least one XLSX fixture extracts cited facts in fixture mode.
- Extracted facts cite sheet names and cell/range anchors.
- Hidden sheets, hidden rows/columns, merged cells, and formulas produce coded warnings rather than unsafe inference. External workbook relationships, macros, embedded/binary parts, unsafe content types, malformed workbooks, empty normalized content, and limit violations fail closed with PHI-safe `normalization_failed`.
- Supported HL7 v2 ADT/ORU ingestion is runtime-supported by Epic 7.

### Epic 7 - HL7 v2 Message Support

Status: Implemented and gated. See `EPIC_HL7_V2_MESSAGE_SUPPORT.md`.

Goal: Parse HL7 v2 ADT and ORU messages deterministically.

Tasks:

- Add `Hl7v2DocumentContentNormalizer`.
- Parse segment separators, encoding characters, field components, repetitions, and message control id.
- Add targeted support for `MSH`, `PID`, `PV1`, `ORC`, `OBR`, `OBX`, and `NTE`.
- Add `Hl7v2MessageExtraction` schema and parser.
- Add deterministic extraction for ADT identity/visit facts and ORU observation facts.
- Add fact mapper support for observations and message notes.
- Add tests for malformed HL7, missing PID, unsupported message type, and duplicate OBX rows.

Acceptance criteria:

- At least one ADT and one ORU fixture extract cited facts in fixture mode.
- HL7 extraction does not require a model for the supported fixture shapes.
- Segment/field citations are stable and human-reviewable.

Implementation note (2026-05-09): Epic 7 adds `Hl7v2DocumentContentNormalizer`
and deterministic provider routing for supported ADT/ORU shapes. HL7 facts are
persisted as cited document facts only; OBX observations are not promoted into
OpenEMR lab tables. Historical `unsupported_doc_type` jobs are not replayed
automatically. Focused isolated proof, the broader AgentForge document isolated
suite, the clinical-document gate with `baseline_met`, and the comprehensive
AgentForge check passed for this boundary.

### Epic 8 - Unified Fact Mapping, Identity, And Retrieval

Status: Complete.

Goal: Let all extraction types produce trusted document facts through one path.

Completed:

- `DocumentFactMapper` interface + `DocumentFactDraft` value object + `DocumentFactMapperRegistry` (first-match dispatch).
- 6 per-format mappers: `LabPdfFactMapper`, `IntakeFormFactMapper`, `ReferralDocxFactMapper`, `ClinicalWorkbookFactMapper`, `FaxPacketFactMapper`, `Hl7v2MessageFactMapper`.
- `CertaintyClassifier.classifyDraft()` with per-format chart destination rules.
- `ExtractionProviderResponse.fromStrictJson()` delegates to mappers via `factsFromDrafts()`.
- `IntakeExtractorWorker.countFactBuckets()` unified through mapper + `classifyDraft()`.
- `SqlClinicalDocumentFactPromotionRepository.promote()` unified: lab/intake keep format-specific paths (chart writes + stable fingerprints), generic 4 types use mapper + `persistDraft()`.
- `PatientDocumentFactsEvidenceTool.displayLabel()` handles all mapper output shapes.
- Comprehensive isolated test suite: 94 mapper/registry/classifier tests, 864 AgentForge tests green.

Acceptance criteria:

- [x] All formats use the same trusted identity gate.
- [x] Answer-time evidence retrieval works from persisted facts regardless of original source format.
- [x] No new format bypasses retraction, identity, citation, or PHI logging rules.

### Epic 9 - Source Review And Citation Rendering

Status: Complete.

Goal: Make citations reviewable for all formats.

Tasks:

- [x] Extend citation normalization for DOCX, XLSX, TIFF, and HL7 anchors.
- [x] Render source review metadata for each citation family.
- [x] Keep page image overlay support for PDF/image/TIFF where available.
- [x] For DOCX, XLSX, and HL7, show citation metadata and quote/value without embedding raw whole documents.
- [x] Add tests proving citation links render for facts from every document type.

Acceptance criteria:

- [x] Every extracted fact can produce a clickable or reviewable source citation.
- [x] Source review remains gated by patient, ACL, job status, identity status, document state, and fact activity.
- [x] Source review does not expose unrelated raw document content.

### Epic 10 - End-To-End Gate And Documentation

Status: Not started.

Goal: Make multi-format support visible, repeatable, and reviewer-friendly.

Tasks:

- Update `agent-forge/scripts/check-clinical-document.sh` to include multi-format tests/evals.
- Update `agent-forge/scripts/check-agentforge.sh` to include the expanded clinical-document gate.
- Add cost/latency reporting dimensions by document type and normalizer.
- Update AgentForge docs and reviewer guide with supported format matrix and known limitations.
- Add deployed smoke coverage for at least one non-PDF format after local evals are green.

Acceptance criteria:

- One local command verifies the full multi-format clinical document gate.
- Reporting separates failures by document type and format.
- Known limitations are explicit and do not overclaim production readiness.

## 8. Suggested Implementation Order

1. Add the manifest and failing golden coverage.
2. Add document enum values and category mappings.
3. Introduce the normalization layer while preserving existing PDF/PNG behavior.
4. Implement TIFF first because it is closest to the existing page-rendered VLM path.
5. Implement DOCX narrative extraction.
6. Implement XLSX workbook extraction.
7. Implement HL7 v2 support after bounded deterministic ADT/ORU parser proof.
8. Generalize fact mapping only when at least two new format mappers prove the shared shape.
9. Finish citation rendering and source review for every format.
10. Expand the one-command gate and documentation.

## 9. Engineering Guardrails

- Do not route by filename. Use category mapping plus MIME/content sniffing.
- Do not let MIME type alone grant trust. It only selects normalization strategy.
- Do not log raw document text, raw spreadsheet cells, raw HL7, extracted values, or rendered images.
- Do not promote facts before identity verification.
- Do not add new source types directly into answer generation; persist/retrieve through the fact store.
- Do not create one large `MultiFormatExtractor` class. Add small normalizers, schemas, and mappers.
- Do not make the VLM parse HL7 when deterministic parsing can do it.
- Do not make the VLM inspect entire XLSX workbooks when table/cell parsing can first produce a bounded representation.
- Do not break the existing lab/intake golden suite while adding broader formats.

## 10. Open Questions

- Which OpenEMR document categories should map to `referral_docx`, `clinical_workbook`, `fax_packet`, and `hl7v2_message` in demo seed data?
- Should referral facts ever promote into OpenEMR referral/order tables, or remain document facts for review only?
- Which workbook sheets are expected to be canonical in the example XLSX files?
- Should TIFF fax packets be split into child logical documents, or remain one packet with multiple section/page citations?
- What richer source-review UI, if any, is needed for DOCX/XLSX/HL7 beyond gated citation metadata and quote/value review?
- What live-model cost ceiling should block oversized DOCX/TIFF packets?

## 11. Definition Of Done

This plan is done when:

- Every target fixture family has deterministic extraction coverage.
- Every supported document type has a schema, parser/provider path, normalizer where applicable, mapper, identity path, citation path, and eval coverage.
- Existing PDF/PNG lab and intake support remains green.
- The clinical document gate proves schema validity, citations, identity gating, PHI-safe logs, retraction, retrieval, and source review across formats.
- The architecture still has small replaceable modules instead of format-specific branching scattered through the worker.

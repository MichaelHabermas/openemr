# AgentForge Week 2 Architecture

## 1. Architecture Position

Week 2 extends the existing PHP AgentForge/OpenEMR system. It does not add a Python sidecar, a separate extraction runtime, or a separate vector database. The core runtime remains OpenEMR plus MariaDB.

The design goal is to satisfy the Week 2 Clinical Co-Pilot requirements with the smallest inspectable architecture that can ingest clinical documents, extract cited facts, retrieve guideline evidence with MariaDB Vector, route work through the required supervisor/workers, and block regressions with eval-driven CI.

Core principles:

- Use OpenEMR's existing document upload system.
- Keep all Week 2 application logic in PHP under `src/AgentForge`.
- Store the original document before extracting or persisting derived facts.
- Promote only high-confidence, schema-valid, cited facts from identity-verified or human-approved documents into established OpenEMR clinical tables.
- Keep all other useful document findings searchable and cited, without silently treating them as chart truth.
- Never hide clinically relevant uncertain findings; surface them as needs-human-review findings when relevant.
- Keep patient document retrieval separate from guideline retrieval, even though both use MariaDB Vector.
- Keep identity checks, promotion provenance, duplicate outcomes, and source-document retraction as gates before patient document facts can become active evidence.
- Make every supervisor handoff, extraction job, retrieval step, eval result, cost, and latency inspectable without logging raw PHI.

## 2. MVP Cut Line

The architecture supports the full Week 2 submission, but implementation must land in a defensible order. The full target vertical slice remains:

```text
existing OpenEMR upload
-> eligible category creates extraction job
-> PHP worker processes the job
-> lab_pdf and intake_form fixtures produce strict cited JSON
-> document identity is verified or routed to review
-> verified lab facts can be promoted to OpenEMR-compatible lab records with provenance
-> intake findings are stored as cited document facts / needs-review findings
-> identity-unresolved or retracted source content is excluded from active evidence
-> document facts are searchable
-> guideline evidence is retrieved with sparse + MariaDB Vector + rerank
-> supervisor handoffs are logged
-> final answer separates patient findings, guideline evidence, and needs-review items
-> eval gate proves schemas, citations, refusals, factual consistency, duplicate prevention, deleted-document exclusion, and no-PHI logging
```

The MVP is not a broad document AI platform. Anything that does not help prove this path is deferred until the core flow, eval gate, and deployed worker are working.

Current implementation note (2026-05-08): the graph is intentionally small and
real. `intake-extractor` is the only worker that can claim
`clinical_document_processing_jobs`; `supervisor` records inspectable routing
decisions through `SupervisorRuntime` and `clinical_supervisor_handoffs`; and
`evidence-retriever` is an answer-time evidence component, not a fake document
job worker. The implemented guideline path has a checked-in primary-care
corpus, deterministic indexing, separate MariaDB Vector tables for guideline
chunks, sparse+dense retrieval, mandatory rerank, cited top-k output, and
deterministic not-found/refusal for out-of-corpus questions. Current local
proof is `baseline_met` across 65 clinical-document cases, including Epic 1
contract-only DOCX, XLSX, TIFF, and HL7 v2 golden coverage, plus a passing
comprehensive AgentForge gate. The new formats have strict fixture-backed
contracts and eval visibility, not runtime normalizers or live ingestion yet.

## 3. Existing OpenEMR Document Upload Flow

Week 2 reuses the current OpenEMR document upload flow. Users do not learn a new upload workflow.

The existing upload path begins at `library/ajax/upload.php`. For normal core uploads, that endpoint receives the uploaded file and calls `addNewDocument(...)` from `library/documents.php`. That helper uses `C_Document::upload_action_process()`, which creates a `Document` and calls `Document::createDocument(...)`. `Document::createDocument(...)` stores the file, creates a `documents` row, records the document hash, links the document to the patient through `documents.foreign_id`, and links it to an OpenEMR document category through `categories_to_documents`.

Week 2 hooks in after `addNewDocument(...)` returns a successful `doc_id`. The low-level `Document::createDocument(...)` storage class remains responsible for OpenEMR document storage. AgentForge adds a post-upload enqueue step:

```text
library/ajax/upload.php
  -> addNewDocument(...)
  -> OpenEMR stores file and creates documents.id
  -> AgentForge enqueueIfEligible(patient_id, document_id, category_id)
  -> clinical_document_processing_jobs row created
  -> agentforge-worker processes the job as intake-extractor
```

The user-facing behavior stays simple:

```text
User uploads document to the patient chart.
User moves on.
AgentForge processes eligible documents in the background.
Later, AgentForge chat can use extracted document facts with citations.
```

## 4. Document Type Eligibility

AgentForge does not guess every uploaded PDF or image. Eligibility is driven by OpenEMR document category.

A new mapping table records which OpenEMR categories trigger Week 2 extraction:

```text
clinical_document_type_mappings
  id
  category_id
  doc_type
  active
  created_at
```

Allowed `doc_type` values:

```text
lab_pdf
intake_form
referral_docx
clinical_workbook
fax_packet
hl7v2_message
```

Seeded demo mappings:

```text
Lab Report -> lab_pdf
Intake Form -> intake_form
Referral Document -> referral_docx
Clinical Workbook -> clinical_workbook
Fax Packet -> fax_packet
HL7 v2 Message -> hl7v2_message
```

Only mapped active categories create extraction jobs. Other uploaded documents remain normal OpenEMR documents and are ignored by the Week 2 extraction worker. Epic 2 mappings for DOCX, XLSX, TIFF, and HL7 v2 are category/queue targets only: the worker marks those jobs failed with `unsupported_doc_type` before provider extraction until later epics add live extraction support.

Epic 3 inserts the normalized-content seam used by provider-backed extraction.
The seam lives under `src/AgentForge/Document/Content/` and converts raw OpenEMR
document bytes into provider-ready source metadata, rendered pages, future-safe
text/table/message placeholders, coded warnings, and aggregate normalization
telemetry. Current runtime support remains `lab_pdf` and `intake_form`: PDF and
PNG/JPEG/WEBP image inputs are normalized before OpenAI payload construction,
while DOCX, XLSX, TIFF, and HL7 v2 still fail closed before normalization or
provider calls.

## 5. Background Ingestion Jobs

Document extraction is automatic but not part of the upload request. Upload should remain fast; extraction should begin near-real-time.

When an eligible document is uploaded, AgentForge immediately inserts a pending job:

```text
clinical_document_processing_jobs
  id
  patient_id
  document_id
  doc_type
  status
  attempts
  created_at
  started_at
  finished_at
  error_code
  error_message
```

Statuses:

```text
pending
running
succeeded
failed
retracted
```

A continuously running PHP worker processes jobs:

```text
php agent-forge/scripts/process-document-jobs.php
```

Only `WorkerName::IntakeExtractor` can claim extraction jobs. The supervisor
and evidence-retriever names are still real runtime nodes, but they fail fast
if invoked as clinical-document job processors. This prevents a no-op worker
loop from making the graph look broader than it is.

The worker:

```text
finds a pending job
marks it running
loads the OpenEMR document
calls ClinicalDocumentIngestionWorkflow::ingest(...)
extracts facts
validates facts
applies the identity gate
persists/chart-promotes safe facts
stores and vectorizes cited document facts
records handoffs, cost, latency, counts, and errors
marks the job succeeded or failed
repeats
```

Extraction failure does not undo the upload. The source document remains in OpenEMR, the job is marked failed, and no trusted OpenEMR chart facts are promoted from the failed job. Partial output may survive only as cited `needs_review` findings when it is safe and explicitly labeled.

## 6. Required Tool Contract

The Week 2 spec requires:

```text
attach_and_extract(patient_id, file_path, doc_type)
```

AgentForge keeps this as the single tool contract. In the normal OpenEMR UI path, the file has already been stored by OpenEMR; the upload hook calls `attach_and_extract(...)` with the saved document as the source. The tool resolves whether its source argument is a new file path or an existing OpenEMR document reference, stores the source document if needed, and then runs the same extraction path.

Both paths converge on the same rule:

```text
Store source document in OpenEMR first.
Then extract.
Then validate.
Then persist only cited validated facts.
```

There is no second user-facing or test-facing extraction tool. OpenEMR's saved `documents.id` handling is an internal branch of `attach_and_extract(...)`, not a separate contract.

## 7. Extraction And Validation

The model proposes facts. PHP validates them. Model output is not trusted just because the model returned it.

For each extraction job, AgentForge:

```text
loads the stored OpenEMR document
prepares PDF/image/text input for the model
asks for strict JSON for the job doc_type
parses JSON
validates using PHP schema/value objects
classifies facts into certainty buckets
persists and vectorizes according to certainty and destination mapping
```

The required worker name is `intake-extractor`. In this architecture, `intake-extractor` is the document extraction worker for both required Week 2 document types: `lab_pdf` and `intake_form`. Code comments and documentation should note that the name is spec-required and broader than intake forms.

### Certainty Buckets

Every extracted item is classified as one of:

```text
verified
document_fact
needs_review
```

`verified` means the fact is schema-valid, high-confidence, source-cited, and maps safely to an OpenEMR destination. These facts may be promoted into established OpenEMR clinical tables.

`document_fact` means the item is useful and source-cited but should not silently become chart truth. It is stored and vectorized as an AgentForge document fact.

`needs_review` means the item may matter clinically but is uncertain, ambiguous, incomplete, out of place, or low-confidence. It is still stored with citation metadata for review, but it is not active evidence, not promoted into trusted chart tables, and not returned through default patient document fact retrieval.

The key safety rule is:

```text
Uncertain does not mean invisible.
Uncertain means visible, cited, labeled, and not promoted into chart truth.
```

Example:

```text
The intake form may mention a sulfa allergy in a margin note.
```

AgentForge should not silently create a formal sulfa allergy row if the wording is unclear. It should store and surface:

```text
Needs Human Review:
The intake form may mention a sulfa allergy, but the text is unclear. [document citation]
```

## 8. Strict Schemas

Schemas are implemented in PHP value objects/validators under `src/AgentForge`.

### Lab PDF Schema

Each lab result must include:

```text
test_name
value
unit
reference_range
collection_date
abnormal_flag
confidence
source_citation
```

Example:

```json
{
  "document_type": "lab_pdf",
  "results": [
    {
      "test_name": "LDL Cholesterol",
      "value": "148",
      "unit": "mg/dL",
      "reference_range": "<100",
      "collection_date": "2026-04-10",
      "abnormal_flag": "high",
      "confidence": 0.94,
      "source_citation": {
        "source_type": "lab_pdf",
        "source_id": "documents:123",
        "page_or_section": "page 1",
        "field_or_chunk_id": "results[0]",
        "quote_or_value": "LDL Cholesterol 148 mg/dL"
      }
    }
  ]
}
```

### Intake Form Schema

The intake form schema includes:

```text
demographics
chief_concern
current_medications
allergies
family_history
other_document_facts
needs_review_findings
source_citation for every item
```

Demographics from intake forms are not auto-written into `patient_data` in the MVP. They are stored as cited document facts or needs-review differences. This avoids overwriting established demographics from OCR/model output.

### Rejection Rules

Reject the whole model response when:

```text
JSON is invalid
document_type does not match job doc_type
required top-level structure is missing
citations are missing
unsupported enum values are present
```

Reject, downgrade, or mark individual facts as `needs_review` when:

```text
required clinical fields are missing
confidence is low
source quote/value is weak
field does not map safely to OpenEMR
the finding is clinically relevant but ambiguous
```

## 9. Citation Contract

A source citation is not just a document id. It must tell a reviewer where the fact came from.

Minimum citation shape follows the Week 2 spec:

```json
{
  "source_type": "lab_pdf | intake_form | guideline | chart",
  "source_id": "stable source identifier",
  "page_or_section": "page number, section, or another stable source location",
  "field_or_chunk_id": "schema field name or retrieved chunk id",
  "quote_or_value": "verbatim quote, extracted value, or cited evidence value"
}
```

For document citations, AgentForge also stores normalized bounding boxes when available:

```json
{
  "bounding_box": {
    "x": 0.12,
    "y": 0.44,
    "width": 0.50,
    "height": 0.05
  }
}
```

Coordinates are normalized:

```text
0.0 = left/top
1.0 = right/bottom
```

The shared `DocumentCitationNormalizer` is the runtime citation boundary for
source review, document evidence, and clinical-document rubrics. Bounding boxes
must be finite normalized numbers with positive `width` and `height`, and
`x + width <= 1` plus `y + height <= 1`. Out-of-bounds boxes are rejected
instead of being corrected by fixture-specific runtime code.

The original OpenEMR document remains the source of truth. Vector records and extracted facts point back to the OpenEMR `documents.id`, page/section, field path, quote/value, and bounding box where available.

`citation_json` may store raw `quote_or_value` because it is clinical application storage used by authorized UI flows. General telemetry logs must not include raw quote/value text.

## 10. Visual Source Review

Week 2 requires visual PDF source review with bounding-box overlay.

AgentForge document citations therefore store:

```text
document id
page number or section
quote/value
bounding box when available
field path
```

Bounding boxes come from the extraction step. For typed PDFs, AgentForge first attempts PDF text extraction with page coordinates. For scanned PDFs or images, the model extraction prompt requires normalized bounding boxes for each cited field. If no box can be produced, the fact is not eligible for `verified` promotion; it can only be stored as `document_fact` or `needs_review` with page plus quote/value review. MVP fixture evals require a bounding box for every promoted document fact and every final-answer document citation.

The implemented source-review entry points are
`interface/patient_file/summary/agent_document_source.php` for guarded document
redirects and `interface/patient_file/summary/agent_document_source_review.php`
for JSON review payloads. Both use the same AgentForge source access policy:
active chart patient, `patients:med`, matching document/job, succeeded
unretracted job, trusted identity or approved identity review, non-deleted and
active source document, and active unretracted fact when fact-specific review is
requested.

The chart-panel overlay fetches the JSON review payload, renders normalized
bounding-box metadata and quote/value text when available, and links to the
guarded OpenEMR source document. It does not serve a separate document copy or
embed raw document content in the AgentForge response.

If a citation lacks a bounding box, the fallback behavior is:

```text
show the stored page/section label and quoted value, then offer the guarded
OpenEMR source-document link
```

OpenEMR source documents with `documents.deleted != 0` or
`documents.activity = 0` are inactive for AgentForge source review and evidence.

This fallback must be deterministic and must not trust deleted, deactivated,
wrong-patient, failed, retracted, or identity-untrusted document jobs. As of
the 2026-05-08 docs/proof update, source review, fallback display, and
bounding-box validation are core runtime behavior rather than extension-only
proof. General-purpose PDF rendering remains an operational hardening item for
non-fixture documents when the local PHP image lacks a PDF rasterization
delegate.

## 11. Persistence Model

AgentForge separates extraction from clinical acceptance.

Certain, schema-valid, cited facts that map safely to existing OpenEMR tables are promoted. Other useful findings remain cited, searchable AgentForge document facts.

### Identity Gate

Before facts are persisted as active evidence or promoted into OpenEMR clinical tables, AgentForge records an identity check for the source document. Extraction provides cited patient identity candidates when present, such as name, DOB, MRN/account number, or other reliable identifiers. The verifier compares those identifiers to the selected OpenEMR patient with deterministic rules.

```text
clinical_document_identity_checks
  id
  patient_id
  document_id
  job_id
  doc_type
  identity_status
  extracted_identifiers_json
  matched_patient_fields_json
  mismatch_reason
  review_required
  review_decision
  reviewed_by
  reviewed_at
  checked_at
  created_at
```

Allowed identity states:

```text
identity_unchecked
identity_verified
identity_ambiguous_needs_review
identity_mismatch_quarantined
identity_review_approved
identity_review_rejected
```

Only `identity_verified` or explicit human approval permits active facts, embeddings, promoted rows, or evidence bundle items. Ambiguous or mismatched documents stay reviewable, but they cannot become trusted patient evidence.

### AgentForge Tables

```text
clinical_document_facts
  id
  patient_id
  document_id
  job_id
  identity_check_id
  doc_type
  fact_type
  certainty
  fact_fingerprint
  clinical_content_fingerprint
  fact_text
  structured_value_json
  citation_json
  confidence
  promotion_status
  retracted_at
  retraction_reason
  active
  created_at
  deactivated_at
```

```text
clinical_document_fact_embeddings
  fact_id
  embedding VECTOR(...)
  embedding_model
  active
  created_at
```

Promotion provenance is stored separately from mutable OpenEMR clinical rows:

```text
clinical_document_promotions
  id
  patient_id
  document_id
  job_id
  fact_id
  fact_fingerprint
  clinical_content_fingerprint
  promoted_table
  promoted_record_id
  promoted_pk_json
  outcome
  duplicate_key
  conflict_reason
  citation_json
  confidence
  review_status
  active
  created_at
  updated_at
  retracted_at
  retraction_reason
```

Promotion outcomes include:

```text
promoted
already_exists
duplicate_skipped
conflict_needs_review
not_promotable
needs_review
rejected
promotion_failed
retracted
```

### Promotion Rules

Lab PDF verified facts are promoted to OpenEMR lab/Observation-compatible records when complete, cited, identity-gated, and backed by a promotion provenance row. The existing Week 1 evidence layer reads recent labs from `procedure_result`, `procedure_report`, `procedure_order`, and `procedure_order_code`, so Week 2 lab promotion should preserve compatibility with those existing chart evidence tools or equivalent FHIR Observation storage backed by OpenEMR.

Intake allergies and medications may be promoted only when explicit, high-confidence, source-cited, and safely mapped. Intake demographics are not auto-overwritten in the MVP. Chief concern, family history, preferences, and free-text findings are stored as cited document facts unless a safe existing destination is defined.

## 12. Duplicate, Deletion, And Retraction Behavior

AgentForge must avoid duplicate and untraceable records.

Duplicate detection uses OpenEMR document identity, document hash, patient id, doc type, source-scoped fact fingerprint, patient-scoped clinical-content fingerprint, and promotion provenance. `PromotionFingerprinter` owns those stable fingerprint/hash shapes so promotion policy and duplicate lookup do not drift inside SQL persistence. Reprocessing the same job or retrying after a transient failure must not create duplicate facts, duplicate embeddings, duplicate promotion outcome rows, or duplicate promoted chart rows. Re-uploading the same lab under a different document id should resolve to `already_exists`, `duplicate_skipped`, or `conflict_needs_review`, not a second clinical row.

If a document is deleted or deactivated:

```text
deactivate derived AgentForge document facts
remove/deactivate their embeddings from retrieval
mark AgentForge promotion rows inactive/retracted
retract or mark inactive OpenEMR records AgentForge promoted from that document when the destination supports it
stop using those rows as AgentForge evidence
record the retraction on the originating fact, promotion, and audit/retraction records
```

For AgentForge-promoted rows stored in OpenEMR `lists`, deactivation uses
`activity = 0` plus an appended source-retracted comment. This preserves
OpenEMR clinical history while making the row inactive for AgentForge evidence.
AgentForge must treat `activity = 0`, inactive promotion rows, inactive facts,
inactive embeddings, and retracted jobs as exclusion signals for retrieval,
source-review trust, and final-answer citations.

Retraction is append-only from an audit perspective and inactive from an evidence perspective:

```text
clinical_document_retractions
  id
  patient_id
  document_id
  job_id
  fact_id
  promotion_id
  promoted_table
  promoted_record_id
  prior_state
  new_state
  action
  actor_type
  actor_id
  reason
  created_at
```

This handles the wrong-document-upload case. If a user uploads the wrong lab PDF and later deletes it, facts derived from that document must not keep poisoning the chart. AgentForge should retract/deactivate rather than hard-delete where OpenEMR supports audit-friendly inactive/voided states. If a destination table has no safe inactive/retracted state, AgentForge stops using the row as evidence and creates a needs-review audit item. Deleted-source content remains historically reviewable but is excluded from active chart evidence, document search, vector retrieval, and final-answer citations.

## 13. MariaDB Vector Retrieval

MariaDB 11.8 is already used by `docker/development-easy`. Week 2 uses MariaDB Vector rather than introducing a separate vector database.

There are separate vector-backed evidence stores:

```text
patient document facts
guideline chunks
```

They are intentionally separate because patient facts and guideline evidence have different privacy, deletion, versioning, and citation rules.

### Patient Document Fact Retrieval

Patient document retrieval searches extracted, validated, cited document facts. It does not use raw PDF vectorization as a substitute for extraction.

```text
uploaded document
  -> extract facts/findings
  -> attach citations
  -> classify certainty
  -> store/vectorize cited document facts
```

Deleted or retracted document facts are excluded from retrieval.

### Guideline Corpus Retrieval

Guideline chunks are global and versioned. The MVP corpus is intentionally small and primary-care focused so retrieval behavior can be inspected and evaluated. It is stored under:

```text
agent-forge/fixtures/clinical-guideline-corpus/
```

Initial corpus sources:

```text
ADA Standards of Care excerpt for diabetes follow-up / A1c monitoring
ACC/AHA cholesterol guideline excerpt for lipid follow-up
USPSTF preventive screening excerpt for primary care follow-up
JNC/ACC-AHA hypertension follow-up excerpt for blood pressure context
OpenEMR-local demo guideline note for out-of-corpus refusal calibration
```

The indexing script normalizes these sources into roughly 25-50 section-level chunks for MVP:

```text
php agent-forge/scripts/index-clinical-guidelines.php
```

Each chunk has a stable `chunk_id`, source title, source file/URL, section, text, and `corpus_version`. `corpus_version` is a checked-in string such as `clinical-guideline-demo-2026-05-06`; changing corpus content requires updating the version and rerunning the indexing/eval command.

```text
clinical_guideline_chunks
  id
  chunk_id
  corpus_version
  source_title
  source_url_or_file
  section
  chunk_text
  citation_json
  active
  created_at
```

```text
clinical_guideline_chunk_embeddings
  chunk_id
  corpus_version
  embedding VECTOR(...)
  embedding_model
  active
  created_at
```

The embedding table is keyed by `(corpus_version, chunk_id)` so corpus versions
can coexist during rebuilds and old chunk ids do not block a new corpus version.

Guideline retrieval follows the required hybrid RAG flow:

```text
1. Sparse keyword query against guideline chunks.
2. Dense vector query against MariaDB Vector.
3. Merge and deduplicate candidates.
4. Rerank merged candidates.
5. Return top cited chunks only.
6. Return not_found/refusal when evidence is insufficient.
```

The final answer may not cite guideline evidence that was not retrieved.

The initial rerank threshold is stored in configuration and calibrated against the W2 retrieval evals. Out-of-corpus cases must fall below threshold and supported guideline cases must clear threshold before the baseline is accepted.

### Reranking

Week 2 requires a reranker that takes candidate chunks as input and returns ranked chunks with scores.

Configured runs use Cohere Rerank when `AGENTFORGE_COHERE_API_KEY` is present. Deterministic fixture/eval runs use a local rerank implementation that takes the same merged candidate chunks and returns ranked chunks with scores. The architecture requires a rerank step, not an elaborate abstraction layer.

## 14. Supervisor And Required Workers

Week 2 requires:

```text
one supervisor
one intake-extractor worker
one evidence-retriever worker
logged and inspectable handoffs
```

AgentForge implements these as PHP orchestration classes under `src/AgentForge`.

### Supervisor

The supervisor is a deterministic-first PHP router. It decides:

```text
whether document extraction is needed
whether patient chart facts are needed
whether patient document fact retrieval is needed
whether guideline retrieval is needed
whether enough evidence exists to answer
whether the request should be refused or narrowed
```

It does not become an opaque model-only router. The "enough evidence" decision is rule-based:

```text
patient factual question:
  answer only if at least one cited chart fact or cited document fact matches the requested topic

interpretation / what-to-pay-attention-to question:
  answer only if at least one patient fact is available and at least one guideline chunk passes rerank threshold

needs-review finding:
  surface when fact_type is clinical/safety relevant or when the question topic matches the finding

out-of-corpus guideline question:
  refuse/narrow when no retrieved guideline chunk passes threshold

unsafe treatment, diagnosis, dosing, or medication-change request:
  refuse/narrow even if patient facts are available
```

Clinical/safety-relevant needs-review types include allergies, medications, active symptoms, abnormal labs, diagnoses/problems, pregnancy status, and urgent red-flag language. Low-value preferences such as appointment timing remain searchable but do not appear unless directly asked about.

### intake-extractor

The spec-required `intake-extractor` worker handles both required document types:

```text
lab_pdf
intake_form
```

It processes document extraction jobs, produces strict JSON, validates facts, attaches source citations, persists safe facts, stores document facts, vectorizes document facts, and records extraction telemetry.

### evidence-retriever

The `evidence-retriever` worker handles answer-time evidence retrieval. It
wraps chart evidence, persisted patient document facts, review-only document
findings, missing/not-found signals, and selective guideline retrieval behind
one evidence boundary. It retrieves:

```text
existing chart evidence
patient document facts for the active patient
review-only document findings for clinician visibility
guideline chunks through hybrid MariaDB Vector RAG plus rerank
```

Guideline retrieval runs only when the question asks for interpretation,
attention, recommendation support, or guideline evidence. It does not run for
every simple factual lookup. Review-only document evidence can be shown to
clinicians, but it remains quarantined from verified patient-fact and guideline
reasoning.

### Handoff Logs

Supervisor handoffs are stored in:

```text
clinical_supervisor_handoffs
  id
  request_id
  job_id
  source_node
  destination_node
  decision_reason
  task_type
  outcome
  latency_ms
  error_reason
  created_at
```

This directly records the fields required by the Week 2 spec:

```text
source node
destination node
decision reason
input/task type
outcome
latency
error reason if any
```

`SupervisorRuntime` combines the deterministic `Supervisor` decision with the
handoff repository write, so routing and audit cannot drift into separate
paths. `VerifiedAgentHandler` uses the same runtime path before invoking
answer-time guideline retrieval.

## 15. Final Answer Behavior

The final response must separate patient facts from guideline evidence.

A Week 2 answer should include sections equivalent to:

```text
Patient Findings
Needs Human Review
Guideline Evidence
Missing or Not Found
```

Rules:

- Every patient-specific clinical claim cites chart evidence or document evidence.
- Every guideline claim cites retrieved guideline chunks.
- Needs-review findings remain visibly labeled.
- Relevant clinical/safety uncertain findings appear in `Needs Human Review` even if the user did not explicitly ask for uncertainty.
- Irrelevant uncertain findings remain searchable but do not clutter every answer.
- Missing data is reported as missing instead of inferred.
- Unsafe, unsupported, or out-of-corpus requests are refused or narrowed.
- AgentForge avoids diagnosis, treatment, dosing, or medication-change advice.
- Any synthesis of what deserves attention must be grounded in cited patient findings and retrieved guideline evidence; it is not a separate uncited editorial section.

The existing Week 1 verified drafting pipeline remains the final guardrail. Week 2 extends the evidence bundle and verifier to support:

```text
chart facts
verified document facts
needs-review document findings
guideline chunks
missing/not-found signals
```

## 16. Observability, Privacy, Cost, And Latency

Week 2 extends the existing `SensitiveLogPolicy`. General telemetry must not include raw PHI.

Allowed log fields include:

```text
job_id
patient_ref
document_id
doc_type
worker
status
facts_extracted_count
facts_promoted_count
facts_needing_review_count
citation_count
source_ids
latency_ms
model
input_tokens
output_tokens
estimated_cost
error_code
```

Disallowed in logs:

```text
patient_name
raw document text
raw extracted facts
raw citation quote/value
full prompts
full answers
image bytes
PDF contents
screenshots
```

General observability should use `patient_ref`, not raw patient identifiers:

```text
patient_ref = patient:<short_hmac(patient_id, app_secret)>
```

The clinical database may store cited findings and quote/value text because authorized OpenEMR users need source review. Logs are sanitized separately.

Enforcement mechanism:

```text
all AgentForge log writes pass through PsrRequestLogger / SensitiveLogPolicy
SensitiveLogPolicy allowlists telemetry keys and drops forbidden keys
Week 2 adds patient_ref and document/job fields to the allowlist
raw extraction payloads, prompts, answers, quotes, and document text are never passed to the logger
the W2 eval runner scans produced telemetry/log artifacts for known demo PHI strings and forbidden keys
```

Latency and cost are captured for:

```text
upload enqueue latency
job queue wait time
document load time
extraction model latency
schema validation latency
persistence latency
embedding latency
document retrieval latency
guideline sparse search latency
guideline vector search latency
rerank latency
draft latency
verify latency
```

Cost is captured for:

```text
extraction model calls
embedding calls
reranker calls
draft model calls
```

This supports the required cost and latency report:

```text
actual development spend
projected production cost
p50 latency
p95 latency
bottleneck analysis
```

The current Week 2 clinical-document cost and latency report lives at:

```text
agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md
```

It summarizes the measured clinical-document cost, latency, and operational caveats from the checked-in eval/job artifacts.

## 17. Eval Gate

Week 2 requires at least a 50-case golden dataset and a blocking eval gate. A demo without a regression-blocking gate does not satisfy the assignment.

Current implementation note (2026-05-08): the checked-in clinical document
golden set is the 65-case H1/Epic 1 baseline. It gates strict fixture-backed
extraction, identity checks, real guideline retrieval, safe refusal, citation
shape, bounding boxes, supervisor/final-answer behavior, no-PHI logging,
preview-only exclusion, and runner-enforced structural coverage for required
H1 scenarios plus contract-only DOCX, XLSX, TIFF, and HL7 v2 formats.

AgentForge extends the existing eval harness under `src/AgentForge/Eval` and `agent-forge/scripts`.

One command is the source of truth for local, CI, and grader reruns:

```text
php agent-forge/scripts/run-clinical-document-evals.php
```

The existing `agent-forge/fixtures/clinical-document-golden` directory is the
clinical document golden set location. It contains the 65-case Week 2 H1/Epic
1 submission set and `source-fixture-manifest.json`, which inventories every
real example document, its role, MIME type, SHA-256, expected document type,
and linked deterministic sidecar when applicable.
The corpus validator fails closed on preview-only extraction mappings, stray
SHA mappings, duplicate source SHAs, unsupported fixture roles, and strict
sidecar citations whose `source_type` does not match the declared document
type.

Baseline storage and comparison are checked into the repo:

```text
agent-forge/fixtures/clinical-document-golden/baseline.json
agent-forge/fixtures/clinical-document-golden/thresholds.json
```

`run-clinical-document-evals.php` validates structural coverage, writes the current run to `agent-forge/eval-results/`, compares rubric pass rates against `baseline.json`, and fails when any required rubric drops below threshold or regresses by more than 5%. Baseline updates require an explicit commit to the baseline file.

Required boolean rubrics:

```text
schema_valid
citation_present
factually_consistent
safe_refusal
no_phi_in_logs
```

Additional gated Week 2 rubrics:

```text
deleted_document_not_retrieved
bounding_box_present
guideline_retrieval
promotion_expectations
document_fact_expectations
```

Other checks such as duplicate prevention and uncertain-finding visibility are reported as case-level assertions, but the required five rubrics plus the Week 2 gated rubrics are the blocking summary metrics.

The gate fails when:

```text
any required rubric drops below documented threshold
any required rubric regresses by more than 5%
schema validation fails for required extraction
any patient clinical claim lacks citation
raw PHI appears in logs
deleted document facts remain retrievable
duplicate upload creates duplicate facts
the eval runner cannot complete
```

Eval artifacts are saved under `agent-forge/eval-results`.

The PR-blocking/local mechanism is a checked-in gate command:

```text
agent-forge/scripts/check-clinical-document.sh
```

That script runs the Week 2 clinical-document bundle:

```text
diff whitespace check
syntax checks
AgentForge isolated PHPUnit
clinical document eval runner
focused PHPStan
PHPCS on changed AgentForge clinical-document eval PHP files
```

The broader local command is:

```text
agent-forge/scripts/check-agentforge.sh
```

The latest local manual verification on 2026-05-08 passed:

```text
AgentForge isolated PHPUnit: 677 tests, 3164 assertions, 1 skipped
AgentForge deterministic evals: 32 passed, 0 failed
clinical document evals: 65 cases, verdict baseline_met
focused PHPStan and PHPCS: clean
```

## 18. Example Documents And Synthetic Expansion

The provided examples under `agent-forge/docs/example-documents` are MVP development fixtures, not the complete golden set.

Initial examples include lab results and intake forms for Chen, Whitaker, Reyes, and Kowalski. Epic 1 also inventories DOCX referrals, XLSX workbooks, TIFF fax packets, HL7 v2 messages, and source-preview proof artifacts. The Week 2 eval set expands from these into 65 synthetic/demo cases using templates/scripts where possible.

The generated set should cover:

```text
clean typed lab PDF
scanned lab PDF
image-only lab result
typed intake form
scanned intake form
important note in an unexpected location
uncertain allergy
incomplete collection date
irrelevant but cited preference
duplicate upload
wrong-document deletion/retraction
missing data behavior
out-of-corpus guideline question
no-PHI logging trap
follow-up question grounding
DOCX referral contract extraction
XLSX workbook contract extraction
TIFF fax packet contract extraction
HL7 v2 ADT/ORU contract extraction
preview-only source artifact exclusion
```

Each case includes ground truth:

```text
expected extracted facts
expected citations
expected promoted chart facts
expected document facts
expected needs-review findings
expected retrieval behavior
```

All documents and facts are synthetic/demo only.

The 65-case H1/Epic 1 set is checked in under `agent-forge/fixtures/clinical-document-golden/cases`. Generated source documents remain committed or reproducibly regenerated according to fixture README instructions. Non-PDF Epic 1 cases prove strict deterministic contracts only; runtime normalizers and live providers are deferred to later multi-format epics.

## 19. Deployment Runtime

The Week 2 runtime includes:

```text
openemr web app
MariaDB 11.8
agentforge-worker
```

`docker/development-easy` already uses MariaDB 11.8.6, which supports MariaDB Vector. No separate vector database is introduced.

The worker is automatic in normal runtime, not manual and not daily cron.

Recommended Docker shape:

```text
agentforge-worker:
  uses the same OpenEMR PHP image/codebase as openemr
  command: php /var/www/localhost/htdocs/openemr/agent-forge/scripts/process-document-jobs.php
  depends_on:
    mysql healthy
```

`docker compose up` should start both the web app and the worker. The app should expose worker/job health so graders can see that extraction jobs are being processed.

Important environment variables. Provider and worker variables are read by PHP
runtime configuration; `AGENTFORGE_EMBEDDING_MODEL` is retained as a reviewer
environment marker and is not currently read by PHP runtime selection.

```text
AGENTFORGE_DRAFT_PROVIDER
AGENTFORGE_OPENAI_API_KEY
AGENTFORGE_OPENAI_MODEL
AGENTFORGE_VLM_PROVIDER
AGENTFORGE_VLM_MODEL
AGENTFORGE_COHERE_API_KEY
AGENTFORGE_EMBEDDING_MODEL
AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS
```

### Deployed Application

The public Week 2 deployment uses the existing AgentForge VM deployment path:

```text
agent-forge/scripts/deploy-vm.sh
agent-forge/scripts/rollback-vm.sh
agent-forge/scripts/health-check.sh
```

Deployment proof must show:

```text
web app reachable
MariaDB 11.8 available
agentforge-worker running
worker health endpoint or health command passing
document upload creates and processes a job
eval/deployed smoke artifact saved under agent-forge/eval-results
```

The H3 readiness contract keeps the legacy `/meta/health/readyz` fields
compatible while adding PHI-safe runtime detail:

```json
{
  "status": "ready",
  "checks": {
    "database": true,
    "agentforge_runtime": true
  },
  "components": {
    "agentforge_runtime": {
      "mariadb": {
        "healthy": true,
        "required_version": "11.8",
        "version": "11.8.6-MariaDB",
        "vector_expected": true
      },
      "worker": {
        "healthy": true,
        "worker": "intake-extractor",
        "status": "running",
        "fresh": true,
        "freshness_threshold_seconds": 120,
        "last_heartbeat_age_seconds": 4
      },
      "queue": {
        "healthy": true,
        "pending": 0,
        "running": 0,
        "stale_running": 0
      }
    }
  }
}
```

`agent-forge/scripts/health-check.sh` is the required deploy/rollback health
gate. It validates the public app, `/readyz`, MariaDB 11.8, fresh
`agentforge-worker` heartbeat, and queue health without requiring `jq`.

Week 2 clinical deployed smoke is separate from the earlier read-only Tier 4
chat smoke:

```text
php agent-forge/scripts/run-clinical-document-deployed-smoke.php
```

It uploads the Chen lab and intake fixtures through OpenEMR, waits for
`lab_pdf` and `intake_form` jobs to succeed, asks a cited Week 2 question, and
writes a PHI-safe artifact under `agent-forge/eval-results`.

Rollback uses the existing rollback script and must leave the previous deployed OpenEMR app and worker in a healthy state.

## 20. Non-Goals For Week 2 MVP

These are out of scope only after the required Week 2 core is satisfied:

- No runtime ingestion claim for third document types beyond `lab_pdf` and `intake_form`; DOCX, XLSX, TIFF, and HL7 v2 are contract/eval targets until later epics add normalizers and providers.
- No raw-PDF RAG as a substitute for strict extraction.
- No automatic demographic overwrite from intake forms.
- No uncited model-generated clinical claims.
- No broad medical-document platform.
- No separate Python or sidecar extraction service.
- No user-visible manual extraction button required for MVP, because extraction is automatic after upload.
- No critic agent unless core requirements are complete.
- No lab trend widget unless core requirements are complete.

## 21. Risks And Tradeoffs

All-PHP extraction keeps deployment and PHI boundaries simple, but PDF/image processing is harder than it would be in Python. This is accepted to keep Week 2 inside AgentForge/OpenEMR.

Automatic background extraction keeps user workflow clean, but introduces a short delay before facts are available. Worker health, job state, and queue latency make the delay observable.

Promoting only certain facts means some useful facts will not become official chart rows immediately. They remain visible, cited, vectorized, and available under document facts or needs-human-review findings.

Identity gating adds one extra step before persistence/promotion, but it prevents the OpenEMR upload destination from silently laundering wrong-patient document content into trusted evidence.

Separate patient-document and guideline vector tables add schema complexity, but prevent privacy and semantic mixing.

Using Cohere for configured rerank and deterministic local rerank for tests keeps the required rerank step inspectable without making reranking a large subsystem.

Promotion and retraction provenance add bookkeeping, but prevent duplicate or wrong-document uploads from permanently poisoning the chart.

Bounding boxes are hard on scanned documents. The MVP requires boxes for promoted document facts and final-answer document citations. If the PHP extraction path cannot produce strict cited JSON plus coordinates from the provided fixtures early, extraction becomes the critical path and the implementation plan must cut scope around that bottleneck before expanding features.

## 22. Requirement Traceability Matrix

| Week 2 Requirement | Architecture Decision |
| --- | --- |
| Ingest `lab_pdf` and `intake_form` | OpenEMR category mapping table maps eligible upload categories to required doc types. |
| Provide `attach_and_extract(patient_id, file_path, doc_type)` | Spec-facing tool remains available; normal UI path converges after OpenEMR stores the document. |
| Store source documents in OpenEMR | Existing OpenEMR document upload/storage path remains source of truth. |
| Persist derived facts without duplicates or untraceable records | Identity checks, document facts, promotion provenance, fingerprints, duplicate checks, and retraction audit records prevent duplicate/uncited records. |
| Strict lab schema | PHP schema/value objects require test name, value, unit, reference range, collection date, abnormal flag, confidence, and citation. |
| Strict intake schema | PHP schema/value objects require demographics, chief concern, medications, allergies, family history, document facts, needs-review findings, and citations. |
| Every extracted fact has source evidence | `citation_json` required for every extracted item. |
| Visual PDF bounding-box overlay | Promoted document facts and final-answer document citations require page and normalized bounding box; eval gates `bounding_box_present`. |
| Basic hybrid RAG | Guideline retriever uses sparse search plus MariaDB Vector dense search. |
| Rerank candidates | Configured runs use Cohere Rerank; deterministic eval runs use local rerank. Both rerank merged candidates and return scores. |
| MariaDB Vector | MariaDB 11.8 stores guideline and document-fact vectors; no separate vector DB. |
| One supervisor | PHP AgentForge supervisor routes extraction/retrieval/final-answer decisions. |
| `intake-extractor` worker | Required worker name retained; handles both `lab_pdf` and `intake_form`. |
| `evidence-retriever` worker | Default evidence composition retrieves chart facts and persisted patient document facts with citations; guideline chunks are added only when guideline support is needed. |
| Logged inspectable handoffs | `clinical_supervisor_handoffs` stores required source, destination, reason, task type, outcome, latency, and error. |
| Separate patient facts from guideline evidence | Final answer sections separate patient record/document findings from guideline evidence. |
| Surface uncertainty | `needs_review` findings are stored with citation metadata for review, but are excluded from active evidence and chart promotion until resolved. |
| Safe refusal / narrowing | Supervisor and verifier refuse unsupported, unsafe, or out-of-corpus requests. |
| 50+ case golden dataset | `agent-forge/fixtures/clinical-document-golden` contains 65 synthetic/demo H1/Epic 1 cases under the 50-80 case policy. |
| Boolean rubrics | Required rubrics are `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`; Week 2 gated rubrics also protect guideline retrieval, bounding boxes, deleted-document exclusion, promotion expectations, and document-fact expectations. |
| Blocking gate command | `agent-forge/scripts/check-clinical-document.sh` runs tests and `run-clinical-document-evals.php`; the same command is intended for CI/hook and grader reruns. |
| No raw PHI in logs | All AgentForge logs pass through `SensitiveLogPolicy`; W2 eval scans telemetry artifacts for forbidden keys and demo PHI strings. |
| Cost and latency report | `agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md` documents the accepted deterministic artifact and explicitly marks runtime latency and model cost fields as not yet measured for the clinical-document path. |
| Deployed observable flow | Runtime includes `openemr`, MariaDB 11.8, automatic `agentforge-worker`, VM deploy/rollback scripts, and worker health/readiness proof. |

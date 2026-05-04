# AgentForge Week 2 Architecture

## 1. Architecture Position

Week 2 extends the existing PHP AgentForge/OpenEMR system. It does not add a Python sidecar, a separate extraction runtime, or a separate vector database. The core runtime remains OpenEMR plus MariaDB.

The design goal is to satisfy the Week 2 Clinical Co-Pilot requirements with the smallest inspectable architecture that can ingest clinical documents, extract cited facts, retrieve guideline evidence with MariaDB Vector, route work through the required supervisor/workers, and block regressions with eval-driven CI.

Core principles:

- Use OpenEMR's existing document upload system.
- Keep all Week 2 application logic in PHP under `src/AgentForge`.
- Store the original document before extracting or persisting derived facts.
- Promote only high-confidence, schema-valid, cited facts into established OpenEMR clinical tables.
- Keep all other useful document findings searchable and cited, without silently treating them as chart truth.
- Never hide clinically relevant uncertain findings; surface them as needs-human-review findings when relevant.
- Keep patient document retrieval separate from guideline retrieval, even though both use MariaDB Vector.
- Make every supervisor handoff, extraction job, retrieval step, eval result, cost, and latency inspectable without logging raw PHI.

## 2. Existing OpenEMR Document Upload Flow

Week 2 reuses the current OpenEMR document upload flow. Users do not learn a new upload workflow.

The existing upload path begins at `library/ajax/upload.php`. For normal core uploads, that endpoint receives the uploaded file and calls `addNewDocument(...)` from `library/documents.php`. That helper uses `C_Document::upload_action_process()`, which creates a `Document` and calls `Document::createDocument(...)`. `Document::createDocument(...)` stores the file, creates a `documents` row, records the document hash, links the document to the patient through `documents.foreign_id`, and links it to an OpenEMR document category through `categories_to_documents`.

Week 2 hooks in after `addNewDocument(...)` returns a successful `doc_id`. The low-level `Document::createDocument(...)` storage class remains responsible for OpenEMR document storage. AgentForge adds a post-upload enqueue step:

```text
library/ajax/upload.php
  -> addNewDocument(...)
  -> OpenEMR stores file and creates documents.id
  -> AgentForge enqueueIfEligible(patient_id, document_id, category_id)
  -> agentforge_document_jobs row created
  -> agentforge-worker processes the job
```

The user-facing behavior stays simple:

```text
User uploads document to the patient chart.
User moves on.
AgentForge processes eligible documents in the background.
Later, AgentForge chat can use extracted document facts with citations.
```

## 3. Document Type Eligibility

AgentForge does not guess every uploaded PDF or image. Eligibility is driven by OpenEMR document category.

A new mapping table records which OpenEMR categories trigger Week 2 extraction:

```text
agentforge_document_type_mappings
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
```

Seeded demo mappings:

```text
AgentForge Lab PDF -> lab_pdf
AgentForge Intake Form -> intake_form
```

Only mapped active categories create extraction jobs. Other uploaded documents remain normal OpenEMR documents and are ignored by the Week 2 extraction worker.

## 4. Background Ingestion Jobs

Document extraction is automatic but not part of the upload request. Upload should remain fast; extraction should begin near-real-time.

When an eligible document is uploaded, AgentForge immediately inserts a pending job:

```text
agentforge_document_jobs
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

The worker:

```text
finds a pending job
marks it running
loads the OpenEMR document
extracts facts
validates facts
persists/chart-promotes safe facts
stores and vectorizes cited document facts
records handoffs, cost, latency, counts, and errors
marks the job succeeded or failed
repeats
```

Extraction failure does not undo the upload. The source document remains in OpenEMR, the job is marked failed, and no trusted OpenEMR chart facts are promoted from the failed job. Partial output may survive only as cited `needs_review` findings when it is safe and explicitly labeled.

## 5. Required Tool Contract

The Week 2 spec requires:

```text
attach_and_extract(patient_id, file_path, doc_type)
```

AgentForge keeps this spec-facing tool for tests, CLI usage, and direct requirement compliance. In the normal OpenEMR UI path, the file has already been stored by OpenEMR, so AgentForge calls the same underlying ingestion service with the saved `documents.id`.

Both paths converge on the same rule:

```text
Store source document in OpenEMR first.
Then extract.
Then validate.
Then persist only cited validated facts.
```

The OpenEMR-native path is:

```text
attach_saved_document_and_extract(patient_id, document_id, doc_type)
```

This is an implementation detail of the existing upload flow, not a replacement for the required tool contract.

## 6. Extraction And Validation

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

`needs_review` means the item may matter clinically but is uncertain, ambiguous, incomplete, out of place, or low-confidence. It is still stored, vectorized, cited, and surfaced when relevant. It is not promoted into trusted chart tables.

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

## 7. Strict Schemas

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

## 8. Citation Contract

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
  "bounding_box": [0.12, 0.44, 0.62, 0.49]
}
```

Coordinates are normalized:

```text
0.0 = left/top
1.0 = right/bottom
```

The original OpenEMR document remains the source of truth. Vector records and extracted facts point back to the OpenEMR `documents.id`, page/section, field path, quote/value, and bounding box where available.

`citation_json` may store raw `quote_or_value` because it is clinical application storage used by authorized UI flows. General telemetry logs must not include raw quote/value text.

## 9. Visual Source Review

Week 2 requires visual PDF source review with bounding-box overlay.

AgentForge document citations therefore store:

```text
document id
page number or section
quote/value
bounding box when available
field path
```

The UI should open the original OpenEMR document to the cited page and draw the bounding rectangle over the cited area. For MVP fixtures, bounding boxes are required in eval coverage. If an extractor cannot produce a box for a non-MVP edge case, the fallback is page plus quote/value highlighting, but the architecture treats bounding boxes as the required target path.

## 10. Persistence Model

AgentForge separates extraction from clinical acceptance.

Certain, schema-valid, cited facts that map safely to existing OpenEMR tables are promoted. Other useful findings remain cited, searchable AgentForge document facts.

### AgentForge Tables

```text
agentforge_document_facts
  id
  patient_id
  document_id
  job_id
  doc_type
  fact_type
  certainty
  fact_text
  structured_value_json
  citation_json
  confidence
  promoted_table
  promoted_record_id
  active
  created_at
  deactivated_at
```

```text
agentforge_document_fact_embeddings
  fact_id
  embedding VECTOR(...)
  embedding_model
  active
  created_at
```

```text
agentforge_fact_promotions
  id
  fact_id
  document_id
  promoted_table
  promoted_record_id
  status
  created_at
  retracted_at
  retraction_reason
```

### Promotion Rules

Lab PDF verified facts are promoted to OpenEMR lab/Observation-compatible records when complete and cited. The existing Week 1 evidence layer reads recent labs from `procedure_result`, `procedure_report`, `procedure_order`, and `procedure_order_code`, so Week 2 lab promotion should preserve compatibility with those existing chart evidence tools or equivalent FHIR Observation storage backed by OpenEMR.

Intake allergies and medications may be promoted only when explicit, high-confidence, source-cited, and safely mapped. Intake demographics are not auto-overwritten in the MVP. Chief concern, family history, preferences, and free-text findings are stored as cited document facts unless a safe existing destination is defined.

## 11. Duplicate, Deletion, And Retraction Behavior

AgentForge must avoid duplicate and untraceable records.

Duplicate detection uses OpenEMR document identity, document hash, patient id, doc type, fact fingerprint, and promotion provenance. Reprocessing the same job or retrying after a transient failure must not create duplicate facts, duplicate embeddings, or duplicate promoted chart rows.

If a document is deleted or deactivated:

```text
deactivate derived AgentForge document facts
remove/deactivate their embeddings from retrieval
retract or mark inactive OpenEMR records AgentForge promoted from that document
stop using those rows as AgentForge evidence
record the retraction in agentforge_fact_promotions
```

This handles the wrong-document-upload case. If a user uploads the wrong lab PDF and later deletes it, facts derived from that document must not keep poisoning the chart. AgentForge should retract/deactivate rather than hard-delete where OpenEMR supports audit-friendly inactive/voided states. If a destination table has no safe inactive/retracted state, AgentForge stops using the row as evidence and creates a needs-review audit item.

## 12. MariaDB Vector Retrieval

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

Guideline chunks are global and versioned:

```text
agentforge_guideline_chunks
  id
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
agentforge_guideline_chunk_embeddings
  chunk_id
  embedding VECTOR(...)
  embedding_model
  active
  created_at
```

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

### Reranking

Week 2 requires a reranker that takes candidate chunks as input and returns ranked chunks with scores.

AgentForge uses:

```text
RerankerInterface
CohereReranker
DeterministicTestReranker
```

The production/default reranker is `CohereReranker` when `AGENTFORGE_COHERE_API_KEY` is configured. Local deterministic tests use `DeterministicTestReranker`, which implements the same interface and returns ranked candidate chunks with scores. Both satisfy the required rerank step.

## 13. Supervisor And Required Workers

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

It does not become an opaque model-only router.

### intake-extractor

The spec-required `intake-extractor` worker handles both required document types:

```text
lab_pdf
intake_form
```

It processes document extraction jobs, produces strict JSON, validates facts, attaches source citations, persists safe facts, stores document facts, vectorizes document facts, and records extraction telemetry.

### evidence-retriever

The `evidence-retriever` worker handles answer-time evidence retrieval. It retrieves:

```text
existing chart evidence
patient document facts for the active patient
guideline chunks through hybrid MariaDB Vector RAG plus rerank
```

Guideline retrieval runs only when the question asks for interpretation, attention, recommendation support, or guideline evidence. It does not run for every simple factual lookup.

### Handoff Logs

Supervisor handoffs are stored in:

```text
agentforge_supervisor_handoffs
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

## 14. Final Answer Behavior

The final response must separate patient facts from guideline evidence.

A Week 2 answer should include sections equivalent to:

```text
Patient Record / Document Findings
Guideline Evidence
Needs Human Review
Missing or Not Found
What To Pay Attention To
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

The existing Week 1 verified drafting pipeline remains the final guardrail. Week 2 extends the evidence bundle and verifier to support:

```text
chart facts
verified document facts
needs-review document findings
guideline chunks
missing/not-found signals
```

## 15. Observability, Privacy, Cost, And Latency

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

## 16. Eval Gate

Week 2 requires a 50-case golden dataset and a blocking eval gate. A demo without a regression-blocking gate does not satisfy the assignment.

AgentForge extends the existing eval harness under `src/AgentForge/Eval` and `agent-forge/scripts`.

One command is the source of truth for local, CI, and grader reruns:

```text
php agent-forge/scripts/run-w2-evals.php
```

The existing `agent-forge/fixtures/w2-golden` directory becomes the 50-case Week 2 golden set.

Required boolean rubrics:

```text
schema_valid
citation_present
factually_consistent
safe_refusal
no_phi_in_logs
```

Additional Week 2 rubrics:

```text
promoted_only_when_safe
uncertain_finding_visible
deleted_document_not_retrieved
duplicate_not_created
bounding_box_present
```

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

## 17. Example Documents And Synthetic Expansion

The provided examples under `agent-forge/docs/week2/example-documents` are MVP development fixtures, not the complete golden set.

Initial examples include lab results and intake forms for Chen, Whitaker, Reyes, and Kowalski. The Week 2 eval set expands from these into 50 synthetic/demo cases using templates/scripts where possible.

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

## 18. Deployment Runtime

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

Important environment variables:

```text
AGENTFORGE_DRAFT_PROVIDER
AGENTFORGE_OPENAI_API_KEY
AGENTFORGE_OPENAI_MODEL
AGENTFORGE_COHERE_API_KEY
AGENTFORGE_EMBEDDING_MODEL
AGENTFORGE_DOCUMENT_WORKER_POLL_SECONDS
```

## 19. Non-Goals For Week 2 MVP

These are out of scope only after the required Week 2 core is satisfied:

- No third document type beyond `lab_pdf` and `intake_form`.
- No raw-PDF RAG as a substitute for strict extraction.
- No automatic demographic overwrite from intake forms.
- No uncited model-generated clinical claims.
- No broad medical-document platform.
- No separate Python or sidecar extraction service.
- No user-visible manual extraction button required for MVP, because extraction is automatic after upload.
- No critic agent unless core requirements are complete.
- No lab trend widget unless core requirements are complete.

## 20. Risks And Tradeoffs

All-PHP extraction keeps deployment and PHI boundaries simple, but PDF/image processing is harder than it would be in Python. This is accepted to keep Week 2 inside AgentForge/OpenEMR.

Automatic background extraction keeps user workflow clean, but introduces a short delay before facts are available. Worker health, job state, and queue latency make the delay observable.

Promoting only certain facts means some useful facts will not become official chart rows immediately. They remain visible, cited, vectorized, and available under document facts or needs-human-review findings.

Separate patient-document and guideline vector tables add schema complexity, but prevent privacy and semantic mixing.

Reranker abstraction adds one interface, but lets production use Cohere while tests remain deterministic.

Retraction provenance adds bookkeeping, but prevents wrong-document uploads from permanently poisoning the chart.

Bounding boxes are hard on scanned documents. The MVP requires bounding boxes for example/eval fixtures and stores page plus quote/value fallback for edge cases where box extraction fails.

## 21. Requirement Traceability Matrix

| Week 2 Requirement | Architecture Decision |
| --- | --- |
| Ingest `lab_pdf` and `intake_form` | OpenEMR category mapping table maps eligible upload categories to required doc types. |
| Provide `attach_and_extract(patient_id, file_path, doc_type)` | Spec-facing tool remains available; normal UI path converges after OpenEMR stores the document. |
| Store source documents in OpenEMR | Existing OpenEMR document upload/storage path remains source of truth. |
| Persist derived facts without duplicates or untraceable records | Document facts, promotion provenance, fingerprints, and duplicate checks prevent duplicate/uncited records. |
| Strict lab schema | PHP schema/value objects require test name, value, unit, reference range, collection date, abnormal flag, confidence, and citation. |
| Strict intake schema | PHP schema/value objects require demographics, chief concern, medications, allergies, family history, document facts, needs-review findings, and citations. |
| Every extracted fact has source evidence | `citation_json` required for every extracted item. |
| Visual PDF bounding-box overlay | Document citations store page and normalized bounding box; MVP eval requires boxes for fixtures. |
| Basic hybrid RAG | Guideline retriever uses sparse search plus MariaDB Vector dense search. |
| Rerank candidates | `CohereReranker` or `DeterministicTestReranker` reranks merged candidates and returns scores. |
| MariaDB Vector | MariaDB 11.8 stores guideline and document-fact vectors; no separate vector DB. |
| One supervisor | PHP AgentForge supervisor routes extraction/retrieval/final-answer decisions. |
| `intake-extractor` worker | Required worker name retained; handles both `lab_pdf` and `intake_form`. |
| `evidence-retriever` worker | Retrieves chart facts, patient document facts, and guideline chunks with citations. |
| Logged inspectable handoffs | `agentforge_supervisor_handoffs` stores required source, destination, reason, task type, outcome, latency, and error. |
| Separate patient facts from guideline evidence | Final answer sections separate patient record/document findings from guideline evidence. |
| Surface uncertainty | `needs_review` findings are stored, vectorized, cited, and surfaced when clinically relevant. |
| Safe refusal / narrowing | Supervisor and verifier refuse unsupported, unsafe, or out-of-corpus requests. |
| 50-case golden dataset | `agent-forge/fixtures/w2-golden` expands to 50 synthetic/demo cases. |
| Boolean rubrics | Required rubrics are `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`. |
| PR-blocking CI or hook | `php agent-forge/scripts/run-w2-evals.php` is the single runner used locally, in CI/hook, and by graders. |
| No raw PHI in logs | Week 2 extends `SensitiveLogPolicy`; telemetry uses `patient_ref`, counts, ids, latency, cost, and statuses only. |
| Cost and latency report | Stage timings, token usage, estimated cost, retrieval hits, and worker timings are captured per encounter/job. |
| Deployed observable flow | Runtime includes `openemr`, MariaDB 11.8, and automatic `agentforge-worker` service with health/readiness. |


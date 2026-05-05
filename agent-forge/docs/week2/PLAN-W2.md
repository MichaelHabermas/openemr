# AgentForge Week 2 Implementation Plan

## 1. Purpose And Constraints

This plan turns `W2_ARCHITECTURE.md` into ordered implementation epics for the Week 2 Clinical Co-Pilot. It is an implementation plan, not another PRD.

Hard constraints:

- Keep all Week 2 runtime code in PHP/OpenEMR/AgentForge. No Python sidecar, no extraction microservice, no external vector database.
- Reuse existing OpenEMR document upload. Users upload documents normally through `library/ajax/upload.php` and OpenEMR stores source documents first.
- After an eligible upload, AgentForge automatically creates a background extraction job. The upload request must stay fast.
- The worker is a separate automatic PHP runtime process using the same OpenEMR codebase: `php agent-forge/scripts/process-document-jobs.php`.
- Use MariaDB 11.8 Vector. Patient document fact vectors and guideline vectors must be stored in separate tables.
- Required node/worker names stay exactly `supervisor`, `intake-extractor`, and `evidence-retriever`.
- `intake-extractor` handles both `lab_pdf` and `intake_form`; code and docs must explicitly note that the name is spec-required and broader than intake forms.
- Evals/tests are written before production code in every epic.
- Prefer one short command for the clinical document gate: `agent-forge/scripts/check-clinical-document.sh`.
- Keep one comprehensive AgentForge command current across Week 1, Week 2, and future work: `agent-forge/scripts/check-agentforge.sh`.

Engineering approach:

- Use SOLID principles for AgentForge Week 2 modules: each class should have one clear reason to change, dependencies should be injected where practical, and high-level orchestration should depend on small interfaces rather than concrete model/database providers.
- Use DRY strategies deliberately: extract shared schema validation, citation handling, telemetry sanitization, embedding, and repository behavior only when duplication is real and the shared abstraction keeps behavior easier to test.
- Keep the design modular: document upload/enqueue, job processing, extraction, validation, fact persistence, lab promotion, document retrieval, guideline retrieval, orchestration, and final answer verification should remain separately testable units under `src/AgentForge` as much as possible.
- Prefer small, inspectable PHP classes over broad service objects. Any module that becomes hard to understand in one pass should be split before adding new behavior to it.

Epic progress convention:

- Every epic carries a `Status` line. Allowed values are `Not started`, `In progress`, `Blocked`, and `Completed`.
- Mark an epic `Completed` only when all acceptance criteria, definition-of-done items, and required tests/evals for that epic are passing or have concrete proof artifacts.
- Every epic that adds, removes, or changes required verification must add or amend `agent-forge/scripts/check-agentforge.sh` so the comprehensive AgentForge gate remains the one command a reviewer can run beyond a single week or assignment slice.
- If there is any uncertainty, missing verification, partial implementation, or unreviewed failure, keep the epic as `In progress` or `Blocked`; do not mark it completed.
- When pausing work, update the status and add a short note only if the next step is not obvious from the unfinished tasks. This lets future sessions resume without re-deciding the architecture.

## 2. MVP Cut Line

The MVP is delivered only when this vertical slice works locally and is proven by a blocking Week 2 eval command:

Existing OpenEMR upload -> eligible category creates extraction job -> PHP worker processes job -> `lab_pdf` and `intake_form` fixtures produce strict cited JSON -> verified lab facts are promoted into OpenEMR-compatible lab records -> intake findings are stored as cited document facts / needs-review findings -> document facts are searchable -> guideline evidence is retrieved with sparse + MariaDB Vector + rerank -> `supervisor` handoffs are logged -> final answer separates patient findings, guideline evidence, and needs-review items -> eval gate proves schemas, citations, refusals, factual consistency, bounding boxes, and no-PHI logging.

MVP does not require third document types, demographic overwrites, broad document AI, a critic agent, or polished submission packaging. The full 50-case eval expansion, deployment proof, visual source overlay polish, cost/latency report, and demo packaging continue after the MVP cut line.

## 3. Dependency Map / Implementation Order

1. Create the Week 2 test/eval skeleton and command shape first.
2. Add database schema and upload enqueue path.
3. Add worker skeleton and deterministic job processing.
4. Add strict extraction schemas/providers for `lab_pdf` and `intake_form`.
5. Add fact persistence, lab promotion, embeddings, and patient document search.
6. Add guideline corpus indexing, sparse+dense MariaDB Vector retrieval, and rerank.
7. Add `supervisor` / `evidence-retriever` orchestration and separated final-answer behavior.
8. Pass the MVP eval gate and local smoke.
9. Expand and harden for full Week 2 submission.
10. Keep the FINAL epic last; insert any fixes or extra feature epics before FINAL.

## 4. MVP Epics

### Epic M1 - Clinical Document Eval And Test Skeleton First

Status: Completed.

Goal: Create the failing tests/evals that define the MVP before production code.

Files/modules:

- Add `agent-forge/scripts/check-clinical-document.sh`.
- Add `agent-forge/scripts/run-clinical-document-evals.php`.
- Add `agent-forge/fixtures/clinical-document-golden/cases/*.json`.
- Add `agent-forge/fixtures/clinical-document-golden/thresholds.json`.
- Add `agent-forge/fixtures/clinical-document-golden/baseline.json`.
- Add isolated tests under `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/*Test.php`.

Database changes: None.

Tests/evals first:

- Add schema-valid, citation-present, factual-consistency, safe-refusal, no-PHI-log, and bounding-box-present rubric scaffolding.
- Add MVP cases for Chen typed lab PDF, Chen typed intake form, one image/scanned lab, one image/scanned intake, duplicate upload, guideline supported retrieval, out-of-corpus refusal, and no-PHI logging trap.
- Add tests proving `check-clinical-document.sh` invokes clinical document eval unit tests and `run-clinical-document-evals.php`.

Implementation tasks:

- Define the clinical document case JSON format with inputs, expected extracted facts, expected citations, expected promoted facts, expected document facts, expected retrieval behavior, and expected final answer sections.
- Make `run-clinical-document-evals.php` initially fail with clear "not implemented" rubric failures rather than silently passing.
- Document thresholds next to the runner.

Acceptance criteria:

- `php agent-forge/scripts/run-clinical-document-evals.php` runs and fails for missing implementation.
- `agent-forge/scripts/check-clinical-document.sh` exists and is the single intended local/CI Week 2 gate.
- The fixture README explains MVP vs later 50-case expansion.

Definition of done:

- Tests/evals are committed before production implementation begins.
- The runner produces JSON artifacts under `agent-forge/eval-results/`.

Dependencies: None.

### Epic M2 - Schema Migration, Upload Eligibility, And Job Enqueue

Status: Not started.

Goal: Reuse OpenEMR upload and enqueue extraction jobs only for mapped document categories.

Files/modules:

- Modify `sql/database.sql`.
- Modify `sql/8_1_0-to-8_1_1_upgrade.sql`.
- Modify `version.php` for the database version bump.
- Modify `agent-forge/sql/seed-demo-data.sql`.
- Modify `library/ajax/upload.php`.
- Add `src/AgentForge/Document/DocumentType.php`.
- Add `src/AgentForge/Document/SqlDocumentTypeMappingRepository.php`.
- Add `src/AgentForge/Document/SqlDocumentJobRepository.php`.
- Add `src/AgentForge/Document/DocumentUploadEnqueuer.php`.

Database changes:

- Add `agentforge_document_type_mappings`: `id`, `category_id`, `doc_type`, `active`, `created_at`, unique `(category_id, doc_type)`.
- Add `agentforge_document_jobs`: `id`, `patient_id`, `document_id`, `doc_type`, `status`, `attempts`, `lock_token`, `created_at`, `started_at`, `finished_at`, `error_code`, `error_message`, unique `(patient_id, document_id, doc_type)`.
- Seed demo categories `AgentForge Lab PDF` -> `lab_pdf` and `AgentForge Intake Form` -> `intake_form`.

Tests/evals first:

- Test allowed doc types are exactly `lab_pdf` and `intake_form`.
- Test mapped active categories enqueue one pending job.
- Test unmapped categories do not enqueue.
- Test duplicate upload/job enqueue is idempotent.
- Test upload hook runs after `addNewDocument(...)` returns `doc_id`.

Implementation tasks:

- In `library/ajax/upload.php`, capture `addNewDocument(...)` return value for core uploads and call `DocumentUploadEnqueuer::enqueueIfEligible($patientId, $docId, $categoryId)`.
- Do not modify low-level `Document::createDocument(...)` for AgentForge behavior.
- Keep portal uploads out of MVP unless the same hook can support them without changing user workflow.
- Enqueue errors must be logged as sanitized AgentForge metadata and must not fail the OpenEMR upload.

Acceptance criteria:

- Normal OpenEMR upload creates a `documents` row first.
- Eligible categories create exactly one pending AgentForge job.
- Ineligible uploads remain normal OpenEMR documents.
- Upload latency is not blocked by extraction.

Definition of done:

- Database install/upgrade path works on a fresh local stack.
- Enqueue path is covered by isolated tests and W2 eval setup.

Dependencies: M1.

### Epic M3 - Automatic PHP Worker Skeleton

Status: Not started.

Goal: Provide the separate automatic PHP process that claims and processes jobs using the same codebase.

Files/modules:

- Add `agent-forge/scripts/process-document-jobs.php`.
- Add `src/AgentForge/Document/DocumentJobWorker.php`.
- Add `src/AgentForge/Document/OpenEmrDocumentLoader.php`.
- Add `src/AgentForge/Document/WorkerHeartbeatRepository.php`.
- Modify `src/AgentForge/Observability/SensitiveLogPolicy.php`.
- Modify `docker/development-easy/docker-compose.yml`.

Database changes:

- Add `agentforge_worker_heartbeats`: `worker_name`, `process_id`, `status`, `last_heartbeat_at`, `metadata_json`.
- Add/confirm job status values: `pending`, `running`, `succeeded`, `failed`, `retracted`.

Tests/evals first:

- Test pending job claim is atomic and marks job `running`.
- Test failed load marks job `failed` without changing the source document.
- Test worker telemetry contains allowed fields only: job id, patient_ref, document id, doc type, worker, status, counts, latency, model/cost fields, error code.
- Test `intake-extractor` appears as the worker name for both document types.

Implementation tasks:

- Implement a loop with poll interval from `AGENTFORGE_DOCUMENT_WORKER_POLL_SECONDS`.
- Implement one-shot mode for tests/evals.
- Load OpenEMR document bytes via `Document` APIs, not direct path guessing.
- Add an `agentforge-worker` service to `docker/development-easy/docker-compose.yml` using the OpenEMR image/codebase and the worker command.
- Ensure worker health can be derived from heartbeat plus job queue status.

Acceptance criteria:

- `docker compose up` can start `openemr`, MariaDB, and `agentforge-worker`.
- A pending job is claimed and reaches a terminal status.
- Worker crashes or extraction errors never undo uploads.

Definition of done:

- Worker skeleton runs locally in one-shot and loop mode.
- Logs are sanitized and prove no raw document text or PHI.

Dependencies: M2.

### Epic M4 - Strict Extraction Tool And Schemas

Status: Not started.

Goal: Implement `attach_and_extract(patient_id, file_path, doc_type)` semantics and strict cited extraction for both required document types.

Files/modules:

- Add `src/AgentForge/Document/AttachAndExtractTool.php`.
- Add `src/AgentForge/Document/Extraction/IntakeExtractorWorker.php`.
- Add `src/AgentForge/Document/Extraction/DocumentExtractionProvider.php`.
- Add `src/AgentForge/Document/Extraction/OpenAiVlmExtractionProvider.php`.
- Add `src/AgentForge/Document/Extraction/FixtureExtractionProvider.php`.
- Add `src/AgentForge/Document/Schema/LabPdfExtraction.php`.
- Add `src/AgentForge/Document/Schema/IntakeFormExtraction.php`.
- Add `src/AgentForge/Document/Schema/DocumentCitation.php`.
- Add `src/AgentForge/Document/Schema/BoundingBox.php`.

Database changes: None beyond M2/M3.

Tests/evals first:

- Test lab schema requires test name, value, unit, reference range, collection date, abnormal flag, confidence, source citation, and bounding box for verified facts.
- Test intake schema requires demographics, chief concern, current medications, allergies, family history, other document facts, needs-review findings, and citations.
- Test invalid JSON, wrong `document_type`, missing citations, unsupported enums, and missing required fields reject before persistence.
- Test low-confidence or weak-citation facts become `needs_review` or `document_fact`, not `verified`.

Implementation tasks:

- `AttachAndExtractTool` accepts new file paths for spec/eval calls and existing OpenEMR document references for upload-hook jobs.
- Source document storage always happens before extraction.
- Implement typed error codes: unsupported doc type, missing file, storage failure, extraction failure, schema validation failure, persistence failure, duplicate detected.
- `OpenAiVlmExtractionProvider` uses `AGENTFORGE_VLM_PROVIDER`, `AGENTFORGE_VLM_MODEL`, and existing API key patterns.
- `FixtureExtractionProvider` powers deterministic tests/evals from checked-in fixtures.

Acceptance criteria:

- Example `lab_pdf` and `intake_form` fixtures produce strict cited JSON.
- Every persisted candidate fact has a citation with source type, source id, page/section, field path, quote/value, and bounding box when required.
- The name `intake-extractor` is documented in code comments as spec-required and broader than intake forms.

Definition of done:

- No extraction output can reach persistence without schema validation.
- MVP extraction evals pass for lab and intake fixtures.

Dependencies: M3.

### Epic M5 - Fact Persistence, Lab Promotion, Embeddings, And Patient Document Search

Status: Not started.

Goal: Store cited document facts, promote verified lab facts to OpenEMR-compatible lab records, and make document facts searchable.

Files/modules:

- Add `src/AgentForge/Document/DocumentFact.php`.
- Add `src/AgentForge/Document/SqlDocumentFactRepository.php`.
- Add `src/AgentForge/Document/DocumentFactClassifier.php`.
- Add `src/AgentForge/Document/Promotion/OpenEmrLabResultPromoter.php`.
- Add `src/AgentForge/Document/Embedding/EmbeddingProvider.php`.
- Add `src/AgentForge/Document/Embedding/OpenAiEmbeddingProvider.php`.
- Add `src/AgentForge/Document/Embedding/DeterministicEmbeddingProvider.php`.
- Add `src/AgentForge/Evidence/PatientDocumentFactsEvidenceTool.php`.
- Modify `src/AgentForge/Evidence/EvidenceBundleItem.php`.

Database changes:

- Add `agentforge_document_facts`: `id`, `patient_id`, `document_id`, `job_id`, `doc_type`, `fact_type`, `certainty`, `fact_fingerprint`, `fact_text`, `structured_value_json`, `citation_json`, `confidence`, `promoted_table`, `promoted_record_id`, `promotion_status`, `retracted_at`, `retraction_reason`, `active`, `created_at`, `deactivated_at`; unique `(patient_id, document_id, doc_type, fact_fingerprint)`.
- Add `agentforge_document_fact_embeddings`: `fact_id`, `embedding VECTOR(1536)`, `embedding_model`, `active`, `created_at`.
- Use existing OpenEMR lab tables: `procedure_order`, `procedure_order_code`, `procedure_report`, and `procedure_result`.

Tests/evals first:

- Test duplicate job retry does not duplicate facts, embeddings, or promoted lab rows.
- Test verified lab facts create OpenEMR-compatible `procedure_*` rows with `procedure_result.document_id` and AgentForge provenance.
- Test intake demographics are not written to `patient_data` in MVP.
- Test intake findings are stored as `document_fact` or `needs_review`.
- Test re-querying document facts excludes inactive/retracted facts.
- Test document fact retrieval returns cited evidence bundle items.

Implementation tasks:

- Convert validated extraction items into certainty buckets: `verified`, `document_fact`, `needs_review`.
- Promote only complete, high-confidence, cited lab facts.
- Store all useful cited intake findings as document facts or needs-review findings.
- Generate stable fact fingerprints from patient id, document id, doc type, field path, normalized value, collection date/section, and citation source.
- Embed document facts only after persistence succeeds.
- Extend evidence bundle handling so source ids can represent chart, document, and guideline evidence without losing backward compatibility.

Acceptance criteria:

- Lab fixture creates chart-readable lab evidence through the existing `LabsEvidenceTool` path or an equivalent OpenEMR Observation-compatible path.
- Intake findings are searchable and cited but not silently treated as chart truth.
- Document fact vectors are stored only in `agentforge_document_fact_embeddings`.

Definition of done:

- MVP evals prove lab promotion, intake needs-review storage, duplicate prevention, and document fact search.

Dependencies: M4.

### Epic M6 - Guideline Corpus, MariaDB Vector, Hybrid Retrieval, And Rerank

Status: Not started.

Goal: Retrieve cited guideline evidence using sparse retrieval plus MariaDB Vector plus rerank.

Files/modules:

- Add `agent-forge/fixtures/clinical-guideline-corpus/*.md`.
- Add `agent-forge/fixtures/clinical-guideline-corpus/corpus-version.txt`.
- Add `agent-forge/scripts/index-clinical-guidelines.php`.
- Add `src/AgentForge/Guidelines/GuidelineChunk.php`.
- Add `src/AgentForge/Guidelines/SqlGuidelineChunkRepository.php`.
- Add `src/AgentForge/Guidelines/GuidelineCorpusIndexer.php`.
- Add `src/AgentForge/Guidelines/HybridGuidelineRetriever.php`.
- Add `src/AgentForge/Guidelines/DeterministicReranker.php`.
- Add `src/AgentForge/Guidelines/CohereReranker.php`.

Database changes:

- Add `agentforge_guideline_chunks`: `id`, `chunk_id`, `corpus_version`, `source_title`, `source_url_or_file`, `section`, `chunk_text`, `citation_json`, `active`, `created_at`; unique `(corpus_version, chunk_id)`.
- Add `agentforge_guideline_chunk_embeddings`: `chunk_id`, `embedding VECTOR(1536)`, `embedding_model`, `active`, `created_at`.

Tests/evals first:

- Test guideline indexing is deterministic and idempotent.
- Test sparse search returns expected chunks for lipid, A1c, BP, and preventive screening queries.
- Test dense retrieval uses MariaDB Vector and never touches patient document fact embeddings.
- Test rerank is always applied to merged candidates.
- Test out-of-corpus questions return not-found/refusal instead of invented guideline claims.

Implementation tasks:

- Create small primary-care corpus: ADA A1c monitoring excerpt, ACC/AHA cholesterol excerpt, USPSTF screening excerpt, hypertension excerpt, and OpenEMR-local refusal calibration note.
- Chunk into roughly 25-50 section-level chunks.
- Implement sparse retrieval, dense vector retrieval, merge/dedupe, rerank, threshold, and cited top-k output.
- Use deterministic local reranker for evals and Cohere when `AGENTFORGE_COHERE_API_KEY` is present.

Acceptance criteria:

- At least one supported guideline question retrieves relevant cited evidence.
- Out-of-corpus questions do not generate guideline claims.
- Guideline vectors are stored only in `agentforge_guideline_chunk_embeddings`.

Definition of done:

- `php agent-forge/scripts/index-clinical-guidelines.php` can rebuild the corpus.
- Retrieval evals pass with deterministic embeddings/rerank.

Dependencies: M5.

### Epic M7 - Supervisor, Evidence-Retriever, Final Answer Separation, And MVP Gate

Status: Not started.

Goal: Wire the full MVP answer path through `supervisor`, `intake-extractor`, and `evidence-retriever`.

Files/modules:

- Add `src/AgentForge/Orchestration/Supervisor.php`.
- Add `src/AgentForge/Orchestration/SupervisorDecision.php`.
- Add `src/AgentForge/Orchestration/SqlSupervisorHandoffRepository.php`.
- Add `src/AgentForge/Evidence/EvidenceRetrieverWorker.php`.
- Modify `src/AgentForge/Handlers/VerifiedAgentHandler.php` or add `src/AgentForge/Handlers/Week2AgentHandler.php`.
- Modify `src/AgentForge/ResponseGeneration/PromptComposer.php`.
- Modify `src/AgentForge/ResponseGeneration/DraftClaim.php`.
- Modify `src/AgentForge/Verification/DraftVerifier.php`.
- Modify `src/AgentForge/Handlers/AgentResponse.php`.
- Modify `interface/patient_file/summary/agent_request.php`.

Database changes:

- Add `agentforge_supervisor_handoffs`: `id`, `request_id`, `job_id`, `source_node`, `destination_node`, `decision_reason`, `task_type`, `outcome`, `latency_ms`, `error_reason`, `created_at`.

Tests/evals first:

- Test handoff rows record `supervisor` -> `intake-extractor` and `supervisor` -> `evidence-retriever`.
- Test factual patient questions do not run guideline retrieval unless needed.
- Test "what changed / what deserves attention" requires patient facts plus guideline evidence.
- Test final answer sections include Patient Findings, Needs Human Review, Guideline Evidence, and Missing or Not Found.
- Test every patient claim cites chart/document evidence and every guideline claim cites retrieved guideline chunks.
- Test unsafe treatment/dosing/diagnosis requests are refused/narrowed.

Implementation tasks:

- Implement deterministic-first `Supervisor` routing rules from `W2_ARCHITECTURE.md`.
- Implement `EvidenceRetrieverWorker` using existing chart evidence tools, `PatientDocumentFactsEvidenceTool`, and `HybridGuidelineRetriever`.
- Extend response schema with backward-compatible machine-readable citation details while preserving existing `citations` strings.
- Add draft claim types for guideline evidence and needs-review findings.
- Update verifier so guideline claims must cite retrieved guideline chunks and patient claims must cite chart/document facts.
- Ensure logs use `patient_ref`, not raw PHI, for W2 fields.

Acceptance criteria:

- Full MVP vertical slice passes through UI/API path and deterministic eval path.
- Handoffs are inspectable in SQL.
- Final response separates patient findings, guideline evidence, and needs-review items.

Definition of done:

- `agent-forge/scripts/check-clinical-document.sh` passes the MVP gate.
- MVP demo can be run locally without manual extraction steps.

Dependencies: M6.

## 5. Post-MVP / Hardening Epics

### Epic H1 - 50-Case Eval Expansion And Regression Policy

Status: Not started.

Goal: Satisfy the full Week 2 50-case eval requirement.

Files/modules:

- Modify `agent-forge/fixtures/clinical-document-golden/cases/*.json`.
- Modify `agent-forge/fixtures/clinical-document-golden/baseline.json`.
- Modify `agent-forge/fixtures/clinical-document-golden/thresholds.json`.
- Add `agent-forge/scripts/generate-clinical-document-golden-fixtures.php`.
- Modify `agent-forge/scripts/run-clinical-document-evals.php`.

Database changes: None.

Tests/evals first:

- Add cases for clean typed lab, scanned lab, image-only lab, typed intake, scanned intake, unexpected location, uncertain allergy, incomplete collection date, irrelevant preference, duplicate upload, wrong-document deletion/retraction, missing data, out-of-corpus guideline, no-PHI logging trap, follow-up grounding, and citation regression.

Implementation tasks:

- Expand to 50 synthetic/demo cases.
- Enforce required rubrics: `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`.
- Keep clinical document gated rubrics: `bounding_box_present`, `deleted_document_not_retrieved`.
- Fail on any required threshold drop or >5% regression.

Acceptance criteria:

- Fewer than 50 cases fails the gate.
- A deliberate citation, schema, refusal, or no-PHI regression fails.

Definition of done:

- 50-case run writes current results and compares against baseline.

Dependencies: M7.

### Epic H2 - Visual PDF Source Review And Retraction

Status: Not started.

Goal: Complete source-review and wrong-document cleanup behavior.

Files/modules:

- Add `src/AgentForge/Document/DocumentCitationReviewService.php`.
- Add source-review endpoint under the existing OpenEMR patient document UI path.
- Modify `controllers/C_Document.class.php` or the relevant document delete/deactivate flow to call AgentForge retraction.
- Add browser/UI tests if the source review surface is visible in OpenEMR.

Database changes:

- Use `agentforge_document_facts.retracted_at`, `retraction_reason`, `active`, `deactivated_at`.
- Use embedding `active` flags.
- If needed, add `agentforge_document_retractions`: `id`, `document_id`, `fact_id`, `promoted_table`, `promoted_record_id`, `status`, `created_at`.

Tests/evals first:

- Test cited PDF fact opens the source document page with bounding-box metadata.
- Test fallback opens exact page and quote/value if bounding box is unavailable.
- Test deleted/deactivated document facts are not retrievable.
- Test promoted AgentForge lab rows are retracted or excluded from evidence when source document is removed.

Implementation tasks:

- Surface citation metadata from `citation_json`.
- Add review URL/data shape to `AgentResponse` citation details.
- Implement retraction/deactivation hooks for document deletion/deactivation.
- Keep clinical DB quote/value storage allowed; keep telemetry sanitized.

Acceptance criteria:

- Reviewer can locate the cited source page/box for MVP fixture citations.
- Wrong-document deletion no longer poisons chart or retrieval.

Definition of done:

- Visual source and deletion/retraction evals pass.

Dependencies: H1.

### Epic H3 - Deployment Runtime, Health, And Smoke Proof

Status: Not started.

Goal: Prove the deployed app includes the automatic worker and Week 2 flow.

Files/modules:

- Modify `docker/development-easy/docker-compose.yml`.
- Modify `agent-forge/scripts/deploy-vm.sh`.
- Modify `agent-forge/scripts/rollback-vm.sh`.
- Modify `agent-forge/scripts/health-check.sh`.
- Add or modify `agent-forge/scripts/run-clinical-document-deployed-smoke.php`.
- Update reviewer/deployment docs.

Database changes: None beyond existing W2 tables.

Tests/evals first:

- Test health check reports MariaDB 11.8, web app readiness, worker heartbeat, and queue health.
- Test deployed smoke uploads/attaches required docs, waits for jobs, asks a cited question, and stores artifact.

Implementation tasks:

- Ensure deployment starts `agentforge-worker`.
- Add deployed smoke artifact under `agent-forge/eval-results`.
- Ensure rollback leaves prior web app and worker healthy.

Acceptance criteria:

- Deployment proof shows web reachable, MariaDB 11.8 available, worker running, document upload creates/processes a job, and smoke artifact saved.

Definition of done:

- Grader can rerun the deployed proof with documented commands.

Dependencies: H1; H2 can run in parallel if source review is not deployment-blocking.

### Epic H4 - Observability, Cost/Latency, And Privacy Hardening

Status: Not started.

Goal: Complete Week 2 cost, latency, and no-PHI proof.

Files/modules:

- Modify `src/AgentForge/Observability/AgentTelemetry.php`.
- Modify `src/AgentForge/Observability/SensitiveLogPolicy.php`.
- Modify `src/AgentForge/Observability/PsrRequestLogger.php`.
- Add `agent-forge/scripts/render-clinical-document-cost-latency-report.php`.
- Add `agent-forge/docs/week2/CLINICAL_DOCUMENT_COST_LATENCY_REPORT.md`.

Database changes: None unless telemetry is persisted in a W2 table; prefer existing sanitized logs and eval artifacts.

Tests/evals first:

- Test allowlist accepts W2 fields: job id, patient_ref, document id, doc type, worker, counts, citations count, source ids, latency, model, tokens, cost, error code.
- Test forbidden keys and demo PHI strings are dropped/fail evals.
- Test cost/latency report renders from eval/job artifacts.

Implementation tasks:

- Capture upload enqueue latency, queue wait, document load, extraction, validation, persistence, embedding, document retrieval, sparse retrieval, vector retrieval, rerank, draft, verify.
- Capture extraction, embedding, reranker, and draft model costs.
- Render actual dev spend, projected production cost, p50, p95, and bottlenecks.

Acceptance criteria:

- No raw document text, prompts, answers, quote/value, image bytes, screenshots, or patient names appear in logs.
- Report exists and is reproducible.

Definition of done:

- `no_phi_in_logs` and report-generation tests pass.

Dependencies: M7.

### Epic H5 - Demo Readiness And Documentation Alignment

Status: Not started.

Goal: Make the Week 2 flow understandable and rerunnable for graders.

Files/modules:

- Modify `README.md`.
- Modify `AGENTFORGE-REVIEWER-GUIDE.md`.
- Modify `agent-forge/docs/week2/README.md`.
- Modify `W2_ARCHITECTURE.md` only to reflect landed implementation details, not to change scope.
- Add `agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md`.

Database changes: None.

Tests/evals first:

- Add document tests ensuring Week 2 commands, env vars, artifacts, and acceptance matrix are linked and do not expose secrets.
- Add link-resolution tests for local docs.

Implementation tasks:

- Document Week 1 vs Week 2 behavior separately.
- Document env vars: `AGENTFORGE_DRAFT_PROVIDER`, `AGENTFORGE_OPENAI_API_KEY`, `AGENTFORGE_OPENAI_MODEL`, `AGENTFORGE_VLM_PROVIDER`, `AGENTFORGE_VLM_MODEL`, `AGENTFORGE_COHERE_API_KEY`, `AGENTFORGE_EMBEDDING_MODEL`, `AGENTFORGE_DOCUMENT_WORKER_POLL_SECONDS`.
- Create acceptance matrix mapping specs to proof artifacts.
- Prepare demo path: upload lab, upload intake, watch jobs, ask final cited question, inspect handoffs/evals.

Acceptance criteria:

- Reviewer can run the core flow without guessing branch, env vars, service names, or commands.
- Docs link to eval results, deployed smoke, cost/latency report, and acceptance matrix.

Definition of done:

- Documentation tests pass and reviewer guide is coherent.

Dependencies: H3, H4.

## 6. FINAL Epic - FINAL Submission Packaging And Reviewer Proof

Status: Not started.

Goal: Produce the final Week 2 submission package. This epic must remain last; insert any fixes or extra feature epics before FINAL.

Files/modules:

- Finalize `README.md`.
- Finalize `AGENTFORGE-REVIEWER-GUIDE.md`.
- Finalize `agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md`.
- Finalize `agent-forge/docs/week2/CLINICAL_DOCUMENT_COST_LATENCY_REPORT.md`.
- Save final eval/deployed smoke artifacts under `agent-forge/eval-results/`.
- Add demo video link/reference in reviewer docs.

Database changes: None.

Tests/evals first:

- Run `agent-forge/scripts/check-clinical-document.sh`.
- Run deployed smoke.
- Run health check.
- Run documentation/link tests.
- Confirm 50-case baseline and current results are present.

Implementation tasks:

- Package final proof artifacts: source-grounded demo video, deployed URL, acceptance matrix, eval results, CI/gate evidence, cost/latency report, rerun instructions, and known caveats.
- Verify final answer behavior on the demo path: Patient Findings, Needs Human Review, Guideline Evidence, Missing or Not Found.
- Verify `supervisor`, `intake-extractor`, and `evidence-retriever` handoff logs are inspectable.
- Verify no raw PHI appears in committed docs, eval artifacts, or telemetry samples.

Acceptance criteria:

- Reviewer rerun instructions work from a clean local or deployed environment.
- Final acceptance matrix has proof for every Week 2 required item.
- Demo video shows upload, extraction, guideline retrieval, final cited answer, handoffs, eval gate, and deployed health.

Definition of done:

- Final gate passes.
- Final docs and artifacts are internally consistent.
- No FINAL work hides known blockers; any blocker is documented with impact and rerun path.

Dependencies: H1-H5.

## 7. Test/Eval Command Strategy

Use one main command:

```bash
agent-forge/scripts/check-agentforge.sh
```

That comprehensive gate must stay current across AgentForge work, not only Week 1 or Week 2. When an epic adds or changes a required check, update this script in the same epic.

Use the clinical-document gate directly when you only need the current Week 2 clinical-document slice:

```bash
agent-forge/scripts/check-clinical-document.sh
```

`check-clinical-document.sh` should run, in order:

```bash
git diff --check
php -l library/ajax/upload.php
find src/AgentForge tests/Tests/Isolated/AgentForge agent-forge/scripts -name '*.php' -print0 | xargs -0 -n 1 php -l
find agent-forge/scripts -name '*.sh' -print0 | xargs -0 -n 1 bash -n
composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'
php agent-forge/scripts/run-clinical-document-evals.php
composer phpstan -- --error-format=raw src/AgentForge tests/Tests/Isolated/AgentForge interface/patient_file/summary/agent_request.php library/ajax/upload.php
vendor/bin/phpcs <changed AgentForge/OpenEMR clinical document PHP files>
```

Supporting scripts:

- `agent-forge/scripts/process-document-jobs.php` runs worker one-shot or loop mode.
- `agent-forge/scripts/index-clinical-guidelines.php` rebuilds guideline chunks and embeddings.
- `agent-forge/scripts/generate-clinical-document-golden-fixtures.php` deterministically regenerates/validates golden metadata.
- `agent-forge/scripts/run-clinical-document-evals.php` runs the clinical document gate and writes artifacts.
- `agent-forge/scripts/render-clinical-document-cost-latency-report.php` writes the cost/latency report.
- `agent-forge/scripts/run-clinical-document-deployed-smoke.php` proves deployed Week 2 flow.

The eval runner must fail when required rubrics fall below thresholds, regress by more than 5%, schema validation fails, a clinical claim lacks citation, raw PHI appears in logs, deleted document facts remain retrievable, duplicate upload creates duplicate facts, or the runner cannot complete.

## 8. Overall Definition Of Done

Week 2 is done when:

- Source documents are stored through existing OpenEMR upload before extraction.
- Eligible upload categories automatically create extraction jobs.
- The PHP worker processes jobs without a manual extraction button.
- `lab_pdf` and `intake_form` extraction produce strict cited JSON.
- Verified lab facts promote into OpenEMR-compatible lab records with provenance.
- Intake findings are stored as cited document facts or needs-review findings, not demographic overwrites.
- Patient document fact vectors and guideline vectors are separate MariaDB Vector stores.
- Guideline retrieval uses sparse search, dense MariaDB Vector search, merge/dedupe, rerank, and thresholded cited output.
- `supervisor`, `intake-extractor`, and `evidence-retriever` names appear exactly in code/docs/logs.
- Handoff logs are inspectable.
- Final answers separate patient findings, guideline evidence, needs-review findings, and missing/not-found data.
- Every patient clinical claim cites chart/document evidence; every guideline claim cites retrieved guideline chunks.
- Unsafe, unsupported, ambiguous, or out-of-corpus questions are refused or narrowed.
- The 50-case eval gate passes and blocks meaningful regressions.
- Logs and committed artifacts contain no raw PHI, raw document text, prompts, answers, screenshots, image bytes, or patient names.
- Deployed proof shows OpenEMR, MariaDB 11.8, `agentforge-worker`, worker health, document job processing, eval/smoke artifacts, and rollback path.
- Final submission includes acceptance matrix, cost/latency report, demo video, deployed URL, and reviewer rerun instructions.

## 9. Risks And Sequencing Notes

- PDF/image extraction with bounding boxes is the critical path. Keep the first provider fixture-driven and deterministic, then integrate VLM calls once schema and persistence tests are locked.
- Do not build raw-PDF RAG for patient documents. Patient document retrieval uses extracted cited facts only.
- Do not mix guideline and patient fact vectors. Separate tables are required for privacy, deletion, versioning, and citation semantics.
- Do not let `supervisor` become opaque model routing. Start deterministic and only add model selection where bounded by tests.
- Lab promotion can duplicate chart facts if provenance/fingerprint rules are weak. Implement idempotency before broad extraction.
- Retraction/deletion is post-MVP but submission-critical because wrong-document uploads must not keep poisoning evidence.
- Keep scripts few and boring. `check-clinical-document.sh` is the main gate; extra scripts should be helpers called by that gate or by documented deploy/smoke flows.

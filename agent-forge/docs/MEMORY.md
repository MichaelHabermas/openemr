# AgentForge Memory

## What This File Is For

`CURRENT-EPIC.md` files are active workbenches. They can be erased, rewritten, or narrowed for each epic. This file is the durable AgentForge memory that should survive those rewrites across all weeks and milestones.

Use this file for notes that future AgentForge work should not forget:

- Architecture decisions that should remain stable.
- Safety and privacy guardrails that must apply across epics.
- Bugs, proof gaps, and test lessons discovered during implementation.
- Acceptance caveats that are easy to lose when an epic file is refreshed.
- Short summaries of prior context that help new work stay aligned.

Do not use this file as the primary task tracker. The source of truth for active execution remains the current epic file, current specs, architecture docs, plans, and the code. This file is for carry-forward memory, not for replacing those documents.

## Update Ritual

Before replacing an active epic file, copy durable lessons here if they will matter later. Good candidates are:

- A bug that was fixed and could reappear.
- A safety rule that affected design.
- A command or gate with an important caveat.
- A deferred proof item that future milestones must close.
- A schema or contract decision that downstream work depends on.

Keep entries concise. Prefer notes that will change future engineering behavior over narrative progress logs.

## Global AgentForge Guardrails

- **Secrets and automation boundary:** LLM keys (`AGENTFORGE_OPENAI_API_KEY` / `AGENTFORGE_ANTHROPIC_API_KEY` and legacy aliases) are server env only; `deploy-vm.sh` fails fast when the chosen provider’s key is missing. Tier 2 and Tier 4 automation assume credentials and optionally SSH (`AGENTFORGE_VM_SSH_HOST`) on the runner—never commit values. Multi-turn state is stored under session key `agent_forge_conversations` via `SessionUtil::setSensitiveSession` so conversation payloads are not written through the generic session debug path.
- Implemented code is not acceptance-complete until the safety proof is present. If verifier, auth, evidence, eval, or deployment proof is missing, call the work incomplete or explicitly document the gap.
- OpenEMR core workflows must not depend on AgentForge success. AgentForge integrations should fail safe and avoid breaking normal OpenEMR behavior.
- Patient and user scope must be resolved before patient data is read.
- Model-facing clinical behavior must be grounded in evidence, citations, and deterministic checks.
- Unsupported or unsafe clinical requests must fail closed with a safe refusal.
- Logs and telemetry must avoid raw PHI while preserving enough operational signal to debug.
- Prefer explicit contracts and mapped eligibility over opportunistic inference when clinical safety or workflow behavior is involved.

## Week 1 Summary

Week 1 established the AgentForge chart-agent baseline inside OpenEMR/PHP. The core idea was a server-bound clinical assistant that can answer chart-grounded questions only when authorization, patient context, evidence, and deterministic safety checks all pass.

The important Week 1 foundations were:

- Patient and user scope must be resolved before any patient data is read.
- Model answers require grounded evidence, citations, and source identifiers.
- Deterministic verification is a release gate, not an optional quality layer.
- Unsupported or unsafe clinical requests must fail closed with a safe refusal.
- Logs and telemetry must avoid raw PHI while preserving enough operational signal to debug.
- Demo data, evals, cost and latency checks, and deployment proof are part of acceptance.

The most important Week 1 habit to carry forward is this: implemented code is not acceptance-complete until the safety proof is present. If verifier, auth, evidence, or eval proof is missing, call the work incomplete or explicitly document the gap.

Later work can build on Week 1, but Week 1 docs are not automatically controlling for newer milestones. Prefer the current architecture, specs, plan, and active epic when they are more specific.

## Week 2 Durable Guardrails

- Document upload must continue even if AgentForge construction, mapping lookup, enqueue, logging, or database writes fail.
- Do not add raw patient IDs to new document-ingestion logs. Use hashed `patient_ref` for M2+ document telemetry.
- Do not log raw document text, extracted document fields, quotes, values, or images. These are clinical-content payloads and must stay out of telemetry.
- Category mappings determine document-ingestion eligibility. Do not infer eligibility from filenames, MIME types, or opportunistic heuristics unless a later epic explicitly changes the contract.
- M2 only creates idempotent `pending` document jobs after OpenEMR has successfully stored a document. Extraction, worker execution, fact persistence, guideline retrieval, and UI proof belong to later milestones.
- Source document deletion is a lifecycle boundary. When an OpenEMR document is deleted, all AgentForge jobs tied to that `document_id` must become non-processable by moving to `retracted` with `retraction_reason=source_document_deleted`.
- M2 retraction only invalidates current document jobs. Later fact, embedding, retrieval, and chart-promotion tables must carry `document_id` and their own retraction/audit metadata so deleted-source content cannot remain retrievable or promoted.
- Do not promote any extracted value into existing OpenEMR clinical tables unless the promoted row can be traced back through `document_id`, `job_id`, extracted fact id, source citation, confidence/review status, and promotion status.
- Users must have an audit/review surface showing what AgentForge inserted, skipped, rejected, left needs-review, or could not promote from a source document.
- Duplicate prevention must exist at both the extracted-fact layer and the promoted-OpenEMR-row layer. Job idempotency alone is not enough once data is copied into clinical tables.
- A passing syntax/test subset is not the same as the full clinical gate. Preserve exact gate caveats when an eval threshold is expected to fail before downstream implementation exists.

## Week 2 stakeholder clarifications (2026-05-05)

Course Slack alignment (treat as normative for assignment interpretation; see `SPECS-W2.md` for the editable spec):

- **MVP submission demo:** Lab PDF + intake ingestion and first guideline retrieval are demonstrated on **deployment**, not localhost-only (corrects ambiguous PRD/spec wording).
- **Clinical-guideline corpus:** Organization-approved practice reference material the team selects and indexes; staff do not ship corpus files—use a minimal intentional MVP set, then grow.
- **Retrieval vs patient data:** Hybrid sparse+dense+rerank applies to the guideline corpus only; patient-derived document facts and observations belong in OpenEMR/FHIR-shaped structured persistence, not as guideline corpus vector chunks.
- **Recommended steps vs checkpoint focus:** The May 5 checkpoint emphasizes ingestion + retrieval on deploy; broader recommended steps (supervisor/workers/CI) should still be taking shape rather than omitted entirely.
- **Prior tech debt:** Resolving Week 1 feedback before adding surface area is **guidance**, not a hard MVP requirement—teams choose timing.

## Week 2 M2 Architecture Decisions

- `clinical_document_type_mappings` maps OpenEMR document categories to clinical document types.
- `clinical_document_processing_jobs` is the durable queue table for document ingestion jobs.
- `doc_type` and job status are represented as PHP backed enums and stored as strings in SQL, rather than database-specific enum types.
- Job enqueue is idempotent for `(patient_id, document_id, doc_type)`.
- Upload enqueue dispatch happens once in `C_Document::upload_action_process()`
  after `Document::createDocument(...)` succeeds. `addNewDocument(...)` already
  uses that controller path, so outer callers such as `library/ajax/upload.php`
  must not dispatch again.
- SQL repositories should go through a small AgentForge database executor boundary instead of duplicating raw helper calls.
- Value objects should reject non-positive IDs at the boundary.
- `DocumentRetractionReason` stores modeled source-document lifecycle reasons as strings; M2 has exactly `source_document_deleted`.
- `retracted` is terminal for M2 document jobs. Future workers must ignore retracted jobs even if they were previously `pending`, `running`, `failed`, or `succeeded`.

## Week 2 M2 Lessons Learned

- OpenEMR document categories use nested-set coordinates. Seeding sibling categories must allocate distinct `lft` and `rght` ranges and expand the root only when an insert actually occurs. Re-running seed data must be idempotent and must not keep widening the root category.
- Hydrating job status from SQL should use the modeled enum parser, not direct enum hydration. Unknown database values should become domain-level exceptions rather than leaking `ValueError`.
- Sensitive logging allowlists need both allowed telemetry keys and explicit forbidden raw clinical-content keys. The forbidden list should include raw quote/value/document fields such as `quote`, `quote_or_value`, `raw_quote`, `raw_value`, `document_text`, `document_image`, and `extracted_fields`.
- Static analysis may reject broad source-level catches of `Throwable` or `Exception`. Prefer catching modeled runtime/domain exceptions in production source, while relying on global error handling for fatal engine errors unless the contract explicitly requires a broader catch.
- The M2 upload and retraction procedural hooks are explicit exceptions to that preference: they run after OpenEMR has already performed the authoritative document side effect, so they catch `Throwable`, log only sanitized metadata, and never let AgentForge failures break normal upload/delete behavior.
- Follow-up for static-analysis hygiene: the current forbidden-catch allowlist exempts whole files, which is acceptable for tiny M2 hook files but should become more precise if those files grow. Add dedicated tests for `ForbiddenCatchTypeRule` path allowlisting, including Windows-style paths and malicious suffix lookalikes. Also reconcile the broader policy tension between Rector rules that prefer `Throwable` catches and PHPStan rules that forbid suppressing `Error`.
- Some isolated suites assume a local server is running. A full `composer phpunit-isolated` can fail for routing tests if `127.0.0.1:8765` is unavailable; record that as environment setup, not as a document-ingestion regression.
- The standard Documents screen has more than one upload entry point, but
  `C_Document::upload_action_process()` is the shared storage boundary used by
  `addNewDocument(...)`. Put document-ingestion dispatch there so core,
  Dropzone, and portal uploads enqueue at most once.
- Wrong-patient document handling is not a delete workflow problem. M2 treats the OpenEMR upload destination as authoritative, creates a job for the uploaded source document if the category is mapped, and retracts that job if the source document is deleted. Content-level patient mismatch detection, rejection, or review belongs to extraction/verification later.
- Wrong-patient detection is extraction/verification scope, not M2 upload scope. Future work must compare document-content identifiers to the selected OpenEMR patient and prevent fact promotion while identity is unresolved.
- Retraction must cover already-finished jobs too. Until downstream fact/promotion tables exist, M2 can overwrite a `succeeded` job to `retracted`; later epics must preserve downstream audit metadata while preventing deleted-source content from being used.
- During the unclosed M2 branch, local databases that saw the old branded
  document tables/categories should be reset or explicitly cleaned before final
  validation. Do not over-engineer production-style migration support for
  unreleased partial table names; fresh install and upgrade SQL should describe
  the durable clinical-document contract.
- A document category maps to exactly one clinical document type in M2. Allowing
  multiple active doc types for the same category makes enqueue behavior depend
  on insertion order, which is not acceptable for a clinical workflow switch.

## Week 2 M2 Proof Notes

Last verified: 2026-05-05.

The focused M2 verification used during implementation included:

- Document isolated PHPUnit suite and updated sensitive logging tests passing.
- PHPCS passing on touched AgentForge document and observability files.
- PHPStan passing on touched AgentForge document and observability files.
- SQL smoke proof for idempotent demo category seed behavior.
- In-container OpenEMR `addNewDocument(...)` smoke proof for mapped enqueue,
  duplicate dispatch idempotency, and unmapped-category no-op.
- Manual browser upload proof through the standard Documents screen passed
  after the `C_Document::upload_action_process()` hook was added.
- Source-document retraction focused tests pass for the reason enum, in-memory repository idempotency, SQL repository update shape, and safe hook dispatch/failure behavior.
- Local DB proof on 2026-05-05 simulated a `succeeded` job for `document_id=75`, invoked `DocumentRetractionHook::dispatch(75)`, and verified `status=retracted`, `lock_token=NULL`, `retracted_at` set, `retraction_reason=source_document_deleted`, with a repeated dispatch leaving the row unchanged.
- M2 naming correction on 2026-05-05 removed branded persistent document-ingestion contracts: tables are `clinical_document_type_mappings` and `clinical_document_processing_jobs`, log events use `clinical_document.*`, and the seed maps existing `Lab Report -> lab_pdf` without creating visible `AgentForge...` document categories.
- Corrected local DB proof on 2026-05-05 verified no `AgentForge%` categories, no old `agentforge_document_*` tables, exactly one `Lab Report -> lab_pdf` mapping, successful upload to `Lab Report` creating document `79` / job `6`, and retraction of job `6` after source-document deletion state transition.
- Full `composer phpunit-isolated` passed outside the sandbox when the
  routing-test server could bind to `127.0.0.1:8765`; sandboxed runs can fail
  those routing tests with connection errors.
- Clinical document gate reaching the eval threshold step, with the known threshold violation still expected until downstream extraction/workflow implementation is connected.
- Review hardening on 2026-05-05 added source-level integration wiring tests
  proving `C_Document::upload_action_process()` is the single enqueue point,
  `library/ajax/upload.php` does not duplicate dispatch, and
  `interface/patient_file/deleter.php` retracts after `documents.deleted=1`.
- Review hardening on 2026-05-05 changed
  `clinical_document_type_mappings` to unique `category_id` so a category
  cannot map to multiple clinical document types.
- Review hardening on 2026-05-05 added a direct document-delete patient guard:
  non-super users with `patients:docs_rm` can delete only documents whose
  `documents.foreign_id` matches the active session patient.
- The direct document-delete guard applies to the request branch that receives
  `deleter.php?document=<id>`. Users do not see the field as `document_id`,
  but OpenEMR document delete links submit the document id in the request. The
  broader patient-delete cleanup path loops through `delete_document()` only
  after admin/super patient-delete authorization, so it is not blocked by the
  active-patient direct-delete guard.
- Clean reset/reseed M2 proof on 2026-05-05 verified the durable baseline:
  no old `agentforge_document_*` tables, no visible `AgentForge%` categories,
  one active `Lab Report -> lab_pdf` mapping, successful upload creates one
  pending `lab_pdf` job, UI delete retracts the pending job, and UI delete also
  retracts an already-`succeeded` job while preserving its prior `finished_at`.
- The OpenEMR deleter helper can echo raw SQL when
  `sql_string_no_show_screen` is false. M2 document deletion suppresses that
  SQL echo for document relation cleanup so users do not see
  `DELETE FROM categories_to_documents ...` during normal document deletion.

Do not claim full clinical-document acceptance from M2 alone unless schema edits, upload hook behavior, idempotency, sanitized logging, and required tests are all proven, and any eval threshold gaps are explicitly accepted.

## Architecture scan follow-ups (2026-05)

- **Runtime seam:** `interface/patient_file/summary/agent_request.php` remains the HTTP entry; larger moves into `OpenEMR\AgentForge` services should follow existing DI patterns and add tests before refactors.
- **CI vs release proof:** PR green does not imply Tier 4 deployed smoke or full `check-agentforge.sh`; treat those as release/demo gates (documented in `EPIC2-DEPLOYMENT-RUNTIME-PROOF.md`).

## Carry Forward To M3+

- M3 worker work should build on `clinical_document_processing_jobs`, including `lock_token`, `started_at`, attempts, heartbeat behavior, and safe retry semantics.
- M3/M4 must preserve the upload safety contract: document upload is non-blocking and cannot be broken by AgentForge.
- Document extraction must continue the no-raw-PHI logging posture established in M2.
- Before claiming end-to-end acceptance, add or perform a manual browser upload smoke test for both core and portal paths.
- If an active epic file is refreshed, move any reusable proof gaps, architectural decisions, or safety lessons here first.
- Durable clinical schema names and user-visible workflow labels must describe the domain, not the implementing module or product. Use names such as `clinical_document_type_mappings` and normal OpenEMR categories such as `Lab Report`; do not introduce branded `AgentForge...` table names or document categories.

## Week 2 M3 Implementation Notes

Last automated proof: 2026-05-05. Last local Docker/manual proof: 2026-05-05.

- M3 worker skeleton uses the existing `clinical_document_processing_jobs`
  columns `lock_token`, `started_at`, and `attempts`; do not add a separate
  `locked_at` column for this milestone.
- `clinical_document_worker_heartbeats` is the M3 liveness table keyed by `worker`,
  with process id, status, iteration count, processed/failed counters,
  started/heartbeat timestamps, and `stopped_at`.
- The canonical worker idle interval env var is
  `AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS`; older
  `AGENTFORGE_DOCUMENT_WORKER_POLL_SECONDS` wording was replaced in Week 2
  planning docs for M3.
- M3 intentionally wires `NoopDocumentJobProcessor`, which marks claimed jobs
  failed with `error_code=extraction_not_implemented`. M4 must replace this
  processor with real extraction rather than treating M3 failures as extraction
  regressions.
- The worker must keep logging only sanitized operational metadata. New M3 logs
  use `patient_ref`, never raw `patient_id`, and never include document text,
  bytes, quotes, extracted fields, full lock tokens, or exception messages.
- Unexpected worker processor/load failures that are not modeled runtime
  exceptions must not strand claimed jobs in `running`. M3 uses a rethrowing
  `Throwable` cleanup boundary: first finalize the claimed job as
  `failed(processor_failed)` and write a stopped heartbeat, then rethrow so the
  global failure path still sees the real fatal.
- OpenEMR's PHP configuration used by `docker/development-easy` disables
  `pcntl_signal*`; graceful worker shutdown in Docker relies on the Compose
  shell trap invoking `process-document-jobs.php --mark-stopped`, not PHP
  signal handlers.
- Legacy OpenEMR `Document` APIs can emit PHP notices for invalid source
  document IDs. The M3 worker must keep these notices out of container logs by
  using scoped error handling in `OpenEmrDocumentLoader` and CLI error-display
  suppression after OpenEMR bootstrap.
- Automated M3 proof covers schema/repository/claimer/heartbeat/worker loop,
  loader errors, CLI parsing/script shape, Docker Compose service shape,
  PHPCS, PHPStan on touched files, syntax checks, and focused document tests.
  Local Docker/manual proof covered a pre-upgrade local volume at
  `v_database=539`; the shipped upgrade SQL contains the standard
  `#IfNotTable clinical_document_worker_heartbeats` path, but that local
  volume needed manual table creation before testing. Proof also covered upload
  enqueue to `pending`, no-op processing to
  `failed(extraction_not_implemented)`, stopped heartbeat on Docker stop,
  retracted-row skip behavior, two-worker/five-job no-double-processing, and
  sanitized container logs without PHP notices.

## Week 2 M4 Implementation Notes

Last verified: 2026-05-06.

- M4 replaces the M3 no-op processor for `WorkerName::IntakeExtractor` with
  `IntakeExtractorWorker`. The spec-required `intake-extractor` name remains
  broader than intake forms and handles both `lab_pdf` and `intake_form`.
- The extraction provider boundary is `DocumentExtractionProvider::extract()`
  with `DocumentId`, `DocumentLoadResult`, `DocumentType`, and `Deadline`.
  M4 supports fixture and OpenAI VLM providers only; disabled/provider-disabled
  mode remains intentionally absent from extraction.
- The eval path uses `AttachAndExtractTool` with `InMemorySourceDocumentStorage`
  and its paired in-memory loader, plus fixture extraction by source-byte
  SHA-256 manifest. Source fixture paths now point at
  `agent-forge/docs/example-documents/...`.
- The M4 cleanup pass added deterministic `CertaintyClassifier` telemetry
  bucketing, `AttachAndExtractTool::forExistingDocument()`, storage-only
  `SourceDocumentStorage`, and `OpenEmrSourceDocumentStorage`. The queued
  production worker path remains independent:
  `DocumentJobWorker` -> `OpenEmrDocumentLoader` -> `IntakeExtractorWorker`.
- Bounding boxes for M4 fixtures and evals use the established
  `{x, y, width, height}` object shape expected by `CitationShape`, not the
  earlier architecture sketch's `[x0, y0, x1, y1]` array.
- Cleanup eval artifact `agent-forge/eval-results/clinical-document-20260506-011856/`
  returned `baseline_met`; it proves fixture/memory strict extraction, not
  OpenEMR fact persistence.
- Final M4 local gate passed via `agent-forge/scripts/check-clinical-document.sh`
  outside the sandbox on 2026-05-05. The sandboxed run can fail PHPStan with
  `Failed to listen on "tcp://127.0.0.1:0": Operation not permitted (EPERM)`;
  this is an execution-environment limitation, not an M4 regression.
- 2026-05-06 host: same script passed again after PHPStan fixes on the clinical
  document gate paths (481 isolated tests, 2297 assertions, 1 skipped; eval
  `baseline_met` under
  `agent-forge/eval-results/clinical-document-20260506-012908/`).
- Same day: clinical eval also run with `AGENTFORGE_CLINICAL_DOCUMENT_EVAL_RESULTS_DIR`
  pointing at `/var/folders/vq/4drfx8g53yx1wpb4_vyfk_f80000gn/T/tmp.K8urMZPvSS/`
  — `baseline_met`, exit `0`, artifact `clinical-document-20260506-011913`
  (isolated from repo `eval-results/` tree).
- Docker `development-easy`: `agentforge-worker` must set
  `AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST` (or `AGENTFORGE_EXTRACTION_FIXTURES_DIR`) when
  `AGENTFORGE_VLM_PROVIDER=fixture`; otherwise `FixtureExtractionProvider` loads an empty
  manifest and every job fails `missing_file` regardless of document bytes. Compose now
  defaults the manifest path to
  `/var/www/localhost/htdocs/openemr/agent-forge/fixtures/clinical-document-golden/extraction/manifest.json`.
- Manual proof same day: core Documents re-upload for patient `900001` stayed HTTP 200
  (upload safety). After recreating `agentforge-worker`, a fresh golden PDF upload
  produced job `16` with `status=succeeded`, `error_code=NULL`, and visible sanitized
  `document.extraction.completed` / `clinical_document.worker.job_completed` logs
  containing `patient_ref` but no raw document bytes or extracted values. Portal path
  was not re-run in that transcript.
- M4 does not persist document facts, promote lab rows, verify patient identity,
  create embeddings, retrieve guideline evidence, or enforce duplicate chart-row
  policy. Those remain M5A/M5B/M5/M6/M7 contracts.

## Week 2 M5A Implementation Notes

Last verified: 2026-05-06.

- M5A introduces `clinical_document_identity_checks` as the durable gate between
  strict extraction and any later trusted facts, embeddings, promotions, or
  retrieval. Identity statuses use strings, not SQL enums; only
  `identity_verified` and future `identity_review_approved` are trusted for
  downstream evidence.
- Strict `lab_pdf` and `intake_form` extraction can now include an optional
  `patient_identity` list of cited identifiers. Missing identifiers remain
  schema-valid, but the production identity gate treats them as
  `identity_ambiguous_needs_review`.
- Identity verification is deterministic: normalized full name plus exact DOB
  verifies; conflicting DOB, MRN/account number, or reliable name+DOB mismatch
  quarantines; partial or missing identifiers require review. Do not infer
  identity from filename, upload category, document metadata, or uncited model
  guesses.
- The queued production path gates inside `IntakeExtractorWorker` after strict
  schema validation and before success. The generic `DocumentJobWorker` remains
  a queue/heartbeat/finalization loop and should not learn extraction-specific
  identity policy.
- `SqlDocumentIdentityCheckRepository` persists redacted identifier summaries:
  kind, field path, confidence, certainty, and citation source metadata. It does
  not persist raw `quote_or_value` in identity check JSON, and M5A adds no raw
  identity values to logs.
- M5A local gate passed with `agent-forge/scripts/check-clinical-document.sh`
  outside the sandbox: 499 isolated tests, 2345 assertions, 1 skipped; clinical
  eval `baseline_met` under
  `agent-forge/eval-results/clinical-document-20260506-020316/`. A sandboxed
  run can still fail PHPStan with localhost bind `EPERM`; rerun outside the
  sandbox for authoritative proof.
- Post-implementation review tightened M5A: `patient_identity` is now required
  in strict JSON output but may be an empty list; DTO parsing rejects omitted
  identity lists; the worker/tool identity gate fails closed when dependencies
  are missing; account numbers are not treated as MRNs; unknown identity status
  values fail closed; nullable chart DOB routes to review instead of crashing.

## Week 2 M5 Implementation Notes

Last verified: 2026-05-06.

- M5 adds `clinical_document_facts` and
  `clinical_document_fact_embeddings` as the durable patient-document fact
  store. Guideline vectors remain isolated in
  `clinical_guideline_chunk_embeddings`; document vectors use `VECTOR(1536)`
  and embedding writes validate dimension count before SQL persistence.
- `IntakeExtractorWorker` now wires `DocumentPromotionPipeline` to
  `SqlDocumentFactRepository` and document fact embeddings after the M5A
  identity gate. Lab facts can persist and promote to OpenEMR `procedure_*`
  rows with promotion provenance; intake findings are forced to
  `document_fact` or `needs_review` and are not silently treated as chart truth.
- `PatientDocumentFactsEvidenceTool` replaces answer-time document
  re-extraction in default evidence composition. It returns only active,
  unretracted, not-deactivated facts from succeeded jobs, with `verified` or
  `document_fact` certainty, trusted identity or explicit human approval, and
  non-deleted source documents.
- Retraction now deactivates document facts and document fact embeddings. Lab
  evidence also independently suppresses AgentForge-promoted
  `procedure_result` rows whose source document is deleted or whose promotion
  or job is inactive or retracted.
- The cited document source gate accepts explicit human review approval
  consistently with evidence retrieval, while still requiring patient/session
  match, succeeded unretracted job, and non-deleted source document.
- M5 proof passed `agent-forge/scripts/check-clinical-document.sh` outside the
  sandbox on 2026-05-06: 582 AgentForge isolated tests / 2888 assertions / 1
  skipped, clinical document eval `baseline_met`, focused PHPStan and PHPCS
  clean. Latest artifact noted during implementation:
  `agent-forge/eval-results/clinical-document-20260506-183912/`.
- Remaining caveat: the default document fact embedding provider is
  deterministic, matching the current local guideline embedding pattern and
  explicit model name; semantic/live document-vector provider selection remains
  future hardening before depending on dense document-vector ranking.

## Week 2 M6 Implementation Notes

Last verified: 2026-05-06.

- M6 added a guideline-only corpus under
  `agent-forge/fixtures/clinical-guideline-corpus/` with corpus version
  `clinical-guideline-demo-2026-05-06` and five checked-in primary-care source
  files: ADA A1c, ACC/AHA LDL, USPSTF screening, hypertension, and
  OpenEMR-local refusal calibration.
- Guideline vectors are stored only in `clinical_guideline_chunk_embeddings`;
  patient document facts must not be mixed into this table. The embedding table
  includes `corpus_version` and is keyed by `(corpus_version, chunk_id)` so
  corpus rebuilds remain versioned.
- `GuidelineCorpusIndexer` produced 25 stable chunks for the current corpus.
  The Docker/OpenEMR runtime command
  `php agent-forge/scripts/index-clinical-guidelines.php` indexed 25 active
  chunks and 25 active embeddings in MariaDB 11.8. Direct host execution still
  depends on a working OpenEMR site database bootstrap and may fail when
  `$GLOBALS['adodb']['db']` is unavailable.
- `HybridGuidelineRetriever` applies sparse retrieval, dense MariaDB Vector
  retrieval, merge/dedupe, mandatory rerank, thresholding, and cited result
  shaping. `DeterministicReranker` is the CI/default path; `CohereReranker` is
  selected by `GuidelineRerankerFactory` only when `AGENTFORGE_COHERE_API_KEY`
  is configured.
- Out-of-corpus guideline questions must return `not_found`/refusal. Generic
  terms such as "guideline" are treated as low-value retrieval terms so
  appendicitis, migraine, and rheumatoid arthritis requests do not accidentally
  retrieve unrelated primary-care chunks.
- The clinical document eval adapter now uses the real in-memory guideline
  retriever for guideline cases instead of hardcoded fake guideline facts.
  `GuidelineRetrievalRubric` is part of the boolean eval gate and the accepted
  baseline requires `guideline_retrieval` pass rate `1.0`.
- M6 proof passed the focused guideline/schema/eval tests, clinical document
  evals, `agent-forge/scripts/check-clinical-document.sh`, and
  `agent-forge/scripts/check-agentforge.sh` on 2026-05-06. Remaining scope:
  production supervisor/final-answer integration is M7, live DB proof is manual
  rather than CI-automated, and SQL sparse search is still LIKE-token based.

## Week 2 M7 Implementation Notes

Last verified: 2026-05-06.

- M7 keeps the MVP orchestration thin. `Supervisor` routes clinical document
  jobs by job status plus identity trust: unfinished jobs go to
  `intake-extractor`, trusted succeeded jobs go to `evidence-retriever`, and
  failed/retracted/untrusted jobs are held.
- `clinical_supervisor_handoffs` uses the Week 2 node language:
  `source_node`, `destination_node`, `decision_reason`, `task_type`, `outcome`,
  `latency_ms`, and `error_reason`. Keep node names exactly `supervisor`,
  `intake-extractor`, and `evidence-retriever`.
- `EvidenceRetrieverWorker` is a wrapper, not a new retriever. It reuses the
  existing chart collector and M6 `GuidelineRetriever`; accepted guideline
  chunks become bounded `guideline` evidence items, while out-of-corpus results
  surface as `Missing or Not Found`.
- Agent responses remain backward-compatible: the flat `answer` and string
  `citations` fields stay, while `sections` and `citation_details` are additive.
  Verifier policy now separates patient claims from guideline claims so patient
  claims cannot be grounded by guideline chunks and guideline claims must cite
  retrieved guideline evidence.
- M7 evals add machine-readable handoffs, final answer sections, answer-level
  citation coverage, and an unsafe-advice refusal case. The clinical document
  baseline remains deterministic and reached `baseline_met`.
- M7 proof passed `agent-forge/scripts/check-clinical-document.sh` and
  `agent-forge/scripts/check-agentforge.sh` on 2026-05-06. In the sandbox,
  PHPStan can fail with localhost bind `EPERM`; rerun the gates outside the
  sandbox for authoritative proof.
- Post-review hardening on 2026-05-06 wired request-level supervisor handoffs
  into `VerifiedAgentHandler`/`agent_request.php`, added a safe missing-corpus
  fallback for guideline retriever construction, fail-closed guideline retrieval
  behavior, richer guideline citation details, and closed the deterministic
  fixture fallback gap where guideline evidence could be mislabeled as patient
  facts. The clinical eval handoff shape now mirrors the database contract.

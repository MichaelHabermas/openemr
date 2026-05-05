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
- A passing syntax/test subset is not the same as the full clinical gate. Preserve exact gate caveats when an eval threshold is expected to fail before downstream implementation exists.

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
- Some isolated suites assume a local server is running. A full `composer phpunit-isolated` can fail for routing tests if `127.0.0.1:8765` is unavailable; record that as environment setup, not as a document-ingestion regression.
- The standard Documents screen has more than one upload entry point, but
  `C_Document::upload_action_process()` is the shared storage boundary used by
  `addNewDocument(...)`. Put document-ingestion dispatch there so core,
  Dropzone, and portal uploads enqueue at most once.
- Wrong-patient document handling is not a delete workflow problem. M2 treats the OpenEMR upload destination as authoritative, creates a job for the uploaded source document if the category is mapped, and retracts that job if the source document is deleted. Content-level patient mismatch detection, rejection, or review belongs to extraction/verification later.
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

Do not claim full clinical-document acceptance from M2 alone unless schema edits, upload hook behavior, idempotency, sanitized logging, and required tests are all proven, and any eval threshold gaps are explicitly accepted.

## Carry Forward To M3+

- M3 worker work should build on `clinical_document_processing_jobs`, including `lock_token`, `locked_at`, attempts, heartbeat behavior, and safe retry semantics.
- M3/M4 must preserve the upload safety contract: document upload is non-blocking and cannot be broken by AgentForge.
- Document extraction must continue the no-raw-PHI logging posture established in M2.
- Before claiming end-to-end acceptance, add or perform a manual browser upload smoke test for both core and portal paths.
- If an active epic file is refreshed, move any reusable proof gaps, architectural decisions, or safety lessons here first.
- Durable clinical schema names and user-visible workflow labels must describe the domain, not the implementing module or product. Use names such as `clinical_document_type_mappings` and normal OpenEMR categories such as `Lab Report`; do not introduce branded `AgentForge...` table names or document categories.

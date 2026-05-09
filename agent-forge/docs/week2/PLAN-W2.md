# AgentForge Week 2 Implementation Plan

## 1. Purpose And Constraints

This plan turns `SPECS-W2.md` and durable AgentForge memory into ordered implementation epics for the Week 2 Clinical Co-Pilot. It is an implementation plan, not another PRD.

AgentForge memory protocol:

- Before starting or resuming any Week 2 epic, read `agent-forge/docs/MEMORY.md`.
- When `CURRENT-EPIC.md` is erased, replaced, or heavily rewritten, first preserve reusable lessons in `agent-forge/docs/MEMORY.md`.
- Update `agent-forge/docs/MEMORY.md` only for durable cross-epic memory: architecture decisions, safety/privacy guardrails, recurring bugs, proof gaps, gate caveats, and carry-forward notes.
- Do not use `agent-forge/docs/MEMORY.md` as a task tracker. Active execution remains in `CURRENT-EPIC.md`, this plan, specs, architecture docs, and code.

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

**May 5 MVP submission checkpoint (course):** On the **deployed** environment, demonstrate lab PDF and intake form ingestion, first structured extraction, and first guideline evidence retrieval. Local development remains normal for iteration; graders expect the checkpoint demo on deployment (stakeholder correction to earlier “working locally” wording). Assignment **recommended steps** (supervisor/workers, CI shape, etc.) should still be visible in draft form—polish can follow.

**Time-boxed MVP checkpoint cut (updated 2026-05-06):** Because the checkpoint is about visible deployed progress, the MVP submission path is now:

Existing OpenEMR upload -> eligible upload creates extraction job -> PHP worker processes required `lab_pdf` and `intake_form` fixtures -> strict cited JSON is produced -> document identity is verified or blocked from trusted use -> a small guideline corpus is indexed/retrieved with cited evidence -> `supervisor`, `intake-extractor`, and `evidence-retriever` names are visible in code/logs -> the demo can show a separated answer with patient extraction output, guideline evidence, needs-review/missing data, and inspectable handoff logs.

The MVP checkpoint may use deterministic fixture-backed extraction and a minimal guideline corpus/retriever. It does not need full OpenEMR lab-row promotion, patient document fact vector search, full promotion duplicate policy, source-document retraction of promoted rows, a 50-case eval expansion, polished source overlay UX, or final submission packaging.

**Full Week 2 MVP (blocking eval gate):** The vertical slice below is complete when it is proven by the blocking Week 2 eval command (typically run locally/CI), independent of demo packaging:

Existing OpenEMR upload -> eligible category creates extraction job -> PHP worker processes job -> `lab_pdf` and `intake_form` fixtures produce strict cited JSON -> document identity is verified or routed to review -> verified lab facts are promoted into OpenEMR-compatible lab records with provenance -> intake findings are stored as cited document facts / needs-review findings -> retracted or identity-unresolved source content is excluded -> document facts are searchable -> guideline evidence is retrieved with sparse + MariaDB Vector + rerank -> `supervisor` handoffs are logged -> final answer separates patient findings, guideline evidence, and needs-review items -> eval gate proves schemas, citations, refusals, factual consistency, bounding boxes, no-PHI logging, duplicate prevention, and deleted-document exclusion.

MVP does not require third document types, demographic overwrites, broad document AI, a critic agent, or polished submission packaging. The full 50-case eval expansion, extended deployment proof beyond the May 5 checkpoint, visual source overlay polish, cost/latency report, and demo packaging continue after the MVP cut line.

## 3. Dependency Map / Implementation Order

1. Create the Week 2 test/eval skeleton and command shape first.
2. Add database schema and upload enqueue path.
3. Add worker skeleton and deterministic job processing.
4. Add strict extraction schemas/providers for `lab_pdf` and `intake_form`.
5. Add document identity gating so wrong-patient or unresolved documents cannot become trusted evidence.
6. For the MVP checkpoint, add a thin guideline corpus and retrieval path that proves sparse/dense retrieval, rerank, citations, and out-of-corpus refusal on a small intentional set.
7. For the MVP checkpoint, add thin `supervisor` / `evidence-retriever` orchestration and separated final-answer behavior with inspectable handoff logs.
8. Pass the time-boxed MVP checkpoint demo/smoke.
9. After the checkpoint, add promotion provenance, review outcomes, and duplicate policy before broad automatic OpenEMR clinical-row promotion.
10. After the checkpoint, add fact persistence, lab promotion, embeddings, and patient document search behind identity/provenance gates.
11. After the checkpoint, add source-document retraction across facts, embeddings, evidence eligibility, and promoted-row overlays before document facts become durable final-answer dependencies.
12. Expand and harden for full Week 2 submission.
13. Keep the FINAL epic last; insert any fixes or extra feature epics before FINAL.

## 4. MVP Epics

All MVP epics are completed. Detailed implementation history is in git;
decisions are in [DECISIONS.md](../epics/DECISIONS.md); compressed summaries
are in [COMPLETED_EPICS_LOG.md](../epics/COMPLETED_EPICS_LOG.md).

### Epic M1 - Clinical Document Eval And Test Skeleton First
Status: Completed. Eval skeleton, rubric scaffolding, `check-clinical-document.sh` gate, and golden-case JSON format established before production code.

### Epic M2 - Schema Migration, Upload Eligibility, And Job Enqueue
Status: Completed (2026-05-05). Upload hook, `clinical_document_type_mappings`/`clinical_document_processing_jobs` schema, source-document retraction, idempotent enqueue.

### Epic M3 - Automatic PHP Worker Skeleton
Status: Completed (2026-05-05). `DocumentJobWorker`, heartbeat schema, atomic claim, sanitized logging, Docker Compose `agentforge-worker` service, graceful SIGTERM via shell trap.

### Epic M4 - Strict Extraction Tool And Schemas
Status: Completed (2026-05-06). `AttachAndExtractTool`, `FixtureExtractionProvider`/`OpenAiVlmExtractionProvider`, lab/intake extraction schemas, certainty classification, deterministic eval path.

### Epic M5A - Document Identity Verification And Wrong-Patient Safeguards
Status: Completed (2026-05-06). `DocumentIdentityVerifier`, `clinical_document_identity_checks`, deterministic patient identity matching, identity status as hard gate for facts/embeddings/promotion.

### Epic M6 - Guideline Corpus, MariaDB Vector, Hybrid Retrieval, And Rerank
Status: Completed (2026-05-06). Five-source primary-care corpus, deterministic chunking, MariaDB `VECTOR(1536)` tables, sparse+dense retrieval, mandatory rerank, thresholded cited output, out-of-corpus refusal.

### Epic M7 - Supervisor, Evidence-Retriever, Final Answer Separation, And MVP Gate
Status: Completed. Deterministic `Supervisor` routing, `EvidenceRetrieverWorker`, separated final answer (Patient Findings / Guideline Evidence / Needs Human Review / Missing), inspectable `clinical_supervisor_handoffs`.

## 5. Post-MVP / Hardening Epics

### Epic M5B - Promotion Provenance, Review Outcomes, And Duplicate Prevention
Status: Completed. `DocumentPromotionPipeline`, `clinical_document_promotions`, source/clinical-content fingerprints, duplicate detection, explicit promotion outcomes.

### Epic M5 - Fact Persistence, Lab Promotion, Embeddings, And Patient Document Search
Status: Completed (2026-05-06). `clinical_document_facts`/`clinical_document_fact_embeddings`, identity-gated retrieval, lab promotion to `procedure_*` rows, duplicate prevention, active-evidence retraction/deletion suppression. 582 tests / 2888 assertions.

### Epic M5C - Promoted Data Retraction And Audit
Status: Completed (2026-05-06). `DocumentRetractionService`, `clinical_document_retractions` audit ledger, stale extract-on-read gates, deleted-source promoted-lab suppression. Gate artifact `clinical-document-20260506-230608`.

### Epic H1 - 50-Case Eval Expansion And Regression Policy
Status: Completed (2026-05-08). 65-case golden set, `StructuralCoveragePolicy`, all rubric thresholds at 1.0, `regression_max_drop_pct` = 5, contract-only DOCX/XLSX/TIFF/HL7v2 coverage.

### Epic H2 - Visual PDF Source Review And Retraction UX
Status: Completed (2026-05-07). Source-review modal with citation metadata/quote, deployed VM browser proof (12→2 citations after deletion). See `EPIC_VISUAL_PDF_SOURCE_REVIEW_AND_RETRACTION_UX.md`.

### Epic H3 - Deployment Runtime, Health, And Smoke Proof
Status: Completed (2026-05-07). Health gate (MariaDB, worker, queue), deployed smoke, manual UI verification on `gauntlet-mgh`. Latest deployed smoke: `clinical-document-deployed-smoke-20260508-001525.json`.

### Epic H4 - Observability, Cost/Latency, And Privacy Hardening
Status: Completed (2026-05-07). Recursive telemetry sanitization, centralized model cost arithmetic, document extraction stage timings, reproducible cost/latency report.

### Epic H5 - Demo Readiness And Documentation Alignment
Status: Completed (2026-05-07). Reviewer guide, Week 2 docs hub, acceptance matrix, `.env.sample`, docs tests (38 tests / 247 assertions).

## 6. FINAL Epic - FINAL Submission Packaging And Reviewer Proof

Status: Not started.

Goal: Produce the final Week 2 submission package. This epic must remain last; insert any fixes or extra feature epics before FINAL.

Acceptance criteria:

- Reviewer rerun instructions work from a clean local or deployed environment.
- Final acceptance matrix has proof for every Week 2 required item.
- Demo video shows upload, extraction, guideline retrieval, final cited answer, handoffs, eval gate, and deployed health.
- Final gate passes; final docs and artifacts are internally consistent.
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
- `agent-forge/scripts/run-clinical-document-evals.php` runs the clinical document gate and writes artifacts.
- `agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md` records the current clinical-document cost/latency report.

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

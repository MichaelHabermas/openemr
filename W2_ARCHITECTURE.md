# AgentForge Week 2 Architecture

This document is the **Week 2 submission artifact** for the Clinical Co-Pilot multimodal track: document ingestion, supervisor/worker graph, hybrid RAG, eval gate, and deployment story.

**Related docs:** `ARCHITECTURE.md` (W1 system overview), `agent-forge/docs/week2/README.md` (Week 2 doc index), `agent-forge/docs/SPECS-W2.txt` (requirements).

**Scope note.** W2 generalizes the agent to any uploaded lab report or intake form. There is no disease-specific tuning in extraction, retrieval, or verification. The guideline corpus covers common outpatient conditions (lipids, glycemia, blood pressure, USPSTF preventive screening); cases outside that corpus return a deterministic "out-of-corpus" refusal rather than a hallucinated answer.

## 1. Document ingestion

- **Supported types:** `lab_pdf`, `intake_form`.
- **Tool surface:** `attach_and_extract(patient_id: PatientId, file_path: string, doc_type: DocType): ExtractionResult` exposed from `src/AgentForge/Documents/`.
- **Flow:**
  1. PDF uploaded via existing OpenEMR document upload (not bypassed).
  2. Bytes stored as a FHIR `Binary`; metadata stored as `DocumentReference` linked to the patient.
  3. Extractor worker reads the `Binary`, runs the VLM, and returns strict-schema JSON.
  4. Lab values persist as FHIR `Observation` resources, each with a `derivedFrom` reference back to the `DocumentReference` and a stored quote/page citation.
- **Integrity:**
  - Duplicate detection via SHA-256 hash of file bytes; same hash for same patient short-circuits re-extraction and returns the prior result.
  - All derived facts round-trip: every `Observation` carries `source_id` (DocumentReference UUID), `page`, and `quote` so the chart panel can re-render the citation.
  - Upload failure or extraction failure leaves the `DocumentReference` in `status=error`; no partial `Observation` rows are written.

## 2. Schemas

Both schemas are PHP value objects under `src/AgentForge/Documents/Schema/` with strict types and constructor validation. The VLM must return JSON conforming to these shapes; non-conforming output fails extraction (no silent coercion).

- **Lab (`LabFinding`):** `test_name: string`, `value: float|string`, `unit: ?string`, `reference_range: ?string`, `collected_at: ?DateTimeImmutable`, `abnormal_flag: ?Flag`, `page: int`, `quote: string`, `confidence: float`.
- **Intake (`IntakeRecord`):** `demographics: Demographics`, `chief_concern: string`, `medications: list<MedicationEntry>`, `allergies: list<AllergyEntry>`, `family_history: list<FamilyHistoryEntry>`, `section: string`, `quote: string`, `confidence: float`.
- **Validation tests:** isolated PHPUnit suite under `tests/Tests/Isolated/AgentForge/Documents/` exercises (a) golden good payloads, (b) malformed payloads (missing required fields, type drift), (c) confidence-below-threshold rejection.

## 3. Hybrid RAG + rerank

- **Corpus:** general primary-care guideline excerpts under `agent-forge/fixtures/w2-corpus/` — ~50-100 chunks covering glycemic targets, lipid management, hypertension, and USPSTF preventive screening. Each chunk has `{guideline_id, section, page, text}`.
- **Indexing:** built once at deploy time into a SQLite-backed BM25 index plus a flat-file dense index using OpenAI embeddings (`text-embedding-3-small`). No external vector DB.
- **Retrieval:**
  - Sparse top-20 (BM25) ∪ dense top-20 → 30-40 unique candidates.
  - Cohere Rerank (`rerank-english-v3.0`) compresses to top-5.
  - Out-of-corpus detection: if the top reranker score is below a calibrated threshold, the retriever returns an empty result and the supervisor emits a deterministic refusal.
- **Output to the agent:** array of `{guideline_id, section, page, text, score}` — no model-generated commentary, no chunk merging.

## 4. Supervisor and workers

- **Framework:** PHP state machine in `src/AgentForge/Graph/`. No Python sidecar, no LangGraph runtime — keeps everything in-process and inspectable from existing PHP traces.
- **Graph:**
  - `Supervisor` routes deterministically based on the request shape: chart question → `EvidenceRetriever`; uploaded document → `Extractor` then optional `EvidenceRetriever`.
  - `Extractor` worker: loads the document `Binary`, calls the VLM, validates against the schema, persists `Observation`s.
  - `EvidenceRetriever` worker: takes the question (and any newly-extracted facts) and runs the hybrid RAG pipeline.
  - Final answer is composed by the existing W1 draft+verifier path, now sourced from worker outputs instead of direct chart tools.
- **Termination:** the supervisor terminates on (a) successful verifier pass, (b) verifier rejection with no retry budget left, or (c) any worker raising a typed failure.
- **Logging:** every handoff writes a row to `AgentTelemetry` with `from_node`, `to_node`, `decision_reason`, `latency_ms`. No prompt content is logged.

## 5. Citation contract

- **Machine-readable shape (per spec):**
  ```json
  {
    "source_type": "lab_pdf | intake_form | guideline | chart",
    "source_id": "uuid",
    "page_or_section": "p.2 | §3.1",
    "field_or_chunk_id": "test_name=A1c | chunk_4f2",
    "quote_or_value": "verbatim text or numeric value"
  }
  ```
- **Producers:** Extractor emits citations for every `LabFinding`/`IntakeRecord` field; EvidenceRetriever emits one per returned chunk; chart tools (W1) already conform.
- **Verifier:** the existing deterministic verifier rejects any response sentence that does not have at least one citation whose `quote_or_value` appears as a substring in the cited source.
- **UI:** the chart panel renders a "View source" affordance per citation. For PDFs, clicking opens the document at the cited page with a bounding-box overlay derived from the stored quote (substring match against the page's text layer).

## 6. Eval gate

- **Golden set:** 50 cases at `agent-forge/fixtures/w2-eval-cases.json`, distributed across upload type and condition so no single disease dominates:
  - 20 lab PDF extractions (mixed: lipids, A1c/glucose, BP-related panels, CBC, basic metabolic).
  - 15 intake form extractions (varied chief concerns).
  - 10 cited-answer cases combining an uploaded document with a guideline question.
  - 5 safe-refusal cases (out-of-corpus questions, conflicting evidence, missing data).
- **Boolean rubrics (per case):** `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`. All five must pass; no partial credit.
- **CI:** wired into `.github/workflows/agentforge-evals.yml` as a Tier 1 PR-blocking job (`w2-evals`). Failure of any rubric on any case fails the PR check. Tier 2 (live LLM) and Tier 4 (deployed smoke) extend the same fixtures nightly.
- **Regression policy:** the W2 fixture file is append-only without a migration note; cases may not be silently weakened.

## 7. Observability and cost

- **Per-encounter telemetry** (extends W1 `stage_timings_ms`):
  - `supervisor:route`, `extractor:vlm`, `extractor:schema_validate`, `retriever:bm25`, `retriever:dense`, `retriever:rerank`, `verifier:check`, `draft:generate`.
  - Token counts and cost estimate per stage; retrieval hit count; extraction confidence (min across fields); final eval rubric outcome.
- **PHI hygiene:**
  - Prompts and intermediate worker payloads are treated as sensitive — never logged, never echoed.
  - Telemetry rows store IDs and timings only; no patient names, no quoted text, no document bytes.
  - Trace export tooling redacts `quote_or_value` fields before writing to disk.
- **Known gap (inherited from W1):** the cost-tracking pricing matrix in `DraftProviderConfig` only knows `gpt-4o-mini`; the configured runtime model emits a zero cost estimate. Tracked, not blocking the W2 gate.

## 8. Risks and tradeoffs

- **VLM extraction error.** Mitigation: strict schema rejection, per-field confidence threshold, verifier substring match against the original page. Residual risk: a verbatim-quoted but mis-attributed value (e.g., wrong row of a table) — caught only by the eval set, not at runtime.
- **Supervisor opacity.** Deterministic PHP routing was chosen specifically so handoffs are auditable in plain logs; the cost is less flexibility than an LLM-routed graph. Acceptable for a primary-care safety surface.
- **W1 stated "no multi-agent system."** W2 explicitly supersedes that v1 decision. The W1 ARCHITECTURE.md note is preserved for history; this document is the current source of truth for the graph layer.
- **Eval flakiness.** Tier 1 (PR-blocking) uses fixtures and seeded SQL — no live LLM calls — so it is deterministic. Tier 2 nightly variability is observed but does not block merges.
- **Corpus scope.** General primary-care coverage is intentionally narrow; out-of-corpus questions return a refusal, which is correct behavior but reads as a limitation in casual demo. Documented in the operator README.

## 9. Deployment

- **Public URL:** `https://openemr.titleredacted.cc/` (W1 deployment; W2 path lives behind feature flag `AGENTFORGE_W2_ENABLED`).
- **Required env vars (additions over W1):**
  - `AGENTFORGE_VLM_PROVIDER=anthropic`
  - `AGENTFORGE_VLM_MODEL=claude-sonnet-4-6`
  - `AGENTFORGE_COHERE_API_KEY`
  - `AGENTFORGE_RAG_CORPUS_PATH=agent-forge/fixtures/w2-corpus`
  - `AGENTFORGE_RAG_ENABLED=1`
  - `AGENTFORGE_W2_ENABLED=1`
- **Grader quickstart:**
  1. `git checkout master` (W2 lives on `master`, not a feature branch).
  2. `agent-forge/scripts/deploy-vm.sh` deploys the same path graders see in production.
  3. Local: `cd docker/development-easy && docker compose up --detach --wait`, then visit `http://localhost:8300/`, log in `admin`/`pass`, open any patient, upload a lab PDF via the chart panel.
  4. Eval gate: `composer agentforge-w2-evals` (runs the 50-case Tier 1 suite locally; same command CI runs).
- **W1 vs W2 separation:** the chart-question single-agent path remains the W1 fallback when `AGENTFORGE_W2_ENABLED=0`. Both paths share the verifier, citation contract, and telemetry tables.

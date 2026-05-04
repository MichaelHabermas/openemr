# AgentForge Week 2 Architecture

This document is the **Week 2 submission artifact** for the Clinical Co-Pilot multimodal track: document ingestion, supervisor/worker graph, hybrid RAG, eval gate, and deployment story. Expand each section as implementation lands.

**Related docs:** `ARCHITECTURE.md` (ongoing product/system architecture), `agent-forge/docs/week2/README.md` (Week 2 doc index), `agent-forge/docs/SPECS-W2.txt` (requirements).

## 1. Document ingestion

- **Supported types:** `lab_pdf`, `intake_form` (and stretch types if any).
- **Flow:** upload → associate with patient → store source in OpenEMR → extract strict-schema JSON → persist derived facts (FHIR/OpenEMR) with traceability.
- **Tool/API surface:** e.g. `attach_and_extract(patient_id, file_path, doc_type)` or equivalent.
- **Integrity:** how duplicates are avoided; how derived observations round-trip.

## 2. Schemas

- **Lab:** test name, value, unit, reference range, collection date, abnormal flag, source citation (minimum fields per spec).
- **Intake:** demographics, chief concern, medications, allergies, family history, source citation.
- **Implementation:** Pydantic/Zod (or equivalent) locations and validation tests.

## 3. Hybrid RAG + rerank

- **Corpus:** guideline scope, indexing location, chunking notes.
- **Retrieval:** sparse + dense; reranker (e.g. Cohere or equivalent).
- **Output to the agent:** top chunks with source metadata only.

## 4. Supervisor and workers

- **Framework:** LangGraph, OpenAI Agents SDK, or other inspectable orchestration.
- **Graph:** supervisor routes to **intake-extractor** and **evidence-retriever**; handoffs logged.
- **Termination:** when extraction vs retrieval vs final answer; what is logged per step.

## 5. Citation contract

- **Machine-readable shape:** `source_type`, `source_id`, `page_or_section`, `field_or_chunk_id`, `quote_or_value` (minimum per spec).
- **UI:** PDF bounding-box overlay + click-to-source for snippets.

## 6. Eval gate

- **Golden set:** 50 cases; location under `agent-forge/fixtures/w2-golden/` (or documented path).
- **Rubrics:** boolean categories including `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`.
- **CI:** PR-blocking hook; regression thresholds per spec.

## 7. Observability and cost

- Per-encounter: tool sequence, latency by step, tokens, cost estimate, retrieval hits, extraction confidence, eval outcome.
- **PHI:** no raw PHI in logs; prompts/traces treated as sensitive.

## 8. Risks and tradeoffs

- VLM hallucination vs schema enforcement; supervisor opacity; eval flakiness; deployment constraints.

## 9. Deployment

- Public URL, env vars, how graders run the Week 2 path without guessing branch or services.

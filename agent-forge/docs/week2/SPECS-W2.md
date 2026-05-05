# AgentForge Clinical Co-Pilot - Week 2 Specs

Source documents:

- `agent-forge/docs/week2/Week-2-AgentForge-Clinical-Co-Pilot.pdf`
- `agent-forge/docs/week2/Week-2-AgentForge-Clinical-Co-Pilot.txt`

This document converts the Week 2 assignment into an implementation-ready specification. It is the source of truth for the Week 2 multimodal Clinical Co-Pilot scope: clinical document ingestion, structured extraction, guideline retrieval, supervisor/worker routing, citations, evaluation, deployment, and submission proof.

## 1. Objective

Week 2 extends the Week 1 Clinical Co-Pilot from a structured OpenEMR chart agent into a multimodal evidence agent.

The agent must support a primary care physician preparing for a follow-up visit. The chart may contain structured OpenEMR data, but the most important recent information may be buried in an uploaded scanned lab PDF and a patient intake form. The physician asks what changed, what deserves attention, and what evidence supports the answer.

The Week 2 system must:

- Ingest a lab PDF and an intake form associated with an OpenEMR patient.
- Extract structured facts from those documents using strict schemas.
- Link every extracted fact to source evidence.
- Persist source documents and derived observations without duplicates or untraceable records.
- Retrieve relevant clinical guideline evidence through a basic hybrid RAG pipeline.
- Route work through one inspectable supervisor and two required workers.
- Return grounded answers that separate patient-record facts from guideline evidence.
- Block regressions through a 50-case eval-driven CI gate.

A demo that appears to work but cannot block regressions does not satisfy Week 2.

## 2. Scope

### 2.1 In Scope

The core Week 2 build includes:

- Two document types: `lab_pdf` and `intake_form`.
- One document ingestion and extraction tool, named `attach_and_extract(patient_id, file_path, doc_type)`. A differently named interface is allowed only if it accepts the same inputs and provides the same validation, persistence, citation, and error behavior.
- Strict structured schemas for lab PDF extraction and intake form extraction.
- OpenEMR storage for uploaded source documents.
- Persistence of derived facts as appropriate FHIR resources or OpenEMR records.
- Machine-readable citations for all extracted facts and final clinical claims.
- A basic hybrid retrieval pipeline using sparse retrieval plus dense retrieval.
- A reranking step using Cohere Rerank or another reranker that takes retrieved candidate chunks as input and returns a ranked list of candidate chunks with scores.
- A small clinical-guideline corpus relevant to primary care follow-up workflows.
- One supervisor.
- Two required workers: `intake-extractor` and `evidence-retriever`.
- Logged and inspectable supervisor handoffs.
- A 50-case synthetic or demo golden dataset.
- Boolean eval rubrics.
- A PR-blocking CI or Git Hook gate.
- Observable deployed Week 2 flow.
- A 3-5 minute demo video.
- A cost and latency report.

### 2.2 Extension Scope

The following are useful extensions only after all core requirements pass:

- Critic agent that rejects uncited claims or unsafe action suggestions.
- Click-to-source UI for citation snippets with document preview.
- Third document type, such as referral fax or medication list.
- Lab trend chart widget using extracted Observation data.
- Retrieval improvements such as better chunking, query rewriting, domain-specific filters, ColQwen2, or multi-vector indexing.

Extension work must not displace core requirements. A narrow complete system is preferred over a broad partial system.

### 2.3 Out of Scope

The Week 2 MVP is not:

- A full medical-document AI platform.
- A system for every possible uploaded clinical document.
- An uncited VLM answer generator.
- An LLM-as-judge system with vague 1-10 ratings.
- A black-box multi-agent orchestration demo.
- A clinical decision-maker.
- A replacement for physician judgment.
- A system that logs raw PHI, raw document text, screenshots, traces with PHI, or patient identifiers to SaaS observability tools.

## 3. User And Scenario

### 3.1 Primary User

The primary user is a primary care physician preparing for a follow-up patient visit.

### 3.2 Required Scenario

Given an OpenEMR patient chart with structured data plus an uploaded scanned lab PDF and uploaded patient intake form, the physician asks:

> What changed, what should I pay attention to, and what evidence supports the recommendation?

The agent must provide an answer that remains useful when:

- The document scan is imperfect.
- The patient record is incomplete.
- The physician asks a follow-up question.
- Extracted facts have uncertainty.
- Guideline evidence is available only in a small indexed corpus.

### 3.3 Required Answer Behavior

The final answer must:

- Distinguish patient-record facts from guideline evidence.
- Cite every medication, lab, document-extracted fact, and guideline claim.
- Report missing data as missing instead of inferring.
- Disclose low-confidence extraction where applicable.
- Refuse or narrow unsafe, unsupported, or out-of-corpus requests.
- Avoid unsupported diagnosis, treatment, dosing, or medication-change advice.

## 4. Milestones And Deadlines

All deadlines are Central Time (Austin). This spec assumes the Week 2 sprint starts on Monday, May 4, 2026.

| Checkpoint | Deadline | Required Outcome |
| --- | --- | --- |
| Architecture Defense | Monday, May 4, 2026, exactly 4 hours after sprint kickoff | Document schemas, RAG design, eval design, security concerns, and key tradeoffs before implementation expands. |
| MVP | Tuesday, May 5, 2026 at 11:59 PM CT | Lab PDF and intake form ingestion work locally. First structured extraction and first guideline evidence retrieval demo are available. |
| Early Submission | Thursday, May 7, 2026 at 11:59 PM CT | Supervisor plus 2 workers, 50-case eval suite, PR-blocking CI, deployed app, and demo video are complete. |
| Final | Sunday, May 10, 2026 at 12:00 PM CT | Production-ready Week 2 agent, source-grounded demo, cost/latency report, and interview readiness are complete. |

If the actual sprint kickoff timestamp differs, the weekday checkpoint dates remain fixed and the Architecture Defense is due exactly 4 hours after the documented kickoff timestamp.

## 5. Stage Requirements

### 5.1 Stage 1 - Ingest Lab PDF And Intake Form

Implement a document ingestion flow that:

- Accepts an uploaded file.
- Associates the file with a specific patient.
- Stores the source document in OpenEMR.
- Supports exactly these required `doc_type` values: `lab_pdf`, `intake_form`.
- Extracts structured JSON.
- Validates extracted JSON against a strict schema.
- Links every derived fact back to the source document.
- Persists derived facts as appropriate FHIR resources or OpenEMR records.
- Prevents duplicate or untraceable records.
- Fails clearly when upload, storage, extraction, validation, or persistence fails.

Required tool interface:

```text
attach_and_extract(patient_id, file_path, doc_type)
```

An equivalent interface is acceptable only if it provides the same inputs, outputs, validation, persistence, and citation behavior.

Acceptance criteria:

- `lab_pdf` upload and extraction work locally.
- `intake_form` upload and extraction work locally.
- Source document storage happens before derived fact persistence.
- No derived fact is persisted without a source citation.
- Duplicate document uploads for the same patient do not create duplicate derived observations or records.
- Extraction failure does not silently create partial trusted facts.

### 5.2 Stage 2 - Build Basic Hybrid RAG

Create a small clinical-guideline corpus relevant to the primary care workflow.

The retrieval pipeline must:

- Index guideline content with source metadata.
- Use sparse keyword retrieval.
- Use dense vector retrieval.
- Combine sparse and dense candidates.
- Rerank candidate chunks with Cohere Rerank or another reranker that ranks candidate chunks and returns scores.
- Return only the top grounded evidence snippets to the answer model.
- Include source metadata for every returned snippet.
- Return a deterministic refusal or "not found in corpus" result when evidence is insufficient.

Acceptance criteria:

- At least one guideline question can retrieve relevant evidence.
- Returned evidence includes source metadata.
- The final answer does not cite guideline evidence that was not retrieved.
- Out-of-corpus questions do not produce invented guideline claims.

### 5.3 Stage 3 - Add Supervisor And Two Workers

Implement a small inspectable graph with:

- One `supervisor`.
- One `intake-extractor` worker.
- One `evidence-retriever` worker.

The supervisor must decide:

- Whether document extraction is needed.
- Whether guideline evidence retrieval is needed.
- Whether enough evidence exists to produce a final answer.
- Whether the request must be refused or narrowed.

Worker responsibilities:

- `intake-extractor`: process uploaded clinical documents, produce strict-schema extraction, attach source citations, and persist validated facts.
- `evidence-retriever`: retrieve guideline evidence using the hybrid RAG plus rerank pipeline and return cited snippets.

Required handoff logging:

- Source node.
- Destination node.
- Decision reason.
- Input type or task type.
- Outcome.
- Latency.
- Error reason, if any.

Acceptance criteria:

- Supervisor decisions are inspectable in logs or traces.
- Worker responsibilities do not overlap in a way that makes accountability unclear.
- The supervisor does not become an opaque model-only router.
- Handoffs are explicit enough for a reviewer to reconstruct the path taken for a request.

### 5.4 Stage 4 - Build The Eval Gate

Create a 50-case golden dataset using synthetic or demo data only.

The dataset must exercise:

- Lab PDF extraction.
- Intake form extraction.
- Evidence retrieval.
- Citation presence.
- Citation correctness.
- Safe refusals.
- Missing-data behavior.
- No-PHI logging behavior.
- Imperfect or incomplete document inputs.
- Follow-up questions.

Required boolean rubric categories:

- `schema_valid`
- `citation_present`
- `factually_consistent`
- `safe_refusal`
- `no_phi_in_logs`

Rubrics must be boolean. 1-10 ratings do not satisfy the requirement.

CI gate requirements:

- The eval suite runs automatically in a PR-blocking Git Hook, CI job, or another blocking check that prevents merge or submission when the gate fails.
- The build fails if any rubric category regresses by more than 5%.
- The build fails if any rubric category drops below its configured pass threshold.
- Rubric thresholds are documented.
- Eval results are saved in a reviewable artifact.
- The gate is deterministic enough to catch intentional regressions during grading.

Acceptance criteria:

- 50 cases exist and are runnable.
- Every case has expected behavior.
- Every case is judged with the required boolean rubric categories.
- A reviewer can introduce a small regression and see the gate fail.

### 5.5 Stage 5 - Integrate, Deploy, And Defend

Expose the Week 2 flow in the deployed app.

The deployed app must demonstrate:

- Document upload or attachment for the required document types.
- Structured extraction output.
- Source citations for extracted facts.
- Guideline evidence retrieval.
- Final source-grounded answer.
- Supervisor and worker handoffs.
- Eval results.
- Observability traces.
- Latency measurements.
- Cost estimates.

Acceptance criteria:

- Graders can run the Week 2 flow without guessing branch, environment variables, services, or setup steps.
- README clearly separates Week 1 baseline behavior from Week 2 multimodal behavior.
- Demo video shows the full source-grounded flow end to end.
- Cost and latency report includes actual dev spend, projected production cost, p50 latency, p95 latency, and bottleneck analysis.

## 6. Functional Requirements

### FR-1: Document Attachment And Extraction Tool

The system must provide `attach_and_extract(patient_id, file_path, doc_type)`. A different function name is allowed only if the tool contract remains identical.

The tool must:

- Accept only supported document types: `lab_pdf`, `intake_form`.
- Bind the document to the supplied patient.
- Store the source document in OpenEMR.
- Extract structured JSON.
- Validate JSON using strict schemas.
- Return the validated extraction result.
- Persist derived facts when validation succeeds.
- Attach citation metadata to every derived fact.
- Return typed errors for unsupported document type, missing file, storage failure, extraction failure, schema validation failure, persistence failure, and duplicate detection.

### FR-2: Lab PDF Extraction Schema

The lab PDF schema must include these minimum required fields:

- Test name.
- Value.
- Unit.
- Reference range.
- Collection date.
- Abnormal flag.
- Source citation.

Each lab fact must be attributable to a specific source document location.

### FR-3: Intake Form Extraction Schema

The intake form schema must include these minimum required fields:

- Demographics fields.
- Chief concern.
- Current medications.
- Allergies.
- Family history.
- Source citation.

Each intake fact must be attributable to a specific source document location.

### FR-4: Strict Schema Enforcement

Schemas must be implemented with Pydantic, Zod, PHP value objects with validation, or another strict schema system that rejects missing required fields, invalid types, unsupported enum values, and uncited facts before persistence.

The system must reject:

- Missing required fields.
- Invalid data types.
- Unsupported enum values.
- Facts without citations.
- Extraction output that cannot be parsed as valid JSON.

### FR-5: OpenEMR And FHIR Integrity

Uploaded documents and derived observations must round-trip through OpenEMR.

The system must:

- Store the original uploaded document.
- Preserve a stable source identifier.
- Persist derived observations or records only when validation succeeds.
- Preserve traceability from each derived fact to the uploaded document.
- Avoid duplicate records on repeated uploads.
- Avoid untraceable records.

### FR-6: Hybrid RAG

The system must maintain a small clinical-guideline corpus and retrieve evidence through:

- Sparse keyword search.
- Dense vector search.
- Candidate merging.
- Reranking.
- Top evidence selection.

Each retrieved chunk must include:

- Guideline/source identifier.
- Page, section, or another stable source location that can be opened or reviewed by a grader.
- Chunk identifier.
- Evidence text or excerpt.
- Retrieval or rerank score. If a backend cannot provide a numeric score, the field must be present with `null` and the retrieval README must explain why.

### FR-7: Supervisor Routing

The supervisor must route work based on request needs.

Minimum routing cases:

- Uploaded document requires extraction.
- Clinical question requires guideline retrieval.
- Uploaded document plus clinical question requires extraction followed by retrieval.
- Missing or insufficient evidence requires refusal or narrowing.
- Final answer is ready only when required evidence and citations are available.

### FR-8: Final Answer Generation

Final answers must:

- Use only patient facts and guideline evidence available in the evidence bundle.
- Include machine-readable citation metadata for every clinical claim.
- Separate patient-record facts from guideline evidence.
- Identify missing data.
- Identify low-confidence or failed extraction when relevant.
- Refuse unsupported or unsafe medical advice.

### FR-9: Visual PDF Source Overlay

The UI must support visual source review for PDF citations.

Minimum requirement:

- A cited PDF fact can be opened against the source PDF page.
- The cited evidence location is highlighted through a bounding-box overlay. If bounding boxes are unavailable, the fallback must open the exact source page and visibly highlight or select the cited quote/value.

If exact bounding boxes cannot be extracted from the PDF, the implementation must provide a deterministic fallback that still directs the reviewer to the exact page and quoted value.

### FR-10: Follow-Up Question Support

The agent must support follow-up questions without losing grounding.

Follow-up handling must:

- Preserve patient context safely.
- Reuse or refresh extracted facts only when source links remain intact.
- Retrieve additional guideline evidence when the follow-up requires it.
- Continue citing every clinical claim.
- Refuse if patient identity, source evidence, or conversation context is unclear.

## 7. Citation Contract

Every clinical claim in the final response must include machine-readable citation metadata.

Minimum citation shape:

```json
{
  "source_type": "lab_pdf | intake_form | guideline | chart",
  "source_id": "stable source identifier",
  "page_or_section": "page number, section, or another stable source location",
  "field_or_chunk_id": "schema field name or retrieved chunk id",
  "quote_or_value": "verbatim quote, extracted value, or cited evidence value"
}
```

Citation rules:

- Patient-record facts must cite OpenEMR chart data or uploaded clinical documents.
- Guideline claims must cite guideline corpus chunks.
- A medication or lab claim is unacceptable without a source citation.
- A citation must be specific enough for a reviewer to locate the supporting evidence.
- Final responses must not merge patient facts and guideline evidence into one uncited paragraph.
- Unsupported extracted facts must be visible as unsupported, low confidence, or rejected.

## 8. Data, Privacy, And Security Requirements

The system must be HIPAA-minded even when using demo data.

Required safeguards:

- Use only demo or synthetic data.
- Treat prompts, extracted fields, document images, traces, screenshots, and logs as sensitive.
- Do not log raw PHI.
- Do not log raw document text.
- Do not log patient identifiers where a non-PHI source ID or redacted ID is sufficient.
- Do not send unnecessary chart data to the model.
- Do not persist raw model prompts in SaaS tools.
- Do not expose model credentials to the browser.
- Refuse requests when user identity, patient identity, authorization, or source evidence is unclear.
- Document security risks and mitigation decisions in the Week 2 architecture documentation.

Required no-PHI eval coverage:

- At least one eval case must inspect logs or telemetry for raw PHI leakage.
- The `no_phi_in_logs` rubric must be part of the 50-case gate.

## 9. Observability And Cost Requirements

Each encounter must log enough metadata to inspect behavior without exposing raw PHI.

Required telemetry:

- Tool sequence.
- Supervisor handoffs.
- Worker outcomes.
- Latency by step.
- Token usage.
- Cost estimate.
- Retrieval hits.
- Extraction confidence.
- Eval outcome.
- Error type and failure location, when applicable.

Required latency/cost report:

- Actual development spend.
- Projected production cost.
- p50 latency.
- p95 latency.
- Bottleneck analysis.
- Any known tradeoffs between speed, completeness, and citation quality.

## 10. Eval Requirements

### 10.1 Dataset Composition

The golden set must include 50 or more cases. A 50-case set is the expected Week 2 baseline; fewer than 50 cases does not satisfy the Week 2 core gate.

The dataset must include all of the following categories:

- Lab PDF extraction cases.
- Intake form extraction cases.
- Combined document-plus-guideline answer cases.
- Missing-data cases.
- Safe-refusal cases.
- Citation regression cases.
- No-PHI logging cases.

### 10.2 Required Rubrics

Each case must evaluate the required boolean rubrics:

- `schema_valid`: output conforms to the expected strict schema.
- `citation_present`: required claims and extracted facts include citations.
- `factually_consistent`: answer matches the cited source and does not invent facts.
- `safe_refusal`: unsafe, unsupported, ambiguous, or out-of-corpus requests are refused or narrowed correctly.
- `no_phi_in_logs`: logs and traces do not contain raw PHI.

### 10.3 Gate Policy

The CI gate must fail when:

- Any required rubric regresses by more than 5%.
- Any required rubric drops below the documented pass threshold.
- Schema validation fails for a required extraction.
- Any patient-specific clinical claim lacks a citation.
- Raw PHI appears in logs or traces.
- The eval runner cannot complete successfully.

Thresholds must be documented beside the eval runner or in the eval README.

## 11. Submission Deliverables

### 11.1 GitLab Repository

The repository must include:

- Week 1 fork with Week 2 changes.
- Setup guide.
- Deployed link.
- Clear environment-variable documentation.
- Clear separation between Week 1 baseline behavior and Week 2 multimodal behavior.

### 11.2 Week 2 Architecture Document

Create or update `./W2_ARCHITECTURE.md`.

It must explain:

- Document ingestion flow.
- Extraction schemas.
- Worker graph.
- RAG design.
- Eval gate.
- Security risks.
- Tradeoffs.
- Known limitations.

### 11.3 Schemas

Provide strict schemas for:

- `lab_pdf`
- `intake_form`

Schema deliverables must include:

- Source citation fields.
- Validation tests.
- Failure behavior.

### 11.4 Eval Dataset And Results

Provide:

- 50 synthetic or demo cases.
- Expected behavior for each case.
- Boolean rubrics.
- Judge configuration.
- Eval runner.
- Saved eval results.

### 11.5 CI Evidence

Provide evidence that the eval suite blocks regressions.

Acceptable evidence includes:

- CI job configuration.
- Git Hook configuration.
- Failing regression demonstration.
- Passing baseline run.
- Instructions for graders to rerun the gate.

### 11.6 Demo Video

Record a 3-5 minute demo video showing:

- Document upload.
- Lab PDF extraction.
- Intake form extraction.
- Evidence retrieval.
- Final cited answer.
- Visual citation/source review.
- Eval results.
- Observability/cost or latency trace.

### 11.7 Cost And Latency Report

Provide a report covering:

- Actual development spend.
- Projected production cost.
- p50 latency.
- p95 latency.
- Bottleneck analysis.
- Cost drivers.
- Latency drivers.

### 11.8 Deployed Application

The deployed application must be publicly accessible and must run the Week 2 core flow.

Graders must be able to:

- Reach the app.
- Identify the Week 2 flow.
- Upload or attach required documents.
- Run the demo scenario.
- Inspect citations.
- Verify eval and CI evidence.

## 12. Acceptance Matrix

| Area | Required Evidence | Pass Criteria |
| --- | --- | --- |
| Lab PDF ingestion | Upload/extraction demo, schema output, persisted source link | Lab facts extract into strict JSON and every fact cites source evidence. |
| Intake form ingestion | Upload/extraction demo, schema output, persisted source link | Intake facts extract into strict JSON and every fact cites source evidence. |
| OpenEMR/FHIR integrity | Stored source document plus derived records | Derived observations round-trip and remain traceable to source document. |
| Duplicate handling | Repeat upload test | Duplicate source documents do not create duplicate derived facts. |
| Hybrid RAG | Corpus, index, retrieval logs, rerank output | Guideline evidence is retrieved through sparse+dense retrieval and reranked before answer generation. |
| Supervisor graph | Handoff logs/traces | Supervisor routes to required workers and decisions are inspectable. |
| Citation contract | Final response payload and UI | Every clinical claim has machine-readable citation metadata. |
| PDF source review | UI proof | Cited PDF facts can be reviewed on source page with bounding-box overlay or deterministic equivalent. |
| Eval dataset | Fixture files and README | At least 50 cases cover extraction, retrieval, citations, refusals, missing data, and no-PHI logs. |
| Boolean rubrics | Eval config/results | Required rubric categories are boolean and reviewable. |
| CI gate | CI/Git Hook evidence | Meaningful regression blocks PR/build. |
| Observability | Trace/log sample | Tool sequence, latency, token usage, cost, retrieval hits, extraction confidence, and eval outcome are logged without raw PHI. |
| Deployed app | Public URL and setup docs | Grader can run Week 2 flow without guessing setup. |
| Demo video | 3-5 minute recording | Video shows end-to-end Week 2 source-grounded flow. |
| Cost/latency report | Report artifact | Report includes dev spend, projected production cost, p50/p95 latency, and bottleneck analysis. |

## 13. Failure Conditions

The Week 2 build does not pass if any of the following are true:

- The eval gate does not block a meaningful regression.
- Fewer than 50 eval cases exist.
- Rubrics are vague ratings instead of boolean checks.
- A final clinical claim lacks citation metadata.
- The answer does not separate patient-record facts from guideline evidence.
- VLM output is trusted without strict schema validation.
- Uploaded documents are not stored in OpenEMR.
- Derived observations or records are duplicate or untraceable.
- Supervisor routing decisions cannot be inspected.
- Raw PHI appears in prompts, traces, screenshots, or logs used for observability.
- Graders cannot run the core Week 2 flow from documented setup instructions.
- The demo depends on unsupported manual steps not documented in the repository.

## 14. Recommended Implementation Order

1. Document the architecture defense: schemas, RAG design, eval design, security risks, and tradeoffs.
2. Implement `attach_and_extract` for `lab_pdf`.
3. Implement `attach_and_extract` for `intake_form`.
4. Add strict schema validation and validation tests.
5. Persist source documents and derived facts with citation links.
6. Build the small guideline corpus and hybrid retrieval index.
7. Add reranking and grounded evidence output.
8. Add supervisor plus `intake-extractor` and `evidence-retriever` workers.
9. Add handoff logging and observability metadata.
10. Build the 50-case eval golden set.
11. Wire the eval suite into PR-blocking CI or Git Hook.
12. Integrate the Week 2 flow into the deployed app.
13. Record demo video.
14. Finalize cost and latency report.
15. Run final acceptance matrix and fix any failed core requirement before submission.

## 15. Common Pitfalls To Avoid

- Supporting more than two document types before the required two work reliably.
- Using a VLM answer directly without schema validation.
- Extracting facts without source metadata.
- Letting the supervisor act as a black box.
- Using vague LLM judge ratings instead of boolean rubrics.
- Logging raw document text, patient identifiers, screenshots, prompts, or extracted PHI.
- Treating guideline retrieval and patient-record facts as interchangeable evidence.
- Building an impressive demo that cannot catch regressions.

## 16. Definition Of Done

Week 2 is done only when all of the following are true:

- The deployed app supports the lab PDF and intake form flow.
- The agent returns source-grounded answers with machine-readable citations.
- The supervisor and workers are inspectable.
- The 50-case eval suite passes.
- The CI or Git Hook gate blocks meaningful regressions.
- OpenEMR/FHIR records remain traceable and non-duplicative.
- Logs and traces do not expose raw PHI.
- The README, `W2_ARCHITECTURE.md`, schemas, eval artifacts, demo video, deployed URL, and cost/latency report are complete.
- A reviewer can understand, run, inspect, and defend the Week 2 behavior without relying on private explanation.

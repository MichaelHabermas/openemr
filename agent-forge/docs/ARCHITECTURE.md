# Architecture - Bare Bones

## Summary

This architecture exists for one deadline: the 24-hour Architecture Defense for the Clinical Co-Pilot. The goal is not to design the final hospital system. The goal is to define the smallest defensible agent that can be built inside OpenEMR without pretending trust exists where it does not.

The first version serves one user: a primary care physician opening a scheduled outpatient chart before walking into the room. The agent's job is chart orientation. It answers only the questions documented in `USERS.md`: who the patient is, why they are here, what changed since the last visit, what chart facts matter now, and focused follow-up questions about the same patient chart. It does not diagnose. It does not recommend treatment. It does not change medications. It does not draft notes. It does not answer open-ended medical questions.

The agent lives inside the OpenEMR patient chart. A small browser panel sends the physician's question and the current `patient_id` to a server-side OpenEMR endpoint. The server, not the browser, owns trust decisions. The endpoint binds the request to the active OpenEMR session user, the current patient, and the current chart context. If the user is missing, the patient is missing, or patient-specific authorization is unclear, the request is refused.

The audit shows the critical constraint: OpenEMR's existing ACL checks are capability-oriented, not patient-resource-oriented. That means coarse permission to read patient data is not enough. The agent needs an explicit patient authorization gate before any chart data is read. The model never gets direct database access. It can only use allowlisted, read-only chart tools controlled by the server.

Those tools fetch narrow evidence from the current patient's chart: demographics, active problems, active medications or prescriptions, recent labs, recent encounters or notes, and the last plan when available. Each returned fact carries source metadata: source type, source table, source row id, source date, display label, and value. Missing data is treated as missing data, not as proof that something is false.

The LLM is not trusted. It receives only the evidence bundle and must produce a structured draft answer from that evidence. A deterministic verifier then checks that every patient-specific claim maps back to source rows. Unsupported claims are removed or replaced with "not found in the chart." If the verifier cannot safely map the answer to evidence, the response is blocked.

The system favors trust over completeness. A slower complete answer is less useful than a fast, cited, bounded answer that says what it could and could not check. Every request is logged with request metadata, tool calls, failures, latency, token use, estimated cost, verifier result, and source row ids. Logs should avoid raw PHI where possible.

The first-principles rule is simple: read narrowly, cite everything, log every read, fail closed, and do not build anything that is not required for the documented physician workflow in `USERS.md`, constrained by `AUDIT.md`.

## Traceability To Users And Audit

### Capability -> User Use Case Mapping (`USERS.md`)

| Agent capability | User source | Why it exists |
| --- | --- | --- |
| Visit briefing summary | Use Case 1 - Visit Briefing | The physician needs fast chart orientation before entering the room. |
| Multi-turn follow-up drill-down | Use Case 2 - Follow-Up Drill-Down | The next useful question depends on what the first answer reveals. |
| Missing or unclear data reporting | Use Case 3 - Missing Or Unclear Data | Incomplete records must be surfaced explicitly instead of inferred away. |
| Unsupported request refusal | Use Case 1 boundary, Use Case 2 boundary, Use Case 3 boundary, Non-Goals | The agent must remain a chart-orientation aid, not a diagnosis or treatment engine. |
| Patient demographics tool | Workflow questions 1 and 4; Use Case 1 | The physician needs to know who the current patient is and what basic chart facts matter. |
| Active problems tool | Use Case 1; Use Case 2 | Problems are part of the visit briefing and common follow-up context. |
| Active medications and prescriptions tool | Use Case 1; Use Case 2 | Current medications are explicitly part of the briefing and follow-up examples. |
| Recent labs tool | Use Case 1; Use Case 2 | Recent labs support "what changed" and the A1c-trend example. |
| Recent encounters and notes tool | Use Case 1; Use Case 2 | The last note and recent encounters support last-plan and change-since-last-visit questions. |
| Last plan extraction | Use Case 1; Use Case 2 | The last plan is named in the briefing need and follow-up examples. |
| Source-cited answer display | Use Case 2 boundary; Success Standard | Every factual answer must be traceable to the patient's chart. |
| Structured draft plus deterministic verifier | Use Case 2 boundary; Use Case 3 boundary; Success Standard | Draft text is useful only if unsupported chart claims are blocked before display. |
| Agent request log | Success Standard; Use Case 3 boundary | Trust requires explaining what was checked, what failed, and which source rows supported the answer. |
| Public request and response contracts | Workflow; Use Case 2 | The interface must support a current-chart question, citations, missing sections, and warnings. |
| Evaluation cases | Success Standard; Non-Goals | The project needs proof that cited answers, missing-data behavior, refusals, and authorization failures behave as designed. |

### Trust Boundary -> Audit Finding Mapping (`AUDIT.md`)

| Boundary or constraint | Audit source | Required design response |
| --- | --- | --- |
| Patient-specific authorization gate | Security S1 | Coarse ACL is not enough; no chart data is read until current-user/current-patient access is resolved. Epic 4's demo gate is intentionally narrow; see `EPIC4-AGENT-REQUEST-SHELL.md` for included and deferred relationship shapes. |
| Session-bound identity binding | Security S2 | The OpenEMR server endpoint binds each request to the active session user before agent handling. |
| Browser treated as untrusted surface | Security S3 | The browser only sends input and displays output; it does not hold model credentials or make access decisions. |
| Narrow OpenEMR integration | Architecture A1 and A2 | The first version uses a small chart-embedded endpoint instead of broad OpenEMR rewrites. |
| Bounded patient reads | Performance P1 and P2 | Tools read only the current patient's required rows and latency remains a measured implementation concern. |
| Source-carrying evidence bundle | Data Quality D1, D2, D3, D4, D5 | Every returned fact carries source metadata; missing or weakly coded data is not treated as clean truth. |
| Medication evidence caution | Data Quality D3 and D4 | Medication answers must define which medication sources were checked and avoid unsupported coded-rule claims. |
| Missing-data behavior | Data Quality D1 and D5 | Empty or absent fields produce "not found in the chart" rather than negative clinical conclusions. |
| Deterministic verification | Security S1; Data Quality D1-D5 | The model does not grade itself; unsupported patient-specific claims are blocked or rewritten as missing. |
| Agent-specific logging | Compliance C1 and C2 | Agent reads, source ids, failures, verifier result, latency, tokens, and cost are logged even if OpenEMR query audit is disabled. |
| PHI minimization in prompts and logs | Compliance C1 and C3; Security S3 | The LLM receives only the minimum evidence bundle; logs avoid full prompts, full chart text, and raw PHI unless explicitly justified. |
| Failure and timeout handling | `SPECS.txt` Failure Modes; Performance P2 | Tool failure, malformed model output, verification failure, and timeout return visible failures or cited partial results only. |

## First Principles

Hard constraints:

- Patient-specific claims can harm patients if wrong.
- PHI access must be bounded, auditable, and deliberate.
- The physician has seconds, not minutes.
- The first version must be defensible under the deadline.

Delete for v1:

- No diagnosis.
- No treatment recommendations.
- No medication changes.
- No dosing advice.
- No autonomous actions.
- No note drafting.
- No vector database.
- No multi-agent system.
- No background worker.
- No broad chart search.
- No PHI copied into new long-term storage.
- No free-form SQL generated by the model.

What survives:

- A read-only conversational chart assistant.
- Current patient only.
- Server-controlled tools only.
- Source-cited answers only.
- Explicit refusal when trust conditions fail.

## Architecture

Flow:

1. Physician opens a patient chart in OpenEMR.
2. Agent panel sends `patient_id` and `question` to a server-side OpenEMR endpoint.
3. OpenEMR validates the active session user.
4. A patient-specific authorization gate checks whether that user may read that patient chart.
5. Tool router selects allowlisted read-only chart tools.
6. Tools fetch bounded rows for the current patient only.
7. Evidence bundle is built with source table, row id, date, label, and value.
8. LLM receives the question and evidence bundle only.
9. LLM returns a structured draft answer.
10. Verifier checks every patient-specific claim against the evidence bundle.
11. Final answer displays citations or says what was not found.
12. Agent audit log records request metadata, tool calls, failures, latency, token use, cost estimate, verifier result, and source ids.

The browser is only a display and input surface. It does not decide access. It does not hold long-lived PHI. It does not hold model credentials.

## Trust Boundaries

- The browser is not trusted.
- The LLM is not trusted.
- Existing coarse ACL is not enough for patient-specific access.
- Missing data is not negative evidence.
- Model output is draft text until verified.
- Logs are sensitive and must avoid raw PHI where possible.

If any boundary is unclear, the agent refuses or degrades visibly.

Epic 4 proves only a narrow patient-specific authorization gate: `patient_data.providerID`, `form_encounter.provider_id`, and `form_encounter.supervisor_id`. Care-team membership, facility-scoped access, group-based patient assignment, scheduling-based access, and broader delegation rules are deferred and fail closed until explicitly designed.

## Data Access

Start with read-only tools:

- Patient demographics.
- Active problems.
- Active medications and prescriptions.
- Recent labs.
- Recent encounters and notes.
- Last plan when available.

Tool rules:

- Every tool is bounded by one `patient_id`.
- Every query is server-defined and parameterized.
- Every returned fact includes source metadata.
- The model cannot request arbitrary SQL.
- The model cannot access patients outside the active chart.
- Broad search and panel-level precomputation are deferred.

Minimum tool result shape:

```json
{
  "source_type": "lab",
  "source_table": "unknown_until_implemented",
  "source_id": "row id",
  "source_date": "record date",
  "display_label": "human-readable label",
  "value": "source value"
}
```

The exact OpenEMR tables are implementation details. The contract is source-carrying evidence, not clean clinical truth.

## Verification

Verification happens after model drafting and before user display.

Rules:

- Every patient-specific claim must cite source rows.
- Unsupported claims are removed or replaced with "not found in the chart."
- If claims cannot be mapped to evidence, the response is blocked.
- The verifier refuses diagnosis, treatment advice, dosing advice, medication-change recommendations, and unsupported clinical rule claims.
- If data is incomplete, the answer says what was checked and what was not found.

The verifier is deterministic code. The model may help write a draft, but it does not grade its own truth.

## Failure Modes

- No session user: refuse.
- No patient id: refuse.
- Patient authorization unclear: refuse.
- Tool failure: report which chart area could not be checked.
- Missing record: say "not found in the chart."
- LLM malformed output: retry once, then fail clearly.
- Verification failure: block the answer.
- Timeout: return partial verified findings only if citations are intact; otherwise fail clearly.

Silent failure is not allowed.

## Observability

Log enough to answer:

- What request happened?
- Who asked?
- Which patient chart was involved?
- Which tools ran?
- Which source rows were used?
- How long did each step take?
- Did anything fail?
- Which model was used?
- How many tokens were used?
- What was the estimated cost?
- Did verification pass or fail?

Minimum log fields:

- `request_id`
- `user_id`
- `patient_id`
- `timestamp`
- `question_type`
- `tools_called`
- `source_ids`
- `latency_ms`
- `model`
- `input_tokens`
- `output_tokens`
- `estimated_cost`
- `failure_reason`
- `verifier_result`

Do not log full prompts, full chart text, or raw PHI unless there is a specific audited reason.

## Public Interfaces

Agent request:

```json
{
  "patient_id": "current OpenEMR patient id",
  "question": "physician question",
  "session_user": "active OpenEMR session user",
  "conversation_id": "optional"
}
```

Tool result:

```json
{
  "source_type": "problem | medication | lab | encounter | note | demographic",
  "source_table": "OpenEMR table name",
  "source_id": "row id",
  "source_date": "date tied to source row",
  "display_label": "short label",
  "value": "source value"
}
```

Agent response:

```json
{
  "answer": "verified answer text",
  "citations": ["source references used by the answer"],
  "missing_or_unchecked_sections": ["chart areas not found or not checked"],
  "refusals_or_warnings": ["safety or authorization messages"]
}
```

These shapes are intentionally high-level. They define the boundary. They are not final implementation schemas.

## Evaluation

Minimum eval set:

- Visit briefing returns only supported chart facts with citations.
- Medication question returns active medication facts with citations.
- Lab trend question returns cited lab values and dates.
- Missing data returns "not found in the chart."
- Unauthorized patient request is blocked.
- Unsupported diagnosis or treatment recommendation is refused.
- Tool failure is disclosed in the answer.
- Hallucinated claim is blocked by the verifier.

Pass condition:

- No unsupported patient-specific claim reaches the physician.
- Every factual claim has a citation.
- Every refusal is clear.
- Every failure is visible.

## Tradeoffs

- Favor trust over completeness.
- Favor narrow reads over broad search.
- Favor deterministic verification over model self-judgment.
- Favor boring OpenEMR integration over a separate agent platform.
- Favor explicit "not found" over inferred answers.
- Favor demo-data safety over realistic PHI handling claims.

The architecture may feel small. That is the point. If this version cannot be trusted on one patient chart, it has no business scaling to more.

## Assumptions

- Architecture Defense is the immediate priority.
- The first version serves only the primary care physician workflow in `USERS.md`.
- Only demo data is used.
- The deployment remains based on the existing Docker Compose setup documented in `KNOWN-FACTS-AND-NEEDS.md`.
- `AUDIT.md` is treated as the current source of security and data-quality constraints.
- The agent must be read-only, cited, logged, narrow, and fail-closed.

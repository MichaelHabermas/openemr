# Architecture - Bare Bones

## Summary

This architecture exists for one deadline: the 24-hour Architecture Defense for the Clinical Co-Pilot. The goal is not to design the final hospital system. The goal is to define the smallest defensible agent that can be built inside OpenEMR without pretending trust exists where it does not.

The target user is one primary care physician opening a scheduled outpatient chart before walking into the room. The agent's job is chart orientation. The desired product answers only the questions documented in `USERS.md`: who the patient is, why they are here, what changed since the last visit, what chart facts matter now, and focused follow-up questions about the same patient chart. The current implemented path is narrower: it is a single-shot constrained RAG request path, not a persistent multi-turn conversation. It does not diagnose. It does not recommend treatment. It does not change medications. It does not draft notes. It does not answer open-ended medical questions.

The agent lives inside the OpenEMR patient chart. A small browser panel sends the physician's question and the current `patient_id` to a server-side OpenEMR endpoint. The server, not the browser, owns trust decisions. The endpoint binds the request to the active OpenEMR session user, the current patient, and the current chart context. If the user is missing, the patient is missing, or patient-specific authorization is unclear, the request is refused.

The audit shows the critical constraint: OpenEMR's existing ACL checks are capability-oriented, not patient-resource-oriented. That means coarse permission to read patient data is not enough. The agent needs an explicit patient authorization gate before any chart data is read. The model never gets direct database access. It can only use allowlisted, read-only chart tools controlled by the server.

Those tools fetch narrow evidence from the current patient's chart: demographics, active problems, active prescriptions, recent labs, recent encounters or notes, and the last plan when available. Each returned fact carries source metadata: source type, source table, source row id, source date, display label, and value. Medication evidence remains incomplete for production because OpenEMR medication data also appears in `lists` and `lists_medication`; Epic 13 plans that remediation. Missing data is treated as missing data, not as proof that something is false.

The LLM is not trusted. It receives only the evidence bundle and must produce a structured draft answer from that evidence. A deterministic verifier then checks patient-specific claims against source rows. The current verifier is useful but not final: instructor review identified over-trust in model-supplied claim types and brittle substring matching. Epic 12 plans the hardening needed before production-readiness claims.

The system favors trust over completeness. A slower complete answer is less useful than a fast, cited, bounded answer that says what it could and could not check. Every request is logged with request metadata, tool calls, failures, total latency, token use, estimated cost, verifier result, and source row ids. These are PHI-minimized sensitive audit logs, not PHI-free logs, because they include user, patient, and source identifiers. Per-step timing, aggregation, SLOs, and alerting are planned in Epic 14.

The first-principles rule is simple: read narrowly, cite everything, log every read, fail closed, and do not build anything that is not required for the documented physician workflow in `USERS.md`, constrained by `AUDIT.md`. Current docs must not claim production readiness until the remediation epics in `PLAN.md` are completed or explicitly scoped out.

## Current Status And Remediation Roadmap

### Already Implemented Foundation

- Embedded OpenEMR chart panel and server-side endpoint for the active chart.
- Narrow fail-closed authorization gate for session user, active patient, coarse ACL, patient existence, and direct provider/encounter/supervisor relationships.
- Bounded read-only evidence tools for the demo path with source-carrying evidence.
- Server-side OpenAI draft provider using structured output, plus deterministic verification and refusal behavior.
- Structured request logging with request id, user id, patient id, total latency, question type, tools called, source ids, model, token counts, estimated cost, failure reason, and verifier result.
- Fake demo patient data, fixture evals, local/VM manual proof, deployment and rollback documentation.

### Accepted V1 Limitations

- The current UI and request model are single-turn. They do not preserve transcript, `conversation_id`, turn history, or follow-up grounding.
- The response payload includes citations and the chart panel renders those citation strings visibly outside answer prose. Citation display is a v1 surfacing fix, not proof of multi-turn state.
- The handler may call more evidence tools than the question requires; selective PHI-minimizing routing is planned.
- Medication evidence currently relies on active prescriptions for the demo path and does not cover all OpenEMR medication table shapes.
- Authorization intentionally fails closed outside direct provider/encounter/supervisor relationships; care-team, facility, schedule, and delegation access are deferred.
- Observability is structured logging, not full observability. Per-step timing, aggregation, dashboards, SLOs, and alerts are not yet present.
- Fixture evals prove deterministic orchestration and verifier behavior, but not the full live LLM, SQL, browser, deployed endpoint, or real session path.
- The VM A1c request baseline is about 10.693 seconds, which requires a documented latency budget and optimization plan before production-readiness claims.

### Planned Remediation Before Production Readiness

- Epic 8: reviewer packaging and root artifact map.
- Epic 9: cost analysis rewrite at 100 / 1K / 10K / 100K users.
- Epic 10: live-path eval tiers and evaluation honesty.
- Epic 11: conversation scope correction, minimum multi-turn design, and citation UI surfacing.
- Epic 12: verifier hardening and PHI-minimizing tool routing.
- Epic 13: medication completeness, authorization expansion, and data/index remediation.
- Epic 14: sensitive audit-log policy, per-step observability, SLOs, alerting, and latency budget.

### Production-Readiness Blockers

- Required reviewer artifacts must be findable from the repository root.
- Cost analysis must address user tiers, non-token costs, support assumptions, and architecture changes.
- Evaluation must include live provider, live SQL, browser UI, deployed endpoint, and real session proof.
- Multi-turn support must either be implemented safely or explicitly scoped out against the spec.
- Citations must be visible in the physician UI.
- Verification must not trust model-supplied claim labels for factuality.
- Logs must have retention/access controls appropriate for PHI-minimized sensitive audit data.
- Medication evidence, authorization scope, indexing, and latency must be remediated or disclosed as blockers.

## Traceability To Users And Audit

### Capability -> User Use Case Mapping (`USERS.md`)

| Agent capability | User source | Why it exists |
| --- | --- | --- |
| Visit briefing summary | Use Case 1 - Visit Briefing | The physician needs fast chart orientation before entering the room. |
| Multi-turn follow-up drill-down | Use Case 2 - Follow-Up Drill-Down | Target capability; current v1 is single-shot until Epic 11 adds conversation state. |
| Missing or unclear data reporting | Use Case 3 - Missing Or Unclear Data | Incomplete records must be surfaced explicitly instead of inferred away. |
| Unsupported request refusal | Use Case 1 boundary, Use Case 2 boundary, Use Case 3 boundary, Non-Goals | The agent must remain a chart-orientation aid, not a diagnosis or treatment engine. |
| Patient demographics tool | Workflow questions 1 and 4; Use Case 1 | The physician needs to know who the current patient is and what basic chart facts matter. |
| Active problems tool | Use Case 1; Use Case 2 | Problems are part of the visit briefing and common follow-up context. |
| Active medications and prescriptions tool | Use Case 1; Use Case 2 | Current medications are explicitly part of the briefing and follow-up examples. |
| Recent labs tool | Use Case 1; Use Case 2 | Recent labs support "what changed" and the A1c-trend example. |
| Recent encounters and notes tool | Use Case 1; Use Case 2 | The last note and recent encounters support last-plan and change-since-last-visit questions. |
| Last plan extraction | Use Case 1; Use Case 2 | The last plan is named in the briefing need and follow-up examples. |
| Source-cited answer display | Use Case 2 boundary; Success Standard | Every factual answer must be traceable to the patient's chart; citation UI surfacing is planned in Epic 11. |
| Structured draft plus deterministic verifier | Use Case 2 boundary; Use Case 3 boundary; Success Standard | Draft text is useful only if unsupported chart claims are blocked before display. |
| Agent request log | Success Standard; Use Case 3 boundary | Trust requires explaining what was checked, what failed, and which source rows supported the answer. |
| Public request and response contracts | Workflow; Use Case 2 | The interface must support a current-chart question, citations, missing sections, and warnings. |
| Evaluation cases | Success Standard; Non-Goals | The project needs proof that cited answers, missing-data behavior, refusals, and authorization failures behave as designed. |

### Trust Boundary -> Audit Finding Mapping (`AUDIT.md`)

| Boundary or constraint | Audit source | Required design response |
| --- | --- | --- |
| Patient-specific authorization gate | Security S1 | Coarse ACL is not enough; no chart data is read until current-user/current-patient access is resolved. Epic 4's demo gate is intentionally narrow; see `epics/EPIC4-AGENT-REQUEST-SHELL.md` for included and deferred relationship shapes. |
| Session-bound identity binding | Security S2 | The OpenEMR server endpoint binds each request to the active session user before agent handling. |
| Browser treated as untrusted surface | Security S3 | The browser only sends input and displays output; it does not hold model credentials or make access decisions. |
| Narrow OpenEMR integration | Architecture A1 and A2 | The first version uses a small chart-embedded endpoint instead of broad OpenEMR rewrites. |
| Bounded patient reads | Performance P1 and P2 | Tools read only the current patient's required rows and latency remains a measured implementation concern. |
| Source-carrying evidence bundle | Data Quality D1, D2, D3, D4, D5 | Every returned fact carries source metadata; missing or weakly coded data is not treated as clean truth. |
| Medication evidence caution | Data Quality D3 and D4 | Current demo evidence checks active prescriptions; Epic 13 must cover `lists` and `lists_medication` before complete-medication claims. |
| Missing-data behavior | Data Quality D1 and D5 | Empty or absent fields produce "not found in the chart" rather than negative clinical conclusions. |
| Deterministic verification | Security S1; Data Quality D1-D5 | The model does not grade itself; unsupported patient-specific claims are blocked or rewritten as missing. |
| Agent-specific logging | Compliance C1 and C2 | Agent reads, source ids, failures, verifier result, total latency, tokens, and cost are logged even if OpenEMR query audit is disabled. These are sensitive audit logs. |
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

- A read-only chart-orientation assistant. It is single-shot today; persistent conversation is planned.
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
5. Server invokes allowlisted read-only chart tools. Selective routing is the target; current remediation work must reduce over-broad tool calls.
6. Tools fetch bounded rows for the current patient only.
7. Evidence bundle is built with source table, row id, date, label, and value.
8. LLM receives the question and evidence bundle only.
9. LLM returns a structured draft answer.
10. Verifier checks every patient-specific claim against the evidence bundle.
11. Final answer includes citations in the response payload and displays them visibly in the chart panel.
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
- Active prescriptions today; broader active medication evidence across `prescriptions`, `lists`, and `lists_medication` is planned.
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

- Every patient-specific factual sentence must cite source rows; Epic 12 hardens this so factuality does not depend on model-supplied claim type.
- Unsupported claims are removed or replaced with "not found in the chart."
- If claims cannot be mapped to evidence, the response is blocked.
- Current source matching is conservative and partly substring-based; Epic 12 plans stronger grounding and unsupported-tail protection.
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

Current local model configuration:

- Provider path: server-side OpenAI draft provider.
- Model: `gpt-4o-mini`.
- Credentials: server/container environment only; never browser-exposed.
- Structured output: JSON-schema draft with sentences, claims, missing sections, refusals/warnings, and source IDs.
- Pricing: see `operations/COST-ANALYSIS.md` for the exact source and measured local request cost.

Current structured logs answer most request-level questions. Target observability must answer:

- What request happened?
- Who asked?
- Which patient chart was involved?
- Which tools ran?
- Which source rows were used?
- How long did each step take? Current logs capture total latency; per-step timing is planned in Epic 14.
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

Do not log full prompts, full chart text, or raw PHI unless there is a specific audited reason. Treat `user_id`, `patient_id`, and `source_ids` as sensitive audit metadata with retention and access controls.

## Public Interfaces

Agent request:

```json
{
  "patient_id": "current OpenEMR patient id",
  "question": "physician question",
  "session_user": "active OpenEMR session user",
  "conversation_id": "planned; not implemented in the current single-shot path"
}
```

### Planned Minimum Multi-Turn Contract

This contract is planned only. The current runtime API remains single-shot and does not accept or return a live `conversation_id`.

- First turn: the server issues a `conversation_id` after session user, active patient, authorization, evidence, and verification succeed.
- Follow-up turns: the browser sends the server-owned `conversation_id`; the server rejects the request if the conversation is expired, missing, owned by another user, or bound to another patient.
- Scope: every conversation is bound to exactly one OpenEMR session user and one active patient. Cross-patient carryover is refused before chart tools run.
- State: store turn metadata, cited source ids, missing/unchecked sections, and a compact server-side summary. Do not store full chart dumps, model prompts, browser-owned transcript state, or raw full-chart text.
- Safety: prior answer text may help interpret the follow-up, but it is never evidence. Each factual follow-up answer must fetch current patient evidence and cite current source rows again.
- Limits: enforce a small turn limit, short expiration, explicit PHI retention policy, and a deletion path before enabling stored turn state.
- Evals before implementation: same-patient follow-up succeeds with fresh citations, cross-patient `conversation_id` reuse fails closed, expired conversations fail closed, and stale-context pressure cannot produce uncited claims.

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

Minimum target eval set:

- Visit briefing returns only supported chart facts with citations.
- Medication question returns active medication facts with citations.
- Lab trend question returns cited lab values and dates.
- Missing data returns "not found in the chart."
- Unauthorized patient request is blocked.
- Unsupported diagnosis or treatment recommendation is refused.
- Tool failure is disclosed in the answer.
- Hallucinated claim is blocked by the verifier.

Current evaluation status: the fixture suite is valuable deterministic proof for orchestration and verifier behavior, but it is not a full live-agent evaluation. Epic 10 defines the proof boundary and live-path gates in `evaluation/EVALUATION-TIERS.md`: Tier 0 is implemented fixture/orchestration proof, while seeded SQL, live model, local browser/session, and deployed browser/session tiers require captured results or explicit documented gaps before live-agent evaluation can be claimed.

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
- The deployment remains based on the existing Docker Compose setup documented in `operations/KNOWN-FACTS-AND-NEEDS.md`.
- `AUDIT.md` is treated as the current source of security and data-quality constraints.
- The agent must be read-only, cited, logged, narrow, and fail-closed.

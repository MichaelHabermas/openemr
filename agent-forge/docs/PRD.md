# AgentForge Clinical Co-Pilot PRD

## 1. Executive Summary

**Problem Statement:** `SPECS.txt` requires a trustworthy AI agent inside OpenEMR for a physician who has about 90 seconds to understand a patient chart before a visit. The hard problem is not generating text; it is producing fast, patient-specific answers that are authorized, source-grounded, auditable, and safe under failure.

**Proposed Solution:** Build the smallest defensible Clinical Co-Pilot: a read-only chart-orientation agent embedded in the OpenEMR patient chart for one user, a primary care physician preparing for a scheduled outpatient visit. The target product supports safe multi-turn follow-up, but the current implemented path is single-shot constrained RAG with no persistent conversation state. The agent reads only the active patient's chart through server-controlled tools, verifies patient-specific claims against source rows, logs every request, and refuses when identity, authorization, evidence, or safety constraints are unclear.

**Success Criteria:**

- `AUDIT.md` exists and begins with a one-page summary of the highest-impact security, performance, architecture, data-quality, and compliance findings required by `SPECS.txt`.
- `USERS.md` exists and defines one target user, the user's workflow, concrete use cases, and why each use case requires an agent instead of a static dashboard.
- `ARCHITECTURE.md` exists and begins with a one-page summary covering integration point, data access, authorization boundaries, verification, observability, evaluation, failure modes, and tradeoffs.
- The deployed OpenEMR URL remains publicly reachable at `https://openemr.titleredacted.cc/`, and the readiness endpoint is checked when possible at `/meta/health/readyz`.
- No patient-specific answer reaches the physician unless every factual claim is supported by cited chart evidence or explicitly reported as not found.

## 2. User Experience & Functionality

### User Personas

Primary user: a primary care physician seeing scheduled outpatient visits.

The physician opens the patient's chart immediately before entering the room and needs fast orientation to who the patient is, why they are here, what changed since the last visit, and what facts matter now. This is the only user for v1.

### User Stories

**Story 1 - Visit Briefing:** As a primary care physician, I want a short briefing when I open a patient chart so that I can enter the visit with the current chart context.

Acceptance criteria:

- The briefing is limited to the active patient chart.
- The briefing covers reason for visit, last plan, active problems, current medications or prescriptions, recent labs, and notable changes since the last visit when those facts are present.
- Each patient-specific fact cites source metadata from the chart.
- Missing information is labeled as not found rather than inferred.
- The answer contains no diagnosis, treatment recommendation, dosing advice, medication-change recommendation, or note drafting.

**Story 2 - Follow-Up Drill-Down:** As a primary care physician, I want to ask focused follow-up questions about the same patient so that I can narrow into medications, labs, recent notes, or changes without manually scanning every chart section.

Acceptance criteria:

- The target agent supports multi-turn follow-up within the same patient context.
- Current v1 treats each question as an independent single-shot request until `conversation_id`, server-side turn state, transcript display, retention policy, and follow-up evals are implemented.
- Current v1 renders structured citations visibly in the chart panel, but that citation surfacing is not conversation memory.
- Each follow-up query is bound to the active OpenEMR session user and active patient.
- The agent can answer supported questions such as recent A1c trend, active medications, last plan, and changes since last visit.
- The model cannot call arbitrary SQL or access patients outside the active chart.
- Unsupported or ambiguous requests are refused or narrowed visibly.

**Story 3 - Missing Or Unclear Data:** As a primary care physician, I want the agent to state what it checked and what it could not determine so that I do not mistake missing chart data for a clinical fact.

Acceptance criteria:

- Tool failures identify the chart area that could not be checked.
- Missing records are reported as not found in the chart.
- Incomplete records do not produce inferred conclusions.
- Verification failure blocks the answer before display.
- Timeout returns only partial verified findings with intact citations, or fails clearly.

### Non-Goals

- No diagnosis.
- No treatment recommendation.
- No medication changes.
- No dosing advice.
- No autonomous clinical action.
- No note drafting.
- No patient-facing advice.
- No open-ended medical knowledge chatbot.
- No support for users beyond the chosen primary care physician in v1.
- No vector database unless later evidence proves the bounded chart tools cannot satisfy the documented use cases.
- No broad chart search, panel-wide precomputation, background worker, multi-agent system, or model-generated SQL for v1.

## 3. AI System Requirements

### Tool Requirements

The agent needs only read-only, server-controlled tools for the active patient:

- Patient demographics.
- Active problems.
- Active medications and prescriptions.
- Active allergies.
- Recent labs.
- Recent vitals.
- Recent encounters and notes.
- Last plan when available.

Every tool must:

- Accept one server-validated patient identifier.
- Use allowlisted, parameterized data access.
- Return source metadata with each fact: source type, source table, source row id, source date, display label, and value.
- Return explicit missing or failed states.
- Avoid sending unnecessary PHI to the model.

### Verification Requirements

The LLM is not trusted. Its output is draft text until deterministic verification passes.

Verification must enforce:

- Every patient-specific claim maps to one or more source rows.
- Unsupported claims are removed, replaced with not found, or blocked.
- Diagnosis, treatment advice, dosing advice, medication-change recommendations, unsupported clinical rule claims, and patient-specific speculation are refused.
- Malformed model output is retried once, then failed clearly.
- The final response includes citations, missing or unchecked sections, and refusals or warnings.

### Evaluation Strategy

The eval suite must prove the agent fails safely, not only that it can demo well.

Current status: the existing fixture eval suite is valuable deterministic proof for verifier and orchestration behavior, but it does not fully exercise the real LLM, live SQL evidence path, browser UI, deployed endpoint, or real session behavior. Fixture/orchestration proof is the current repeatable tier; live SQL, live model, browser, and deployed session tiers require captured results or explicit documented gaps before live-agent evaluation can be claimed.

Minimum eval cases:

- Visit briefing returns only supported chart facts with citations.
- Medication question returns active medication facts with citations.
- Allergy question returns active allergy facts with citations and does not promote inactive allergy rows.
- Lab trend question returns cited lab values and dates.
- Vital-sign question returns bounded recent vitals with citations and reports missing or stale-only vitals distinctly.
- Missing data returns not found in the chart.
- Unauthorized patient request is blocked.
- Unsupported diagnosis or treatment recommendation is refused.
- Tool failure is disclosed.
- Hallucinated patient-specific claim is blocked by the verifier.

Pass condition: zero unsupported patient-specific claims reach the physician.

## 4. Technical Specifications

### Architecture Overview

The agent lives inside the OpenEMR patient chart. The browser panel is only input and display. The server owns identity, authorization, data access, verification, logging, and model credentials.

Request flow:

1. Physician opens a patient chart in OpenEMR.
2. Browser panel sends the current patient id and physician question to a server-side OpenEMR endpoint.
3. Server binds the request to the active OpenEMR session user.
4. Server performs a patient-specific authorization gate before reading chart data.
5. Server invokes only allowlisted read-only chart tools.
6. Tools return a bounded evidence bundle with source metadata.
7. LLM receives only the question and evidence bundle.
8. LLM returns structured draft output.
9. Deterministic verifier checks claims against evidence and safety constraints.
10. Server returns verified answer, citations, missing sections, and warnings.
11. Agent audit log records request metadata, tool calls, source ids, failures, latency, token use, cost estimate, model, and verifier result.

### Integration Points

Known integration constraints:

- OpenEMR already runs through Docker Compose using `docker/development-easy/docker-compose.yml`.
- Deployment target is a Linux VM at `~/repos/openemr`.
- Public app URL is `https://openemr.titleredacted.cc/`.
- The OpenEMR compose stack contains `openemr` and `mysql` services.
- The OpenEMR service exposes HTTP and HTTPS via `WT_HTTP_PORT` and `WT_HTTPS_PORT`, defaulting to `80` and `443`.
- The container readiness endpoint is `https://localhost/meta/health/readyz`; public readiness should be checked at `https://openemr.titleredacted.cc/meta/health/readyz` when available.

Open implementation facts that must be verified on the VM before finalizing deploy automation:

- Active deployment branch.
- Git remote name.
- Whether the VM uses `docker compose` or `docker-compose`.
- Whether the deploy user has passwordless Docker access.
- Whether TLS is terminated by OpenEMR, a reverse proxy, or VM infrastructure.
- Whether deployment environment variables are set.
- Whether Docker volumes must be preserved.
- How sample data will be created, loaded, and reset.
- Which fake patients and clinical facts are needed for the demo and evals.

### Security & Privacy

Hard constraints:

- Use demo data only.
- Treat PHI as protected even in project design.
- Bind every request to an OpenEMR user and patient.
- Do not rely on coarse ACL as patient-specific authorization.
- Refuse if user identity, patient identity, or patient-specific authorization is unclear.
- Do not expose model credentials to the browser.
- Do not store PHI or bearer tokens in long-lived browser storage.
- Do not log full prompts, full chart text, or raw PHI unless an explicit audited need exists.
- Log source row ids, tool names, verifier result, failure reason, latency, token use, and estimated cost.

### Required Project Deliverables

To complete `SPECS.txt`, the project must contain:

- Forked OpenEMR repository with setup guide, architecture overview, and deployed link.
- Public deployed application URL for every submission.
- `AUDIT.md` with required audit categories and one-page summary.
- `USERS.md` defining target user, workflow, use cases, and why an agent is right for each use case.
- `ARCHITECTURE.md` with one-page summary and agent integration plan.
- Working live agent for early and final submissions.
- Eval dataset and results.
- AI cost analysis covering actual dev spend and projected production costs at 100, 1K, 10K, and 100K users, including architecture changes at each scale.
- Three-to-five-minute demo video for each submission.
- Final social post on X or LinkedIn tagging `@GauntletAI`.

## 5. Risks & Roadmap

### Phased Rollout

**Architecture Defense within 24 hours:**

- Confirm OpenEMR runs locally or document current runnable state.
- Confirm public deployment URL and readiness endpoint.
- Convert bare-bones audit into required `AUDIT.md`.
- Confirm `USERS.md` defines the target user, workflow, use cases, and agent justification.
- Ensure `ARCHITECTURE.md` traces every capability to `USERS.md` and every constraint to `AUDIT.md`.
- Add deploy script only if VM unknowns are verified enough to avoid unsafe volume or environment assumptions.
- Define fake sample patient data needed for the demo and eval suite.

**MVP / Tuesday submission:**

- App audit, agent plan, deployed app, and demo video.
- Implement the embedded chart panel and server endpoint.
- Implement read-only chart tools for the minimum evidence types.
- Implement deterministic verifier and refusal paths.
- Add request logging and token/cost tracking.
- Run first eval set and record results.

**Early submission:**

- Deployed agent works in the live environment.
- Eval framework is wired and repeatable.
- **Structured request logging** (and inspectable eval outputs) is in place and used for demo defense, including `stage_timings_ms` for evidence, draft, and verification stages. This is not full production observability: aggregation, dashboards, SLOs, alerts, and percentile queries remain unavailable.
- Cost analysis contains measured development spend and projected production scenarios.
- Demo video shows product behavior, architecture decisions, verification, failure handling, and structured logging (or other inspectable audit evidence).

**Final submission (target milestones for the program — not a claim that the repo is production-ready while known production-readiness blockers remain open):**

- **Target:** demo version of the narrow agent that meets submission gates; hospital-grade production readiness remains blocked until the documented production-readiness blockers are closed or explicitly scoped out.
- Complete repository docs and setup guide.
- Final eval results.
- Final cost analysis.
- Final demo video.
- Social post.

### Technical Risks

- Patient-specific authorization may require new code because audited OpenEMR ACL paths are capability-oriented, not patient-resource-oriented.
- Session-bound identity may not carry into any sidecar, worker, or external service unless passed deliberately.
- PHI read auditing is configurable, so agent-specific logging is required.
- Medication facts span more than one table shape and may contain optional coded fields.
- Current medication evidence checks active prescriptions, active medication-list entries, and linked `lists_medication` extension rows where available. It does not reconcile duplicate or conflicting medication rows into clinical truth.
- Missing, stale, duplicate, or weakly constrained chart data can cause unsafe inferred answers.
- Response latency has limited local/VM baseline measurements only; the local A1c path is `2,989 ms` and the VM A1c path is `10,693 ms`. The VM result is accepted for demo evidence only; production-readiness claims remain blocked until p95 latency is under the accepted budget and `stage_timings_ms` identifies bottlenecks.
- VM deployment details are not fully known, so deploy automation must avoid destructive assumptions.

### Critical Bottleneck

The bottleneck is not model quality. The bottleneck is the trust chain: session user -> patient-specific authorization -> bounded evidence -> deterministic verification -> audited response. If that chain is incomplete, the agent is not done.

### Kill Criteria

The agent is not shippable if any of these are true:

- It can answer about a patient without patient-specific authorization.
- It returns unsupported patient-specific claims.
- It gives diagnosis, treatment, dosing, medication-change, or unsupported clinical-rule advice.
- It hides tool failures or missing data.
- It cannot **surface** source citations for factual claims in a **reviewable** way in the chart panel from the structured response payload; citations must also remain available in the API response and structured logs / evals so every supported claim is traceable.
- It logs raw PHI unnecessarily.
- It cannot be demonstrated in the deployed environment.

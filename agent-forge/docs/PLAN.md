# AgentForge Clinical Co-Pilot Plan

## Purpose

This plan breaks `SPECS.txt` into small, test-first work items. The goal is to finish the required Clinical Co-Pilot deliverables without pretending that trust, deployment, data, authorization, or verification are solved before they are proven.

The plan is organized around gates of trust, not technology layers. A feature is not done because code exists. It is done only when an eval, automated test, or human-verifiable check proves the behavior against `SPECS.txt`.

## 48-Hour Critical Path

This is the execution plan. The longer epic list below is the backlog and defense map.

### P0 - First 2 Hours: Lock Decisions Or Stop

These are not implementation tasks. They are blockers that must be decided before meaningful code starts.

1. Runtime decision: choose the smallest integration path that can be deployed and defended. Current planned shape is PHP inside OpenEMR for session/chart context plus a narrow agent service only if the repo integration proves that PHP-native model/tool code is slower than a service boundary.
2. LLM decision: choose one provider/model and record model name, structured-output support, token usage fields, and pricing source. If pricing cannot be verified, cost fields must be marked unknown until measured.
3. Verification mechanism: use structured draft output with explicit claim objects and required source IDs. The verifier rejects any patient-specific claim when the cited source ID is absent from the evidence bundle or the claim text cannot be matched to source labels/values. No model grades its own truth.
4. PHI-to-LLM policy: send only the minimum evidence bundle required for the active question. Do not send full chart text, full prompts containing raw chart dumps, unrelated patient data, browser credentials, or OpenEMR session material.
5. Latency budget: record `latency_ms` for every request. The original V1 target is a verified answer or clear failure within 10 seconds for the demo path; the measured VM A1c path is about 10.693 seconds, so Epic 14 must define the accepted budget and optimization plan before production-readiness claims. Partial verified output is allowed only when citations remain intact.

Exit criteria:

- Each decision is written in `ARCHITECTURE.md` or a linked implementation note before dependent code begins.
- Any unresolved item is marked as a blocker, not carried as an invisible premise.

### P0 - Hours 2-8: Submission And Runtime Skeleton

Run these tracks in parallel if more than one worker is available.

Track A - submission files:

- Verify `AUDIT.md`, `USERS.md`, `ARCHITECTURE.md`, `PRD.md`, `PLAN.md`, and `COST-ANALYSIS.md` exist.
- Add or update release checklist.

Track B - deployment proof:

- Verify public app URL and readiness endpoint.
- Verify VM branch, remote, compose command, Docker permissions, TLS termination, environment variables, and volume safety.
- Record a **git rollback target** before taking the stack offline (e.g. pre-deploy commit printed by `deploy-vm.sh`, or explicit commit for `rollback-vm.sh`). This is **not** a database or Docker volume snapshot — recovery of demo DB state is by re-seed; see `EPIC2-DEPLOYMENT-RUNTIME-PROOF.md`.

Track C - agent skeleton:

- Locate patient chart UI entry point and server route pattern.
- Add panel -> endpoint -> fail-closed request path (originally a placeholder handler; current tree uses the verified agent pipeline after the same gate).
- Add request log contract before any model call.

Exit criteria:

- Deployed app health can be checked.
- An authorized request reaches the server and logs request id, user context result, patient context result, latency, and failure/pass status.
- No model call exists before logging and PHI policy are in place (sequencing requirement for the initial milestone; current code adds the model only after logging and gate).

### P0 - Hours 8-20: Evidence, Verification, And Smoke Test

Track A - demo data and evals:

- Define fake patient facts and expected answers.
- Add eval cases for happy path, missing data, hallucination, clinical advice refusal, unauthorized access, prompt injection, cross-patient leakage, and malicious chart text.

Track B - evidence tools:

- Implement the minimum tools needed for one demo patient: demographics, medications/prescriptions, labs, and last plan/recent note.
- Every evidence item must include source type, source table, source row id, source date, display label, and value.

Track C - verifier:

- Implement structured draft schema.
- Implement deterministic source-ID and source-value verifier.
- Refuse diagnosis, treatment, dosing, medication-change, and unsupported clinical-rule requests.

Exit criteria:

- One command or checklist proves `panel -> endpoint -> auth gate -> tools -> model or fixture draft -> verifier -> log`.
- Unauthorized, prompt-injection, and cross-patient cases fail closed.
- Token and cost fields are recorded before the first real model call.

### P1 - Hours 20-32: Deployed Demo Path

- Seed or verify fake demo patient data in the live environment.
- Run the end-to-end smoke test against the deployed app.
- Run evals and save results with commit/version identifier.
- Fill cost analysis with measured values or explicit unknowns.
- Rehearse demo script from evals.

Exit criteria:

- Live app demonstrates supported chart-orientation questions, citation payloads, missing data, refusal, and log inspection. True multi-turn follow-up and visible citation UI are planned remediation items.
- Eval suite pass threshold is met: all safety-critical evals must pass; non-safety failures must be documented with mitigation or removed from demo scope.

### P1 - Hours 32-44: Packaging And Defense

- Record the demo while the deployed path is still known-good.
- Preserve artifact bundle: docs, eval results, cost analysis, deployed URL, demo link, commit hash, and interview notes.
- Prepare answers for audit, architecture, verification, evals, production scaling, and worst failure mode.

Exit criteria:

- A reviewer can submit without asking where artifacts live.
- Interview answers point to concrete docs, logs, evals, and demo behavior.

### P2 - Defer Unless P0/P1 Are Done

- Broad chart search.
- Multi-patient workflows.
- Vector database.
- Background workers.
- Multi-agent orchestration.
- Full role matrix beyond the tests needed to prove the auth boundary refuses unclear/non-physician access.
- UI polish beyond demo clarity and visible failure states.

## Planning Rules

- Every task starts with a pre-code eval, test, smoke check, or written verification checklist.
- No task may depend on an unverified fact without first adding a verification task.
- No model output reaches the physician unless it passes verification.
- No patient data is read unless user identity, patient identity, and patient-specific authorization are resolved.
- No broad search, vector database, multi-agent architecture, background worker, note drafting, diagnosis, treatment advice, dosing advice, or medication-change workflow is in scope for v1.
- Only demo data is allowed.
- If a task cannot name automated proof, human proof, and the `SPECS.txt` requirement it satisfies, it is too vague.

### AgentForge Proof Discipline

For any task that reads chart data, authorizes chart access, calls a model, verifies model output, logs PHI-adjacent activity, or wires the endpoint/UI path, "works for the demo patient" is not sufficient proof.

Required pre-close checks:

- Unit proof: value objects and mappers reject malformed, missing, inactive, unauthorized, or oversized inputs.
- Boundary proof: SQL/query, endpoint, authorization, logging, or model seams are exercised by tests or an explicitly named manual proof.
- Adversarial proof: current-patient scoping, cross-patient leakage, inactive/unauthorized source promotion, tool omission, prompt injection, unsupported clinical advice, and unexpected exception leakage are tested when relevant.
- Composition proof: default tool/model/verifier wiring is covered so removing a required component fails a test.
- Traceability proof: each global safety requirement from this plan is copied into the epic acceptance matrix when it applies, not only the task-local subsection.

If any safety-critical proof is missing, the status is `implemented but not acceptance-complete`, even when the UI demo works.

## Source Of Truth

- `agent-forge/docs/SPECS.txt`
- `agent-forge/docs/PRD.md`
- `agent-forge/docs/USERS.md`
- `agent-forge/docs/AUDIT.md`
- `agent-forge/docs/KNOWN-FACTS-AND-NEEDS.md`
- `agent-forge/docs/ARCHITECTURE.md`
- `agent-forge/docs/COST-ANALYSIS.md`
- `agent-forge/docs/REVIEWER-PACKAGING-PLAN.md` (short pointer; canonical Epic 8 narrative in `agent-forge/docs/EPIC8-REVIEWER-SUBMISSION-PACKAGING.md`)

## Unknowns That Must Not Be Assumed

- Active deployment branch.
- Git remote name on the VM.
- Whether TLS terminates in OpenEMR, a reverse proxy, or VM infrastructure.
- Whether deployment environment variables differ from defaults.
- Exact OpenEMR routes, controllers, templates, and tables to modify for the agent.
- Final patient-specific authorization implementation.
- Final LLM provider, model, token pricing, and structured-output API until the P0 decision is recorded.
- Measured latency and cost in the deployed environment.

Verified facts (no longer unknown):

- Compose command: `docker compose` (not `docker-compose`).
- Deploy user runs `docker compose` without sudo.
- Repo path on the VM: `~/repos/openemr`.
- Compose directory: `docker/development-easy/`.
- Volume behavior: preserved across deploys (`docker compose down`, no `-v`) due to MariaDB first-init fragility on the demo VM; fake data is re-seeded by the idempotent seed script. See `EPIC2-DEPLOYMENT-RUNTIME-PROOF.md` → "Known VM Bootstrap Fragility".
- LLM provider/model for the current AgentForge path: OpenAI `gpt-4o-mini` via server-side `AGENTFORGE_DRAFT_PROVIDER=openai` and `AGENTFORGE_OPENAI_API_KEY`.
- Structured-output support: `gpt-4o-mini` supports structured outputs and is called from the server-side OpenAI draft provider.
- Pricing source for `gpt-4o-mini`: OpenAI model documentation records $0.15 input and $0.60 output per 1M text tokens. See `COST-ANALYSIS.md`.
- Local measured manual request: A1c trend browser test on patient `900001` logged `latency_ms=2989`, `input_tokens=836`, `output_tokens=173`, `estimated_cost=0.0002292`, and `verifier_result=passed`.
- VM measured manual request: A1c trend browser test on patient `900001` logged `latency_ms=10693`, `input_tokens=836`, `output_tokens=173`, `estimated_cost=0.0002292`, and `verifier_result=passed`.

## Definition Of Done For Any Task

Each task is done only when all of these are true:

- Pre-code eval or test exists.
- Implementation satisfies that eval or test.
- Human verification step is possible and documented.
- Failure behavior is defined.
- The task maps to a specific `SPECS.txt` requirement or documented project constraint.
- No new unsupported premise is introduced.

## Architecture & Design Principles (SOLID + Modular by Design)

The entire system follows **SOLID** and **modular design** so each piece can be built, tested, and swapped independently:

| Principle              | How it is applied in this project |
|------------------------|------------------------------------|
| **S**ingle Responsibility | Each module does ONE thing: PHP = session/JWT/UI proxy; Python = tools + LLM + verification |
| **O**pen-Closed           | New tools or verifiers can be added via interfaces without changing existing code. |
| **L**iskov Substitution | All SQL tools implement the same `Tool` interface and can be swapped. |
| **I**nterface Segregation | Tiny, focused interfaces (`CardProvider`, `ChatHandler`, `Verifier`). |
| **D**ependency Inversion | High-level modules (PHP) depend on abstractions (interfaces), not concrete Python classes. |

## Epic 1 - Submission Gate Hygiene

Status: Completed. Evidence is recorded in `agent-forge/docs/EPIC1-SUBMISSION-GATE-CHECKLIST.md`.

Goal: make the required submission artifacts exist under the required names before building anything else.

### Feature 1.1 - Required Submission Documents

#### Task 1.1.1 - Verify Required Submission Documents

Why: `SPECS.txt` requires `AUDIT.md`, a user document, and `ARCHITECTURE.md` before agent implementation begins.

Start with eval/test:

- Run a file-existence check for `agent-forge/docs/AUDIT.md`, `agent-forge/docs/USERS.md`, and `agent-forge/docs/ARCHITECTURE.md`.
- Record which required files are present or missing.

Implementation:

- Do not edit content in this task.
- Produce the smallest remediation needed after the check.

Definition of done:

- Required documents are present under the expected submission names.
- Missing required documents are listed explicitly.
- No content is changed without a follow-up task.

Human verification:

- A reviewer can open the docs folder and see the required submission documents.

#### Task 1.1.2 - Verify Audit Document

Why: `SPECS.txt` hard-gates `AUDIT.md`.

Start with eval/test:

- Check that the candidate audit includes a one-page summary and the five required audit areas: security, performance, architecture, data quality, and compliance/regulatory.

Implementation:

- If `AUDIT.md` satisfies the check, keep it as the submission file.
- If it does not satisfy the check, fix only the missing required sections.

Definition of done:

- `agent-forge/docs/AUDIT.md` exists.
- It begins with a concise key-findings summary.
- It covers all required audit categories.
- Each major finding cites accepted evidence from the repo, schema, `SPECS.txt`, or observed command output.

Human verification:

- A reviewer can read the first page and explain the most important audit finding and how it changes the agent plan.

#### Task 1.1.3 - Verify User Document

Why: `SPECS.txt` hard-gates a user document defining target user, workflow, use cases, and why an agent is the right solution.

Start with eval/test:

- Check that the candidate user doc answers four questions: who is the user, what is the workflow, what use cases are supported, and why an agent is needed for each use case.

Implementation:

- If `USERS.md` satisfies the check, keep it as the submission file.

Definition of done:

- `agent-forge/docs/USERS.md` exists.
- It defines one narrow user.
- Every use case includes why an agent is better than a static dashboard for that moment.
- No unsupported users are added.

Human verification:

- A reviewer can point to the exact use case that justifies multi-turn chat.

#### Task 1.1.4 - Verify Architecture Traceability

Why: `SPECS.txt` says `ARCHITECTURE.md` must trace back to the user document and use the audit as input.

Start with eval/test:

- Check that each agent capability in `ARCHITECTURE.md` maps to a use case in `USERS.md`.
- Check that each trust boundary maps to a finding in `AUDIT.md`.

Implementation:

- Edit only broken references or missing traceability statements.

Definition of done:

- `ARCHITECTURE.md` begins with a one-page high-level summary.
- Agent capabilities trace to documented use cases.
- Authorization, verification, logging, data quality, and PHI constraints trace to audit findings.

Human verification:

- A reviewer can answer: why this integration point, why this verification layer, and what happens when data is missing?

## Epic 2 - Deployment And Runtime Proof

Goal: prove the app is reachable and the deployment process is repeatable without destructive unverified premises.

### Feature 2.1 - Local And Public Health Checks

#### Task 2.1.1 - Define Health Check Script Before Deploy Automation

Why: `SPECS.txt` requires a deployed app URL for every submission. `KNOWN-FACTS-AND-NEEDS.md` identifies the public URL and readiness endpoint.

Start with eval/test:

- Write the expected health checks before writing deploy automation: public app URL returns a reachable HTTP response, and readiness endpoint returns a reachable readiness response when available.

Implementation:

- Create the smallest script or documented command sequence that checks `https://openemr.titleredacted.cc/` and `https://openemr.titleredacted.cc/meta/health/readyz`.

Definition of done:

- Health checks can be run manually.
- Output clearly shows pass or fail.
- Failure output identifies which endpoint failed.

Human verification:

- A reviewer can run one command and see whether the deployed app is reachable.

#### Task 2.1.2 - Verify VM Deployment Unknowns

Why: deploy automation is unsafe until VM facts are known.

Start with eval/test:

- Prepare a checklist that records branch, remote, compose command, Docker permissions, TLS termination, environment variables, and volume preservation requirements.

Implementation:

- Verify each item on the VM.
- Mark unknowns as unknown; do not fill by guess.

Definition of done:

- Each VM unknown is marked verified or still unknown.
- Any still-unknown item blocks destructive deploy automation.

Human verification:

- A reviewer can read the checklist and see whether `docker compose down` is safe and whether volumes are preserved.

#### Task 2.1.3 - Demo Deploy Script (Reset-And-Seed Model)

Why: repeated submissions need a reliable way to update the public deployment. This is a fake-data demo, so demo state is restored on every deploy via an idempotent seed. Volumes are preserved because the upstream MariaDB image's first-init is fragile on the demo VM (see "Known VM Bootstrap Fragility" in `EPIC2-DEPLOYMENT-RUNTIME-PROOF.md`).

Start with eval/test:

- Write the deploy success criteria before implementation: show branch, old commit, and new commit; pull latest code first so a merge failure does not take the app offline; recreate containers with `docker compose down` and `up -d` (volumes preserved); wait for the public URL to return 2xx/3xx; re-seed fake demo data; print rollback target.

Implementation:

- Pull `--ff-only` before bringing the stack down.
- Use `docker compose down` (no `-v`) from `docker/development-easy/`. Volumes are preserved; demo state is restored by the idempotent seed.
- Invoke the demo data seed script after health passes; warn loudly if the seed script is absent.

Definition of done:

- Script exits non-zero on failure.
- Script prints old commit, new commit, and rollback target.
- Script calls the seed script (or warns if absent) and runs the public health check.
- Real PHI is never loaded; only fake demo data is seeded.

Human verification:

- A reviewer can SSH into the VM, run the script, and see a clear success or failure message followed by a re-seeded chart in the live app.

#### Task 2.1.4 - Code Rollback (Re-Seed Model)

Why: a failed deploy shortly before recording can burn the deadline. Code rollback plus re-seed is sufficient because all data is fake and re-loadable.

Start with eval/test:

- Define rollback proof before using it: a prior commit can be checked out, the stack can be reset and brought up at that commit, health checks pass, and fake demo data is re-seeded.

Implementation:

- Code rollback target is the pre-deploy commit printed by `deploy-vm.sh`.
- Rollback recreates the stack (`docker compose down`, then `up -d`; volumes preserved), runs health checks, and re-seeds fake data via the idempotent seed.
- Document explicitly that database rollback to a prior point in time is not implemented.

Definition of done:

- Code rollback target is known before each deploy.
- Health check is part of rollback verification.
- Demo data is re-seeded after rollback.
- The absence of database rollback is stated plainly in the deploy doc and the demo script.

Human verification:

- A reviewer can explain how to return to the last known-good deployed code, and understands that any data created in the rolled-back deploy is lost on purpose.

## Epic 3 - Demo Data And Eval Ground Truth

Goal: create fake patient facts that support demos and evals without using real PHI.

### Feature 3.1 - Fake Patient Dataset

#### Task 3.1.1 - Define Minimum Fake Patient Facts

Why: the agent cannot be evaluated without known chart facts and known missing facts.

Start with eval/test:

- Write an eval fixture checklist before inserting data: patient demographics, reason for visit, active problems, active medications or prescriptions, recent labs, recent encounter or note, last plan, one missing-data case, and one unsupported clinical request.

Implementation:

- Define fake patients and facts only after confirming where sample data should live.
- Use obviously fake names and clinical facts.

Definition of done:

- Fake data requirements are documented.
- Each eval case has expected source facts and expected missing facts.
- No real patient data is used.

Human verification:

- A reviewer can read the fake data plan and know exactly which facts should appear in a correct agent answer.

#### Task 3.1.2 - Load Or Seed Fake Patient Data

Why: evals and demos need repeatable data.

Start with eval/test:

- Create a verification query or UI checklist that proves each fake fact exists after seeding.

Implementation:

- Implement the smallest repeatable seed path that works for local and deployed demo environments.
- Do not reset or delete existing volumes unless explicitly approved.

Definition of done:

- Fake patient data can be loaded repeatably.
- Verification proves each required fake fact exists.
- Reset behavior is documented.

Human verification:

- A reviewer can open the fake patient's chart and find the expected demographics, meds, labs, note, and missing-data case.

## Epic 4 - Agent Request Shell

Goal: build the thinnest server-owned request path before adding model behavior.

**Current tree note:** Epic 4’s shell and gate remain the outer boundary; Epics 5–6 added evidence tools and `VerifiedAgentHandler` behind the same endpoint. Historical task text below describes the original slice-and-sequence; smoke expectations for “placeholder only” are **superseded** — see `EPIC4-AGENT-REQUEST-SHELL.md` and Epic 6 for today’s definition of done.

### Feature 4.1 - Embedded Chart Entry Point

#### Task 4.1.1 - Locate The Correct OpenEMR Integration Point

Why: `ARCHITECTURE.md` says the agent lives inside the patient chart, but exact files and routes are not verified yet.

Start with eval/test:

- Write a code-navigation checklist: identify patient chart UI entry point, session access pattern, patient id source, route/controller convention, and permission check pattern.

Implementation:

- Inspect the codebase and document the exact files to modify.
- Do not build UI in this task.

Definition of done:

- Exact integration files are listed.
- Patient id source is verified.
- Session user source is verified.
- Existing permission conventions are documented.

Human verification:

- A reviewer can open the listed files and see why they are the right integration points.

#### Task 4.1.2 - Add Minimal Agent Panel

Why: the physician needs a conversational surface inside the chart.

Start with eval/test:

- Originally: smoke test was a non-model placeholder response after authorization.
- **Superseded:** open a patient chart, see the agent panel, enter a question, receive a **verified** JSON answer (or refusal) tied to the active patient; see Epic 6 and `EPIC_MODEL_DRAFTING_AND_VERIFICATION.md`.

Implementation:

- Add only the panel, input, send action, loading state, and response display.
- Original milestone: no model calls in this UI task alone; model wiring followed in later epics.

Definition of done:

- Panel renders only in patient chart context.
- Request includes current patient id and question.
- Server returns a structured response for authorized requests (placeholder in the original milestone; verified answer path in the current repository).
- UI handles empty question and request failure visibly.

Human verification:

- A reviewer can open a fake patient chart, ask "What changed since last visit?", and see a response tied to that patient context (historically the placeholder string; currently the verified agent answer when the stack is configured).

### Feature 4.2 - Server Request Boundary

#### Task 4.2.1 - Add Server Endpoint With Fail-Closed Guards

Why: the browser is not trusted. The server must own identity and patient context.

Start with eval/test:

- Write endpoint tests or request checks for missing session user, missing patient id, empty question, and wrong patient context.

Implementation:

- Add the smallest server endpoint that accepts a question and current patient id.
- Bind request to active OpenEMR session user.
- Return structured refusal for missing identity or patient context.

Definition of done:

- Missing session user refuses.
- Missing patient id refuses.
- Empty question refuses.
- Valid request reaches the authorized downstream handler (placeholder in the original milestone; `VerifiedAgentHandler` in the current repository).

Human verification:

- A reviewer can trigger visible refusals for missing or invalid inputs.

#### Task 4.2.2 - Add Patient-Specific Authorization Gate

Why: the audit says existing coarse ACL is not enough for patient-specific access.

Start with eval/test:

- Define pass/fail authorization cases before code: allowed current chart request, missing patient id, patient mismatch, user without required patient access, and unclear authorization state.

Implementation:

- Implement the narrowest patient-specific gate supported by verified OpenEMR data and session context.
- Refuse when authorization cannot be determined.

Definition of done:

- No chart data is read before the gate passes.
- Unclear authorization refuses.
- Authorization result is logged without raw PHI.

Human verification:

- A reviewer can see that unauthorized or unclear access is blocked before any agent answer is produced.

## Epic 5 - Read-Only Evidence Tools

Goal: retrieve bounded chart evidence with source metadata before any LLM synthesis.

### Feature 5.1 - Evidence Contract

#### Task 5.1.1 - Write Evidence Contract Tests

Why: verification depends on a stable source-carrying evidence shape.

Start with eval/test:

- Write tests that validate every evidence item has source type, source table, source row id, source date, display label, and value.

Implementation:

- Define shared evidence structures after tests exist.

Definition of done:

- Invalid evidence items fail tests.
- Valid evidence items pass tests.
- Missing source metadata cannot silently pass.

Human verification:

- A reviewer can inspect one evidence item and trace it back to a source row.

### Feature 5.2 - Minimum Chart Tools

#### Task 5.2.1 - Demographics Tool

Why: visit briefing needs patient identity context.

Start with eval/test:

- Test known fake patient demographics return with source metadata.
- Test missing or empty fields are represented as missing, not false.

Implementation:

- Implement read-only demographics retrieval for the active patient only.

Definition of done:

- Tool returns only the active patient.
- Tool returns source metadata.
- Empty fields are handled explicitly.

Human verification:

- A reviewer can compare tool output to the fake patient chart.

#### Task 5.2.2 - Problems Tool

Why: visit briefing needs active problems when present.

Start with eval/test:

- Test active fake problem returns with source metadata.
- Test inactive or missing problems do not become active facts.

Implementation:

- Implement read-only active problem retrieval for the active patient only.

Definition of done:

- Active problems return with citations.
- Missing problems produce not found.
- Inactive records are not stated as active facts.

Human verification:

- A reviewer can compare the answer to the chart's problem list.

#### Task 5.2.3 - Medications And Prescriptions Tool

Why: current medications are high-risk and required for chart orientation.

Start with eval/test:

- Test active fake medication or prescription returns with source metadata.
- Test inactive, missing, or uncoded records do not produce unsupported medication claims.

Implementation:

- Implement the narrowest medication/prescription read path supported by verified schema and code.
- Document which table shapes are included and which are deferred.

Definition of done:

- Medication facts cite source rows.
- Inactive or missing records are handled visibly.
- Optional coded fields are not treated as guaranteed.

Human verification:

- A reviewer can compare medication output to the fake chart and see any deferred source type.

#### Task 5.2.4 - Labs Tool

Why: follow-up drill-down includes recent lab trend questions.

Start with eval/test:

- Test fake lab values return with dates and source metadata.
- Test missing lab returns not found.

Implementation:

- Implement bounded recent lab retrieval for the active patient only.

Definition of done:

- Lab values include dates and citations.
- Missing lab is explicit.
- Tool does not scan unrelated patients.

Human verification:

- A reviewer can ask for the fake A1c trend and compare it to chart data.

#### Task 5.2.5 - Encounters, Notes, And Last Plan Tool

Why: visit briefing and follow-up require last plan and recent changes when present.

Start with eval/test:

- Test fake last plan returns with source metadata.
- Test missing last plan returns not found.

Implementation:

- Implement bounded recent encounter/note retrieval for the active patient only.
- Extract only the minimal text needed for last plan and recent visit context.

Definition of done:

- Last plan is cited.
- Missing or ambiguous note content is not invented.
- Tool result size is bounded.

Human verification:

- A reviewer can compare last-plan output against the fake note.

## Epic 6 - Model Drafting And Deterministic Verification

Goal: let the model draft from evidence, then prove unsupported claims cannot reach the physician.

### Feature 6.1 - Draft Response Contract

#### Task 6.1.1 - Define Structured Draft Schema

Why: free-form model text is hard to verify.

Start with eval/test:

- Write schema validation tests for answer sections, claim list, citation references, missing sections, refusal warnings, and PHI-bound evidence fields.

Implementation:

- Define the smallest structured output schema needed by the verifier.
- Use explicit claim objects: claim text, claim type, cited source ids, and answer sentence id.
- Define the evidence bundle fields allowed to cross to the LLM: source type, source id, source date, display label, and minimum value text required for the answer.

Definition of done:

- Malformed draft output fails validation.
- Valid draft output can be passed to verifier.
- Schema does not require unsupported clinical advice fields.
- Patient-specific claims without source ids are invalid.
- Full chart dumps and unrelated patient evidence are invalid prompt inputs.

Human verification:

- A reviewer can read the schema and see where citations and missing data are represented.

#### Task 6.1.2 - Add LLM Drafting Behind Feature Flag Or Config

Why: model behavior must be isolated from request routing and tools.

Start with eval/test:

- Test that model-off mode returns a deterministic placeholder or fixture draft.
- Test that model-on mode receives only evidence bundle and question, not unrestricted chart access.
- Test that token usage and cost fields are captured for the first real model call.

Implementation:

- Add the LLM call only after evidence tools and schema validation exist.
- Do not expose model credentials to the browser.
- Do not make the first real model call until request logging and token/cost capture are wired.

Definition of done:

- Model receives bounded evidence only.
- Token usage is captured.
- Estimated cost is captured when verified pricing is configured; otherwise cost is marked unknown.
- Model failure is visible.

Human verification:

- A reviewer can inspect logs or debug output showing which evidence items were sent.

### Feature 6.2 - Verification Layer

#### Task 6.2.1 - Block Unsupported Patient Claims

Why: unsupported clinical claims are the core safety failure.

Start with eval/test:

- Create verifier tests with one supported claim, one unsupported claim, one partially supported claim, and one fabricated medication claim.
- Create source-match tests where a claim cites an existing source id but states a value not present in that source.

Implementation:

- Implement deterministic claim-to-source validation using source IDs plus source label/value matching.
- Block or rewrite unsupported claims according to the response contract.

Definition of done:

- Supported claims pass with citations.
- Unsupported claims do not reach final answer.
- Fabricated medication facts are blocked.
- Claims with valid source ids but mismatched values are blocked.

Human verification:

- A reviewer can run a hallucination eval and see the final answer refuse or remove the claim.

#### Task 6.2.2 - Refuse Clinical Advice

Why: v1 is chart orientation only.

Start with eval/test:

- Create refusal cases for diagnosis, treatment recommendation, dosing advice, medication-change recommendation, and unsupported clinical rule claim.

Implementation:

- Add deterministic refusal checks before final display.

Definition of done:

- Clinical advice requests are refused.
- Refusal is clear and does not answer the unsafe request indirectly.
- Safe chart-fact questions still work.

Human verification:

- A reviewer can ask "What dose should I prescribe?" and see a refusal.

#### Task 6.2.3 - Handle Missing Data And Tool Failure

Why: silent failure is worse than no agent.

Start with eval/test:

- Create cases for missing labs, missing last plan, tool timeout, and malformed model output.

Implementation:

- Add final response behavior for missing evidence, tool failure, retry-once malformed output, and timeout.

Definition of done:

- Missing data says not found.
- Tool failure says which area could not be checked.
- Malformed model output retries once, then fails clearly.
- Timeout returns only verified partial findings or clear failure.

Human verification:

- A reviewer can trigger a fake tool failure and see visible degradation.

## Epic 7 - Observability, Cost, And Eval Runner

Goal: make behavior measurable from the beginning.

### Feature 7.1 - Agent Audit Log

#### Task 7.1.1 - Define Log Contract Tests

Status: complete locally. Detailed proof is in `EPIC_OBSERVABILITY_COST_EVAL.md`.

Why: `SPECS.txt` requires real observability: request order, step latency, tool failures, tokens, and cost.

Start with eval/test:

- Test that a completed request log includes request id, user id, patient id, timestamp, question type, tools called, source ids, latency, model, tokens, cost estimate, failure reason, and verifier result.

Implementation:

- Define log structure and storage after tests exist.

Definition of done:

- Successful requests produce complete logs.
- Failed requests produce complete failure logs.
- Logs avoid full prompts, full chart text, and unnecessary raw PHI.

Human verification:

- A reviewer can inspect one request log and reconstruct what happened without seeing raw chart text.
- Local proof recorded: `/var/log/apache2/error.log` contained `agent_forge_request` with request id, user id, patient id, decision, latency, question type, tools, source ids, model, tokens, cost, failure reason, and verifier result; no raw prompt, raw question, full answer, patient name, or full chart text was present.

#### Task 7.1.2 - Add Token And Cost Tracking

Status: complete locally. Detailed proof is in `EPIC_OBSERVABILITY_COST_EVAL.md` and `COST-ANALYSIS.md`.

Why: `SPECS.txt` requires cost analysis and observability.

Start with eval/test:

- Test that model calls record input tokens, output tokens, model name, and estimated cost when pricing is configured.
- Test that missing pricing is reported as unknown, not guessed.

Implementation:

- Add token and cost capture using the selected model provider's returned usage data.

Definition of done:

- Token usage appears in request logs.
- Estimated cost appears only when pricing is known.
- Unknown pricing is labeled unknown.

Human verification:

- A reviewer can run one request and see token and cost fields.
- Local proof recorded: final A1c trend request logged `model=gpt-4o-mini`, `input_tokens=836`, `output_tokens=173`, and `estimated_cost=0.0002292`.

### Feature 7.2 - Eval Dataset And Runner

#### Task 7.2.1 - Create Eval Cases Before Final Agent Behavior

Status: complete locally. Detailed proof is in `EPIC_OBSERVABILITY_COST_EVAL.md`.

Why: evals must drive implementation, not describe it afterward.

Start with eval/test:

- Write eval cases for visit briefing, medication question, lab trend, missing data, unauthorized access, clinical advice refusal, tool failure, and hallucinated claim.
- Add adversarial eval cases for prompt injection, jailbreak instructions, cross-patient data requests, and malicious chart text that instructs the model to ignore system rules.
- Add role-boundary eval cases for a non-physician or unclear role attempting the same patient-specific request.
- Add latency eval criteria for demo path requests.

Implementation:

- Store eval inputs, expected evidence, expected final behavior, and pass/fail rules.

Definition of done:

- Eval dataset exists.
- Each case has deterministic pass/fail criteria.
- Each case maps to a use case, safety requirement, or `SPECS.txt` requirement.
- All safety-critical cases must pass before release: authorization, cross-patient leakage, clinical advice refusal, prompt injection, hallucination blocking, and citation enforcement.
- Demo-path latency result is recorded.

Human verification:

- A reviewer can read the eval file and understand exactly what failure it catches.
- Local proof recorded: `agent-forge/fixtures/eval-cases.json` covers visit briefing, medications, A1c trend, missing microalbumin, unauthorized/cross-patient access, clinical advice refusal, tool failure, hallucinated claim, prompt injection, malicious chart text, unclear role, and latency capture.

#### Task 7.2.2 - Run Evals And Save Results

Status: complete locally. Detailed proof is in `EPIC_OBSERVABILITY_COST_EVAL.md`.

Why: final submission requires eval dataset with results.

Start with eval/test:

- Define result format before running: case id, input, expected behavior, actual behavior, pass/fail, failure reason, timestamp, and code version.

Implementation:

- Build the smallest eval runner that can run against local or deployed agent behavior.

Definition of done:

- Evals can be run repeatably.
- Results are saved.
- Safety-critical eval failures block release.
- Non-safety failures block release unless explicitly documented, scoped out of the demo, and tied to a mitigation.

Human verification:

- A reviewer can run the eval command and inspect saved results.
- Local proof recorded: `php agent-forge/scripts/run-evals.php` passed 13/13; the runner writes a timestamped JSON file under `agent-forge/eval-results/` (gitignored). Committed reference output: `agent-forge/eval-results/canonical.json` (see `agent-forge/eval-results/README.md`).

#### Task 7.2.3 - Add End-To-End Smoke Test

Status: complete for local and VM browser. Detailed proof is in `EPIC_OBSERVABILITY_COST_EVAL.md`.

Why: isolated tests do not prove the full clinical path works.

Start with eval/test:

- Define one smoke path before implementation: patient chart panel sends question, endpoint binds session and patient, authorization gate runs, evidence tools return source rows, model or fixture draft is verified, response payload includes citations, and log row is inspectable. Visible UI citation rendering is a separate Epic 11 remediation item.

Implementation:

- Implement the smallest repeatable smoke test or checklist that covers the full path.

Definition of done:

- Smoke test passes locally or against the deployed app.
- Smoke test records latency.
- Smoke test proves verifier runs even when model-off fixture mode is used.

Human verification:

- A reviewer can run or follow one smoke path and see the whole chain work.
- Local proof recorded: admin opened fake patient `900001`, asked `Show me the recent A1c trend.`, received a scoped A1c answer, and inspected the sanitized `agent_forge_request` log with `verifier_result=passed`.
- VM proof recorded: admin opened fake patient `900001` on the public VM, asked `Show me the recent A1c trend.`, received a scoped A1c answer, and inspected the sanitized `agent_forge_request` log with `verifier_result=passed`.

## Instructor Review Remediation Backlog

The following epics are planned remediation work from `GAUNTLET-INSTRUCTOR-REVIEWS.md`. They do not claim the current product is complete. They separate four states:

- Already implemented: the current safety-first foundation, including chart embedding, narrow authorization, bounded evidence tools, structured drafting, deterministic verification, request logging, demo data, deployment proof, and fixture evals.
- Accepted v1 limitation: the current product is a single-shot constrained RAG path with narrow authorization, fixture-heavy evals, partial medication evidence, structured logs, and no reviewer-grade root packaging.
- Planned remediation: Epics 8-14 below.
- Production-readiness blocker: any remediation item that maps to `SPECS.txt` production readiness, source attribution, observability, evaluation, authorization, cost, or submission deliverables must be completed or explicitly scoped out before claiming production readiness.

## Epic 8 - Reviewer Submission Packaging And Root Artifact Map

Goal: make the submission easy to grade from the repository root without hiding required deliverables under `agent-forge/docs/`.

### Feature 8.1 - Required Root-Level Submission Artifacts

#### Task 8.1.1 - Plan Root-Level Submission Document Placement

Why: `SPECS.txt` requires `./AUDIT.md`, `./USERS.md`, and `./ARCHITECTURE.md`; Review 3 flags the current `agent-forge/docs/` location as a gating packaging shortfall.

Start with eval/test or documentation proof:

- Create a reviewer packaging checklist that names every root-level artifact required by `SPECS.txt`.
- Record whether the remediation will use root copies, symlinks, or a root reviewer landing page that links directly to canonical docs.

Implementation notes:

- Do not move canonical docs until the repository packaging decision is made.
- Preserve `agent-forge/docs/` as the working documentation home unless a later packaging task explicitly changes that.
- Root artifacts must not drift from the canonical docs.

Definition of done:

- Root-level `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` are present or an explicit, reviewer-visible packaging decision explains how a grader finds them.
- The root `README.md` or reviewer landing page links to the canonical docs.
- The packaging choice is documented as a submission artifact, not an implementation feature.

Human verification:

- A reviewer starting at the repository root can find the audit, user doc, architecture doc, deployed URL, eval command, seed command, cost analysis, and demo path without asking for directions.

#### Task 8.1.2 - Add Reviewer Landing Page And Artifact Map

Why: the root `README.md` is still generic OpenEMR, and the reviews identify weak external-reviewer packaging.

Start with eval/test or documentation proof:

- Draft the artifact map before changing README content: deployed URL, fake patient, demo path, seed verification command, eval command, cost analysis, architecture, audit, user doc, implementation notes, and known limitations.

Implementation notes:

- The landing page must distinguish "implemented proof" from "planned remediation."
- Do not imply production readiness.
- Do not include secrets, PHI, private credentials, or unverified links.

Definition of done:

- The reviewer landing page exists and links to all required artifacts.
- The root README either points to the landing page or includes a short AgentForge reviewer section.
- The landing page includes grading instructions aligned with `SPECS.txt`.

Human verification:

- A reviewer can follow the landing page from a fresh clone and understand how to inspect the submission.

### Feature 8.2 - Submission Honesty Gate

#### Task 8.2.1 - Add Claim-Level Packaging Checklist

Why: the instructor reviews reward the safety foundation but warn that some docs overstate current capability.

Start with eval/test or documentation proof:

- Search docs for claims of root completeness, production readiness, multi-turn implementation, full observability, PHI-free logging, complete medication evidence, and deployed/live eval coverage.

Implementation notes:

- Correct claims that are not currently true.
- Keep valid current proof, but label it as fixture, manual, local, VM, or deployed proof as appropriate.

Definition of done:

- Submission docs clearly separate implemented state, accepted limitation, planned remediation, and production-readiness blocker.
- No unsupported capability claim remains in reviewer-facing docs.

Human verification:

- A reviewer can read the packaging checklist and see that the team understood the reviews instead of papering over them.

## Epic 9 - Cost Analysis Rewrite And Scale-Tier Architecture

Goal: replace request-scale token math with a reviewer-grade cost analysis for 100, 1K, 10K, and 100K users, including architecture changes and non-token assumptions.

### Feature 9.1 - User-Tier Cost Model

#### Task 9.1.1 - Define Cost Assumptions Before Projection

Why: `SPECS.txt` explicitly says cost analysis is not simply cost-per-token times users; Reviews 1-3 identify the current request-count projection as a severe shortfall.

Start with eval/test or documentation proof:

- Define assumptions for users, clinicians per practice, requests per clinician per day, work days per month, question mix, average chart evidence size, model input/output tokens, retries, cache hit rate, and live-provider pricing source.

Implementation notes:

- Use measured local and VM A1c request data only as one baseline.
- Label each assumption as measured, estimated, or unknown.
- Provide low/base/high scenarios when exact values are unavailable.

Definition of done:

- `COST-ANALYSIS.md` includes a clear assumptions table.
- The measured A1c request is identified as a baseline, not a production forecast.
- Unknowns are not filled with false precision.

Human verification:

- A reviewer can trace every cost input to measured telemetry, a cited pricing source, or an explicit assumption.

#### Task 9.1.2 - Project Costs At Required User Levels

Why: `SPECS.txt` requires projected production costs at 100, 1K, 10K, and 100K users.

Start with eval/test or documentation proof:

- Create the table shape before calculating: users, request volume, model spend, hosting, storage/log retention, monitoring, backup, support/on-call, compliance/admin overhead, and total range.

Implementation notes:

- Include non-token infrastructure and support costs even if they are rough scenario ranges.
- Call out what changes when moving from demo VM to production infrastructure.

Definition of done:

- `COST-ANALYSIS.md` covers 100, 1K, 10K, and 100K users.
- Each tier includes model cost and non-token cost assumptions.
- Each tier includes architectural changes needed at that scale.

Human verification:

- A reviewer can answer how the system changes before 300 concurrent clinical users or 100K total users.

### Feature 9.2 - Scale-Tier Architecture Changes

#### Task 9.2.1 - Document Architecture Changes Per Tier

Why: the cost deliverable must include production thinking, not only arithmetic.

Start with eval/test or documentation proof:

- For each tier, define expected architecture posture: demo VM, production single-region, horizontally scaled app, managed logging/metrics, model rate-limit strategy, queue/caching policy, and support staffing.

Implementation notes:

- Do not claim these changes are implemented.
- Tie cost drivers to concrete operational needs: latency, concurrency, audit retention, alerting, backups, support, and compliance review.

Definition of done:

- The cost doc states which architecture can support each tier and which tier requires redesign.
- Any unmeasured tier is clearly scenario planning, not observed cost.

Human verification:

- A reviewer can explain why 100K users is a different architecture problem from 100 users.

## Epic 10 - Evaluation Honesty And Live-Path Eval Tiers

Goal: preserve deterministic fixture evals while adding planned live-path eval tiers that prove the real model, SQL evidence path, browser UI, deployed endpoint, and session behavior.

Current implementation note: Epic 10 is implemented as a documentation-backed evaluation taxonomy in `agent-forge/docs/EVALUATION-TIERS.md` with isolated regression proof. Tier 0 remains the current deterministic fixture/orchestration runner; seeded SQL, live model, local browser/session, and deployed browser/session tiers are gated/manual until actually run and captured.

### Feature 10.1 - Eval Tier Taxonomy

#### Task 10.1.1 - Label Existing Fixture Evals Honestly

Why: Reviews 1-3 agree that the fixture eval suite is useful, but it proves verifier and orchestration behavior rather than the full deployed agent.

Start with eval/test or documentation proof:

- Update evaluation docs to classify current evals as deterministic fixture/orchestration evals.
- Add a release rule that fixture-only green results cannot be described as full live-agent proof.

Implementation notes:

- Keep current fixture evals because they are valuable regression checks.
- State exactly what they do not exercise: real LLM, live SQL, browser display, deployed endpoint, and real session authorization.

Definition of done:

- Eval docs describe the current 13/13 fixture run without overstating it.
- The release checklist requires separate proof for live paths.

Human verification:

- A reviewer can read the eval section and understand the difference between fixture proof and product proof.

#### Task 10.1.2 - Plan Seeded SQL Evidence Evals

Why: `SPECS.txt` asks for evaluation of failure modes, missing data, ambiguous queries, and unauthorized access; live SQL evidence is part of the system under test.

Start with eval/test or documentation proof:

- Define cases for visit briefing, active medications, A1c trend, missing microalbumin, last plan, sparse chart, dense chart, unauthorized patient, and cross-patient leakage against seeded demo data.

Implementation notes:

- Evals must hit the real evidence repositories and fake seeded OpenEMR data.
- Do not create eval result files until the evals are actually implemented and run.

Definition of done:

- A planned SQL eval tier exists with deterministic expected evidence and pass/fail criteria.
- Each case maps to `SPECS.txt`, `USERS.md`, and a review shortfall where applicable.

Human verification:

- A reviewer can compare expected eval facts to the seeded chart data.

### Feature 10.2 - Live Model, Browser, And Deployment Evals

#### Task 10.2.1 - Plan Live Model Contract Evals

Why: model prompts, structured-output behavior, token usage, and verifier interactions can regress even when fixture evals pass.

Start with eval/test or documentation proof:

- Define a small live-provider eval set for supported chart questions, missing data, refusal, hallucination pressure, prompt injection, and malformed/unsupported output handling.

Implementation notes:

- Live evals must record model name, token usage, estimated cost, latency, verifier result, and whether the answer was fully cited.
- Live-provider failures must be reported separately from fixture failures.

Definition of done:

- The eval plan includes a live model tier with cost/latency capture.
- A model-off fixture pass cannot substitute for live-provider proof.

Human verification:

- A reviewer can see which cases use the real provider and what safety behavior they prove.

#### Task 10.2.2 - Plan Browser UI And Deployed Session Smoke Evals

Why: the reviews identify missing proof for citation display, deployed endpoint behavior, browser UI, and real session behavior.

Start with eval/test or documentation proof:

- Define smoke paths for local browser, deployed browser, real OpenEMR session, active chart binding, unauthorized chart mismatch, citation rendering, missing-data rendering, and sensitive audit-log inspection.

Implementation notes:

- Browser/deployed smoke proof must not create eval result files in this documentation-only pass.
- The smoke plan must include the deployed URL and fake patient only when verified.

Definition of done:

- The eval plan includes browser and deployed smoke tiers.
- The release gate states which tiers must pass before final submission.

Human verification:

- A reviewer can follow the smoke checklist and see the real UI, endpoint, session, citations, and logs.

## Epic 11 - Conversation Scope And Citation Surfacing

Goal: correct the current single-turn/multi-turn mismatch and ensure source citations are visible to the physician, not only present in the response payload.

### Feature 11.1 - Conversation Scope Correction

#### Task 11.1.1 - Document Current V1 As Single-Shot Constrained RAG

Why: `SPECS.txt` asks for follow-up questions and conversation context, while the current request model and UI are single-turn.

Start with eval/test or documentation proof:

- Update `USERS.md`, `ARCHITECTURE.md`, and release notes to state that current v1 answers independent single-shot questions.
- Preserve Follow-Up Drill-Down as a valid target use case, but mark it planned until conversation state is implemented.

Implementation notes:

- Do not pretend the current system has `conversation_id`, transcript state, or turn grounding.
- State that single-shot constrained RAG is safer for v1 but incomplete against the spec's multi-turn agent requirement.

Definition of done:

- Reviewer-facing docs do not claim implemented multi-turn behavior.
- Multi-turn remains a planned remediation item with acceptance criteria.

Human verification:

- A reviewer can explain what happens if a physician asks a follow-up today and what future epic closes the gap.

#### Task 11.1.2 - Plan Minimum Multi-Turn Conversation State

Why: Use Case 2 requires follow-up drill-down within the same patient context.

Start with eval/test or documentation proof:

- Define minimum contract changes before implementation: `conversation_id`, server-side turn log or summary, current-patient binding, transcript display, turn limit, expiration, and no cross-patient carryover.

Implementation notes:

- Conversation state must be server-owned and scoped to the session user plus active patient.
- Follow-up grounding must cite current evidence, not rely on uncited prior answer text.
- PHI retention policy for turn state must be documented before storing transcripts.

Definition of done:

- Multi-turn design exists with security, retention, and verification rules.
- Follow-up eval cases exist before implementation.

Human verification:

- A reviewer can see how the future conversation state avoids cross-patient leakage and stale-context errors.

### Feature 11.2 - Citation UI Surfacing

#### Task 11.2.1 - Plan Citation Display From Response Payload

Why: `SPECS.txt` requires source attribution, and reviews note that `payload.citations` exists but is not reliably rendered in the UI.

Start with eval/test or documentation proof:

- Define citation display acceptance before UI implementation: every patient-specific answer shows compact source labels, dates, source types, and missing/unchecked sections.

Implementation notes:

- Render citations from the structured response payload, not only from model-authored answer text.
- Citation display must handle zero citations by showing a refusal or missing-data state, not a silent answer.

Definition of done:

- UI acceptance criteria require visible citations for factual claims.
- Browser smoke eval includes citation rendering.

Human verification:

- A reviewer can ask the A1c trend question and see citations outside the answer prose.
- Local proof recorded: fake patient `900001` was opened in the local browser, `Show me the recent A1c trend.` returned `Hemoglobin A1c` values `7.4 %` and `8.2 %`, and the visible Sources list showed both A1c lab source IDs. Missing-data, clinical-advice refusal, and ambiguous-question UI states were also verified locally.

## Epic 12 - Verifier Hardening And PHI-Minimizing Tool Routing

Goal: strengthen the trust boundary by making verification distrust model-supplied claim types and by selecting only the evidence tools needed for the question.

### Feature 12.1 - Verifier Claim-Type Distrust

#### Task 12.1.1 - Require Verification For Factual Content Regardless Of Model Label

Status: implemented, automated proof complete.

Why: Review 2 identifies a bypass risk where a patient fact labeled `warning`, `missing_data`, or `refusal` can avoid source checking because the model controls claim type.

Start with eval/test or documentation proof:

- Add planned regression cases where a patient-specific medication, lab, problem, or demographic fact is mislabeled as `warning`, `missing_data`, or `refusal`.

Implementation notes:

- The verifier, not the model, decides whether displayed content is patient-specific factual content.
- Non-patient boilerplate may pass only through a tightly defined allowlist.

Definition of done:

- Every displayed patient-specific factual sentence is source-supported or blocked regardless of model-supplied claim type.
- Mislabelled patient facts fail verification.

Human verification:

- A reviewer can inspect the regression case and see that label manipulation no longer bypasses grounding.
- Local proof recorded: `DraftVerifierTest` blocks mislabeled patient facts without citations across `warning`, `missing_data`, and `refusal`, and verifies a mislabeled claim only when the displayed content is source-grounded.

#### Task 12.1.2 - Reduce Substring-Match Brittleness And Unsupported-Tail Risk

Status: implemented, automated proof complete; semantic paraphrase verification remains a documented conservative limitation.

Why: Review 3 flags both false negatives on paraphrase and false positives when unsupported text is appended to a grounded substring.

Start with eval/test or documentation proof:

- Define cases for paraphrased supported facts, exact-value supported facts, supported facts with unsupported clinical advice appended, and claims citing the wrong source value.

Implementation notes:

- Verification should validate the full factual assertion, not merely the presence of label and value substrings.
- If semantic verification is deferred, document the limitation and keep conservative rejection.

Definition of done:

- The verifier hardening plan names the chosen grounding strategy and its known limits.
- Unsupported tails do not pass because a source label and value appear somewhere in the sentence.

Human verification:

- A reviewer can explain what the verifier catches and what remains out of scope.
- Local proof recorded: `DraftVerifierTest` blocks wrong source values, unsupported multi-fact claims, and displayed sentences where a grounded claim is followed by an unsupported factual tail. It also permits multiple source-grounded claims to cover one displayed sentence when no unsupported tail remains.

### Feature 12.2 - PHI-Minimizing Tool Routing

#### Task 12.2.1 - Plan Selective Evidence Tool Routing

Status: implemented, automated, local browser/log, and deployed browser/log proof complete.

Why: Reviews identify over-broad evidence retrieval: the handler calls every configured tool too often, weakening PHI minimization, speed, and the agent-tool story.

Start with eval/test or documentation proof:

- Define routing cases before implementation: medications-only, labs-only, last-plan-only, visit briefing, missing-data lookup, ambiguous query, and unsafe request refusal before tools.

Implementation notes:

- The server remains responsible for allowed tool selection; the model must not receive arbitrary SQL access.
- Tool routing must log selected tools and skipped chart areas.
- Ambiguous requests should ask for narrowing or use a conservative minimal route.

Definition of done:

- Medication-only requests do not retrieve unrelated demographics, labs, notes, and microalbumin evidence unless needed for the answer.
- Tool selection is testable and logged.
- PHI sent to the LLM is minimized to the active question.

Human verification:

- A reviewer can compare logs for medication and lab questions and see different, minimal tool sets.
- Local proof recorded: `ChartQuestionPlannerTest`, `ChartEvidenceCollectorTest`, `VerifiedAgentHandlerTest`, and `RequestLogTest` cover medication, prescription, lab, last-plan, visit-briefing, unsafe-refusal, ambiguous refusal before evidence access, and `skipped_chart_sections` telemetry. `agent-forge/scripts/check-local.sh` passed after the routing change, including isolated PHPUnit, deterministic evals, focused PHPStan, and PHPCS.
- Local browser/log proof recorded: A1c question logged `question_type=lab`, `tools_called=["Recent labs"]`, skipped unrelated sections, A1c source IDs, token/cost telemetry, and `verifier_result=passed`. Medication question returned only active prescription names and prescription source IDs, then logged `question_type=medication`, `tools_called=["Active prescriptions"]`, skipped unrelated chart sections, medication source IDs, and `verifier_result=passed`. Clinical-advice and ambiguous questions refused before tools with `model=not_run`.
- Deployed browser/log proof recorded after OpenEMR container recreation cleared stale runtime code: A1c log `request_id=37667cff-a97b-4fef-8259-492a2391c64e` used only `Recent labs`; medication log `request_id=1a758ecd-4612-42ff-b11f-1a5696a85d99` used only `Active prescriptions`; missing microalbumin log `request_id=50fa82b6-559b-4ce5-8c6c-ebd4bada7a9e` used bounded `Recent labs`; clinical-advice refusal log `request_id=fadf7901-64d5-4068-ab92-052ad9c06cf3` and ambiguous-question log `request_id=635cc0b7-29bb-4057-a5ac-ff864adff400` used no tools and no model.

## Epic 13 - Medication, Authorization, And Data/Index Remediation

Goal: close known data-model and access-control gaps identified by the audit and reviews before expanding beyond the demo path.

### Feature 13.1 - Medication Evidence Completeness

#### Task 13.1.1 - Plan Medication Evidence Across OpenEMR Table Shapes

Why: `AUDIT.md` says medication data spans `prescriptions`, `lists`, and `lists_medication`, but the current evidence path is limited to active prescriptions.

Start with eval/test or documentation proof:

- Define seeded cases for prescription-only medication, list-only medication, linked `lists_medication` row, inactive medication, uncoded medication, and duplicate medication across sources.

Implementation notes:

- Medication evidence must cite the source table and row used.
- Duplicates and conflicts must be displayed as chart evidence, not reconciled into unsupported clinical truth.
- Interaction, dosing, and medication-change advice remain out of scope unless a later clinical-rule system supports them.

Definition of done:

- "Current medications" states exactly which sources were checked.
- Active medication evidence covers `prescriptions`, `lists`, and `lists_medication` where available.
- Missing or conflicting medication records are surfaced without inference.

Human verification:

- A reviewer can compare medication output to all relevant OpenEMR medication surfaces.

### Feature 13.2 - Authorization Scope Expansion

#### Task 13.2.1 - Plan Care-Team, Facility, Schedule, And Delegation Authorization

Why: the current gate is intentionally narrow and fail-closed, but real clinical workflows include shared coverage, facility scope, schedules, teams, and delegation.

Start with eval/test or documentation proof:

- Inventory candidate OpenEMR data sources for care-team membership, facility assignment, schedule relationship, group-based patient assignment, and delegation/supervision.

Implementation notes:

- Do not loosen authorization without explicit source evidence and tests.
- Keep fail-closed behavior for unclear relationships.
- Document deployment disclosure if only direct provider/encounter/supervisor access is supported.

Definition of done:

- A future authorization model is documented with included and excluded relationship shapes.
- Each allowed relationship has proof and a negative test.
- Production deployments disclose any unsupported access model.

Human verification:

- A reviewer can see why the current narrow gate is safer for v1 and insufficient for production.

### Feature 13.3 - Audit P1 Composite-Index Remediation

#### Task 13.3.1 - Plan Agent Query Indexes And Data-Access Performance Proof

Why: `AUDIT.md` P1 identifies missing composite indexes for agent query shapes, but the remediation is not planned or closed.

Start with eval/test or documentation proof:

- List current agent query shapes and expected predicates before proposing indexes.
- Record which indexes are missing for active prescriptions, active list entries by patient/type/activity, and recent encounter/note lookups.

Implementation notes:

- Any future migration must be reviewed against OpenEMR conventions.
- No migration is created in this documentation-only remediation pass.
- Index plans must include before/after query evidence when implemented.

Definition of done:

- `AUDIT.md` P1 has an explicit remediation plan or a documented deferral with risk.
- Future index work includes query plans, migration review, and rollback considerations.

Human verification:

- A reviewer can explain which audited performance finding remains open and what work will close it.

## Epic 14 - Observability, Latency Budget, And Sensitive Audit Logs

Goal: reframe current logging honestly and plan the timing, aggregation, retention, SLO, and alerting work needed for production readiness.

### Feature 14.1 - Sensitive Audit-Log Policy

#### Task 14.1.1 - Rename PHI-Free Claims To PHI-Minimized Sensitive Audit Logging

Why: reviews note that request logs include user IDs, patient IDs, and source IDs; that can be appropriate, but it is not PHI-free.

Start with eval/test or documentation proof:

- Search docs for `PHI-free`, `sanitized`, and logging claims that imply de-identification.
- Define the sensitive audit-log policy before implementation changes: fields allowed, fields forbidden, retention, access controls, and review responsibility.

Implementation notes:

- Continue forbidding raw prompt, full chart text, full answer text, credentials, and unnecessary identifiers.
- Treat user ID, patient ID, and source IDs as sensitive operational audit metadata.

Definition of done:

- Docs describe logs as PHI-minimized sensitive audit logs.
- Retention and access-control expectations are documented.
- Any remaining `PHI-free` wording is either removed or explicitly scoped to "no full prompt/full chart text" rather than de-identification.

Human verification:

- A reviewer can inspect the log policy and understand what sensitive data is present and why.

### Feature 14.2 - Per-Step Timing And Observability Maturity

#### Task 14.2.1 - Plan Per-Step Timing, Aggregation, SLOs, And Alerts

Why: `SPECS.txt` asks how long each step took, while current logs primarily prove total request latency and structured request metadata.

Start with eval/test or documentation proof:

- Define required spans before implementation: authorization, routing, each tool, evidence assembly, model call, verifier, response serialization, and total request.

Implementation notes:

- Structured logs remain the v1 foundation.
- Full observability requires aggregation, dashboards or queries, SLOs, alerting, and failure-rate tracking.
- Do not claim dashboards or alerts until they exist.

Definition of done:

- The observability plan distinguishes structured logging from full observability.
- Per-step timing fields and aggregation targets are documented.
- SLO and alert thresholds are proposed before production readiness.

Human verification:

- A reviewer can answer which step is slow in a request, or see that the current system cannot yet answer that fully.

### Feature 14.3 - Latency Budget And Optimization Plan

#### Task 14.3.1 - Define Latency Budget Against Measured VM Baseline

Why: the measured VM A1c path is about 10.693 seconds, which is borderline for a physician with seconds between rooms.

Start with eval/test or documentation proof:

- Record current local and VM latency baselines and define target budgets for demo, v1, and production readiness.

Implementation notes:

- State whether the current VM measurement is accepted for demo only or blocks production readiness.
- Tie optimization work to selective routing, per-step timing, model timeout, evidence size, caching, and infrastructure.

Definition of done:

- Docs include a clear latency budget and current measured baseline.
- The release gate says what latency level blocks production-readiness claims.
- Optimization work is tied to measurable steps rather than vague performance promises.

Human verification:

- A reviewer can see the current 10.693s VM result and the plan to reduce or justify it.

## Epic Final - Demo, Cost Analysis, And Final Packaging

Goal: produce the artifacts needed to defend the system, not just run it.

### Feature Final.1 - Cost Analysis

#### Task Final.1.1 - Capture Actual Development Spend

Why: `SPECS.txt` requires actual dev spend.

Start with eval/test:

- Define the spend fields before collecting: provider, model, dates, input tokens, output tokens, total cost, unknown fields.

Implementation:

- Fill only measured or provider-reported values.
- Label unavailable values as unknown.

Definition of done:

- Actual dev spend is recorded or explicitly unknown.
- No cost number is invented.

Human verification:

- A reviewer can trace every cost number to usage data or see that it is unknown.

#### Task Final.1.2 - Project Production Cost At Required User Levels

Why: `SPECS.txt` requires projections at 100, 1K, 10K, and 100K users plus architecture changes.

Start with eval/test:

- Define projection formula inputs before calculating: requests per user, tokens per request, model price, hosting inputs, logging/storage cost, concurrency inputs, and unknowns.

Implementation:

- Use measured request token data when available.
- If any input is unknown, create a scenario table instead of one false exact number.

Definition of done:

- `COST-ANALYSIS.md` covers 100, 1K, 10K, and 100K users.
- Each scale level includes likely architecture changes.
- Unknowns are labeled.

Human verification:

- A reviewer can explain what would need to change before 300 concurrent clinical users.

### Feature Final.2 - Demo Video

#### Task Final.2.1 - Write Demo Script From Evals

Why: the demo should prove safety behavior, not just show a happy path.

Start with eval/test:

- Create a demo checklist before recording: deployed URL, fake patient, visit briefing, follow-up, citation, missing data, refusal, observability log, and eval result.

Implementation:

- Write a three-to-five-minute demo script that follows the checklist.

Definition of done:

- Script fits within three to five minutes.
- It shows product behavior and key technical decisions.
- It includes one failure or refusal behavior.

Human verification:

- A reviewer can rehearse the script and see each proof point in the live app.

#### Task Final.2.2 - Record Submission Demo

Why: every submission requires a demo video.

Start with eval/test:

- Run the demo checklist immediately before recording.

Implementation:

- Record the deployed app, agent behavior, citations, refusal/failure behavior, observability, and eval result.

Definition of done:

- Video is three to five minutes.
- Deployed app is visible.
- The agent works in the live environment for early and final submissions.

Human verification:

- A reviewer can watch the video and understand what was built, why it is trustworthy, and what is still limited.

### Feature Final.3 - Final Submission Checklist

#### Task Final.3.1 - Run Release Gate

Why: final delivery fails if any required artifact is missing.

Start with eval/test:

- Create a release checklist from `SPECS.txt`: repository, setup guide, deployed link, `AUDIT.md`, user doc, `ARCHITECTURE.md`, demo video, eval dataset, cost analysis, deployed app, and final social post.

Implementation:

- Fill the checklist only with verified links, files, or commands.

Definition of done:

- Every final deliverable is present or explicitly marked blocked.
- No item is marked complete without proof.

Human verification:

- A reviewer can follow the checklist and submit without guessing.

#### Task Final.3.2 - Prepare Interview Defense Notes

Why: Austin admission requires interviews after major deliverables.

Start with eval/test:

- Write interview questions from `SPECS.txt` before drafting answers: audit, architecture, evaluation, production thinking, failure modes.

Implementation:

- Draft concise answers grounded in implemented behavior, eval results, and known limitations.

Definition of done:

- Notes answer the required interview prompts.
- Every answer points to a doc, eval, log, demo behavior, or known unknown.

Human verification:

- A reviewer can ask the interview questions and get direct, evidence-backed answers.

#### Task Final.3.3 - Draft Final Social Post

Why: `SPECS.txt` requires a final X or LinkedIn post tagging `@GauntletAI`.

Start with eval/test:

- Define the post checklist before drafting: project name, problem, demo proof point, safety/verification point, deployed/demo media reference, and `@GauntletAI`.

Implementation:

- Draft the final-only post after the demo behavior is known.
- Do not claim production readiness beyond what the demo, evals, and docs prove.

Definition of done:

- Post draft exists.
- It includes `@GauntletAI`.
- It does not include PHI, credentials, private URLs, or unsupported claims.

Human verification:

- A reviewer can read the draft and confirm it accurately describes the submitted project.

## Parallel Execution Rules

The 48-hour critical path at the top overrides the linear backlog order.

- Documentation, deployment verification, fake data planning, and agent skeleton work can run in parallel.
- No real model call may happen before logging, PHI-to-LLM policy, and token/cost capture are in place.
- No patient evidence tool may run before session, patient context, and authorization gate behavior are defined.
- No demo recording waits for every P2 item. Record when the P0/P1 demo path is green.
- If a P0 item slips, cut P2 scope immediately.

## Overall Eval Pass Threshold

- Safety-critical evals must have a 100% pass rate before release.
- Safety-critical evals are authorization, cross-patient leakage, prompt injection, malicious chart text, hallucination blocking, clinical advice refusal, and citation enforcement.
- Demo-path evals must pass for every behavior shown in the video.
- Non-safety eval failures must be documented with scope, impact, and mitigation.
- Any unsupported patient-specific claim reaching final display is a release blocker.

## Release Kill Criteria

Do not submit as complete if any of these are true:

- Required docs are missing.
- Public deployment cannot be reached.
- Fake demo data cannot be verified.
- P0 runtime, LLM, verification, PHI-to-LLM, and latency decisions are not recorded.
- Agent can answer about a patient without patient-specific authorization.
- Agent leaks or answers about another patient through cross-patient prompting.
- Prompt injection or malicious chart text can override safety or authorization rules.
- Agent returns an unsupported patient-specific claim.
- Agent gives diagnosis, treatment, dosing, medication-change, or unsupported clinical-rule advice.
- Missing data or tool failure is hidden.
- Citations are absent from patient-specific facts.
- Request logs cannot show tools, latency, token use, cost estimate, failures, and verifier result.
- First real model call happens before token/cost capture is wired.
- Demo-path verified answer or failure cannot complete inside the latency budget.
- Eval results are missing.
- Any safety-critical eval fails.
- Cost analysis invents unknown numbers instead of labeling them unknown.

## Critical Bottleneck

The bottleneck is proof. Code without proof is not progress for this project. The fastest path is to make every step falsifiable, keep the agent narrow, and refuse anything that cannot be verified.

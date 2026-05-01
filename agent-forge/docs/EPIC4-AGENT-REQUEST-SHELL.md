# Epic: Agent Request Shell

**Scope:** patient-chart request shell and fail-closed authorization  
**Status:** Done (request shell and gate). Full drafting and verification path: see [EPIC_READ_ONLY_EVIDENCE_TOOLS.md](EPIC_READ_ONLY_EVIDENCE_TOOLS.md) and [EPIC_MODEL_DRAFTING_AND_VERIFICATION.md](EPIC_MODEL_DRAFTING_AND_VERIFICATION.md).

---

## Summary

**Original Epic 4 scope (completed):** Build the server-owned request shell before adding model behavior: same-origin POST endpoint, CSRF and session binding, typed parsing, fail-closed patient authorization gate, and PHI-minimized request logging. A **placeholder** handler returned a non-model acknowledgment after authorization passed.

**Current repository state:** Epics 5–6 wired read-only chart evidence tools and a verified drafting pipeline into the same endpoint. [agent_request.php](../../interface/patient_file/summary/agent_request.php) now composes `AgentRequestHandler` with `VerifiedAgentHandler` (default evidence tools, `DraftProviderFactory::createDefault()`, `DraftVerifier`) after the same authorization gate. Refusals still occur before any chart read when the gate fails.

The first-principles constraint is unchanged: a browser panel is useful only after the server proves the current session user, active patient chart, and patient-specific relationship. If any of those facts are missing or unclear, the request is refused before chart tools run.

---

## Integration Checklist

- [x] Patient chart UI entry point: `interface/patient_file/summary/demographics.php`.
- [x] Dashboard card pattern: `src/Patient/Cards/*ViewCard.php` plus `templates/patient/card/*.html.twig`.
- [x] Patient id source: active chart `$pid`, initialized from the OpenEMR session and `set_pid` flow in `demographics.php`.
- [x] Endpoint session patient source: `SessionWrapperFactory::getInstance()->getActiveSession()->get('pid')`.
- [x] Endpoint session user source: `SessionWrapperFactory::getInstance()->getActiveSession()->get('authUserID')`.
- [x] CSRF pattern: server-rendered `CsrfUtils::collectCsrfToken()` and endpoint-side `CsrfUtils::verifyCsrfToken()`.
- [x] Coarse ACL convention: `AclMain::aclCheckCore('patients', 'med')`.
- [x] Patient-specific relationship convention: direct relationship through `patient_data.providerID` or `form_encounter.provider_id/supervisor_id`.

---

## Implemented Request Boundary

- [x] `AgentForgeViewCard` renders a minimal Clinical Co-Pilot card in the patient dashboard.
- [x] `agent_request.php` accepts same-origin POST requests only.
- [x] Request parsing converts untrusted input into typed `PatientId` and `AgentQuestion` objects.
- [x] Empty questions, invalid patient ids, missing session users, missing chart context, patient mismatches, missing medical ACL, missing patient rows, and missing/unclear patient relationships all return structured JSON refusals.
- [x] After authorization passes, the handler delegates to `VerifiedAgentHandler` (evidence tools, draft provider, deterministic verifier). Fixture and eval paths use the same seams without requiring a live model when configured.
- [x] Every endpoint exit records a PHI-minimized sensitive AgentForge request log entry with request id, user id when known, patient id when known, decision, latency, timestamp, and telemetry when present.

---

## Known Authorization Scope Limitations

The patient-specific gate checks coarse `patients/med` ACL first, then requires the active OpenEMR user to have a direct patient relationship through one of these relationship shapes:

- `patient_data.providerID = active_user_id`
- `form_encounter.provider_id = active_user_id`
- `form_encounter.supervisor_id = active_user_id`

The following relationship surfaces are deliberately deferred to later epics: care-team membership through `care_teams` or `care_team_member`, facility-scoped access through `users_facility`, group-based patient assignment, scheduling-based access, and delegation outside `form_encounter.supervisor_id`. Each deferred shape fails closed with `Patient-specific access could not be verified for this user.` rather than allowing chart evidence reads.

This limitation responds to `AUDIT.md` Security S1: OpenEMR's coarse ACL checks are capability-oriented and do not answer the patient-resource question, "may this user access this patient?"

---

## Acceptance Traceability

| Requirement | Proof |
| --- | --- |
| Authorized requests log request id, user/patient context, decision, latency, telemetry, and pass/fail status. | `RequestLog`, `RequestLogger`, `PsrRequestLogger`, and `agent_request.php` log-and-respond helper. |
| Authorization result is logged without raw PHI in default context. | `RequestLog::toContext()` avoids question/answer text; extended telemetry is sanitized per observability epic. |
| Logging can be tested without a live OpenEMR request. | `tests/Tests/Isolated/AgentForge/RequestLogTest.php` uses a recording PSR logger. Run: `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'`. |
| Unexpected endpoint failures do not leak internal exception messages. | `agent_request.php` catches modeled `DomainException` validation failures separately from unexpected `Throwable`; unexpected failures are logged internally and return `AgentResponse::unexpectedFailure()`. |
| Endpoint orchestration can be tested without a live OpenEMR session. | `AgentRequestHandler` owns method, CSRF, parser, authorization, and downstream handler invocation; `agent_request.php` is glue for OpenEMR session/CSRF/logger wiring. |
| Endpoint refusal matrix is represented as isolated tests. | `tests/Tests/Isolated/AgentForge/AgentRequestHandlerTest.php` covers non-POST, bad CSRF, missing patient id, empty question, missing session user, missing chart context, mismatch, missing ACL, unverified patient, missing relationship, unclear repository state, unexpected parser failure, and allowed request. |
| Verified agent path is tested in isolation. | `tests/Tests/Isolated/AgentForge/VerifiedAgentHandlerTest.php` and related draft/evidence tests. |

---

## Verification Notes

- [x] PHP syntax check passed for `RequestLog`, `RequestLogger`, `PsrRequestLogger`, `RequestLogTest`, and `agent_request.php`.
- [x] Direct PHP smoke assertion confirmed `PsrRequestLogger` emits one PHI-minimized sensitive context without `question`.
- [x] Direct PHP smoke assertion confirmed `AgentResponse::unexpectedFailure()` does not expose an internal SQL-style message.
- [x] PHP syntax check passed for `AgentRequestHandler`, `AgentRequestResult`, `AgentRequestParserInterface`, and `AgentRequestHandlerTest`.
- [x] Isolated suite: `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'` — see `agent-forge/docs/epic4-phpunit-output.txt` for a captured run (29 tests / 103 assertions at time of capture; counts may grow).
- [x] Endpoint JSON output is protected from accidental prior output by buffering before `globals.php` and cleaning before emitting the JSON response.

---

## Manual Verification Checklist

- [x] Open a fake patient chart and confirm the Clinical Co-Pilot panel renders in the dashboard.
  - Observed in local OpenEMR on Alex Testpatient, `AF-DEMO-900001`, patient id `900001`.
  - The `Clinical Co-Pilot` card rendered on the Medical Record Dashboard below the prescriptions card.
- [x] Submit an empty question and confirm the UI shows a visible warning without sending a useful request.
  - Observed warning text: `Enter a question before sending.`
- [x] Submit a chart-orientation question on an authorized chart and confirm a **verified** JSON response (`status: ok`) with an `answer` grounded in seeded demo data (not the historical placeholder string). Requires OpenAI credentials or fixture-driven local setup per [EPIC_MODEL_DRAFTING_AND_VERIFICATION.md](EPIC_MODEL_DRAFTING_AND_VERIFICATION.md).
- [x] Post a mismatched `patient_id` to `interface/patient_file/summary/agent_request.php` and confirm a structured refusal.
  - Browser console `fetch` to `patient_id=900002` returned HTTP `403`.
  - Observed JSON refusal: `The requested patient does not match the active chart.`
- [x] Use a user without a direct `patient_data.providerID` or encounter relationship and confirm authorization refuses before any answer.
  - Observed with seeded user `af_demo_unrelated`.
  - Seed verification: `af_demo_unrelated` is active, authorized, in OpenEMR group `Default`, and mapped to phpGACL group `11`.
  - Relationship verification: patient id `900001` remains linked to provider id `1` through `patient_data.providerID` and `form_encounter.provider_id`; `form_encounter.supervisor_id` is `0`.
  - Observed refusal text: `Patient-specific access could not be verified for this user.`

### Historical note: Epic 4 placeholder era

Through early VM proof, an authorized request returned:  
`AgentForge request shell received your question for patient 900001. Model behavior and chart evidence retrieval are intentionally disabled in Epic 4. This is a non-model placeholder response.`  
That string is **obsolete**; the current endpoint runs the verified agent path after the same gate.

---

## Deployed VM Verification

**Historical (commit `95bf383a7c453c3d6538b7be85ef43123d41840a`):** Deploy, seed, authorized **placeholder** response, and no-relationship refusal were verified on the public VM.

**Current expectation:** After deploy with valid model configuration (see [EPIC2-DEPLOYMENT-RUNTIME-PROOF.md](EPIC2-DEPLOYMENT-RUNTIME-PROOF.md) and `deploy-vm.sh` OpenAI key precondition), repeat manual checks above on the deployed app: authorized user should receive verified answers for demo questions; `af_demo_unrelated` should still receive the same refusal. Re-capture transcripts when convenient for reviewer evidence.

---

## Change Log

- Request logging contract added: `RequestLog`, `RequestLogger`, `PsrRequestLogger`, endpoint log-and-respond helper, and isolated logger tests.
- Exception handling tightened: expected parser validation failures return safe domain messages; unexpected failures are logged and return a generic refusal.
- Endpoint orchestration extracted into `AgentRequestHandler` so refusal/status-code behavior can be covered by isolated tests.
- Evidence tools and `VerifiedAgentHandler` wired into `agent_request.php` (Epics 5–6); placeholder path removed from production endpoint.
- Local proof: AgentForge isolated PHPUnit suite; JSON output path hardened; seeded no-relationship user verified.
- Deployed VM: initial proof used placeholder responses; documentation updated to reflect verified pipeline (re-verify on VM for updated transcripts).

---

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes.
- Required automated tests executed and captured? yes — `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'`; see `agent-forge/docs/epic4-phpunit-output.txt`.
- Required local manual checks executed and captured? yes (update narrative when re-running against verified path only).
- Required fixtures/data/users for local proof exist? yes, `af_demo_unrelated` is seeded for the no-relationship refusal path.
- Security/privacy/logging/error-handling requirements verified locally? yes.
- Known limitations and deferred relationship/scope shapes documented? yes.
- Epic 4 shell and gate: **DONE-done.** Full agent behavior: tracked in Epics 5–6 and `ARCHITECTURE.md`.

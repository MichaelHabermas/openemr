# Epic: Agent Request Shell

**Scope:** patient-chart request shell and fail-closed authorization
**Status:** Done

---

## Summary

Epic 4 builds only the server-owned request shell for the Clinical Co-Pilot. It does not call a model, read chart evidence, create logging tables, or verify generated claims. Those behaviors remain deferred to later epics.

The first-principles constraint is trust: a browser panel is useful only after the server proves the current session user, active patient chart, and patient-specific relationship. If any of those facts are missing or unclear, the request is refused before future chart tools could run.

---

## Integration Checklist

- [x] Patient chart UI entry point: `interface/patient_file/summary/demographics.php`.
- [x] Dashboard card pattern: `src/Patient/Cards/*ViewCard.php` plus `templates/patient/card/*.html.twig`.
- [x] Patient id source: active chart `$pid`, initialized from the OpenEMR session and `set_pid` flow in `demographics.php`.
- [x] Endpoint session patient source: `SessionWrapperFactory::getInstance()->getActiveSession()->get('pid')`.
- [x] Endpoint session user source: `SessionWrapperFactory::getInstance()->getActiveSession()->get('authUserID')`.
- [x] CSRF pattern: server-rendered `CsrfUtils::collectCsrfToken()` and endpoint-side `CsrfUtils::verifyCsrfToken()`.
- [x] Coarse ACL convention: `AclMain::aclCheckCore('patients', 'med')`.
- [x] Patient-specific relationship convention for Epic 4: direct relationship through `patient_data.providerID` or `form_encounter.provider_id/supervisor_id`.

---

## Implemented Request Boundary

- [x] `AgentForgeViewCard` renders a minimal Clinical Co-Pilot card in the patient dashboard.
- [x] `agent_request.php` accepts same-origin POST requests only.
- [x] Request parsing converts untrusted input into typed `PatientId` and `AgentQuestion` objects.
- [x] Empty questions, invalid patient ids, missing session users, missing chart context, patient mismatches, missing medical ACL, missing patient rows, and missing/unclear patient relationships all return structured JSON refusals.
- [x] The placeholder handler returns a non-model response only after authorization passes.
- [x] Every endpoint exit records a PHI-free AgentForge request log entry with request id, user id when known, patient id when known, decision, latency, and timestamp.

---

## Known Authorization Scope Limitations

Epic 4 intentionally implements the narrowest demonstrable patient-specific gate. It checks coarse `patients/med` ACL first, then requires the active OpenEMR user to have a direct patient relationship through one of these relationship shapes:

- `patient_data.providerID = active_user_id`
- `form_encounter.provider_id = active_user_id`
- `form_encounter.supervisor_id = active_user_id`

The following relationship surfaces are deliberately deferred to later epics: care-team membership through `care_teams` or `care_team_member`, facility-scoped access through `users_facility`, group-based patient assignment, scheduling-based access, and delegation outside `form_encounter.supervisor_id`. In Epic 4, each deferred shape fails closed with `Patient-specific access could not be verified for this user.` rather than allowing chart evidence reads.

This limitation responds to `AUDIT.md` Security S1: OpenEMR's coarse ACL checks are capability-oriented and do not answer the patient-resource question, "may this user access this patient?"

---

## Acceptance Traceability

| Requirement | Proof |
| --- | --- |
| Placeholder request logs request id, user context result, patient context result, latency, and pass/fail status. | `RequestLog`, `RequestLogger`, `PsrRequestLogger`, and `agent_request.php` log-and-respond helper. |
| Authorization result is logged without raw PHI. | `RequestLog::toContext()` contains only request id, numeric ids, decision, latency, and timestamp; no question or answer text. |
| Logging can be tested without a live OpenEMR request. | `tests/Tests/Isolated/AgentForge/RequestLogTest.php` uses a recording PSR logger. Local PHPUnit run is blocked because `phpunit` is not installed in this checkout. |
| Unexpected endpoint failures do not leak internal exception messages. | `agent_request.php` catches modeled `DomainException` validation failures separately from unexpected `Throwable`; unexpected failures are logged internally and return `AgentResponse::unexpectedFailure()`. |
| Endpoint orchestration can be tested without a live OpenEMR session. | `AgentRequestHandler` owns method, CSRF, parser, authorization, and placeholder decisions; `agent_request.php` is glue for OpenEMR session/CSRF/logger wiring. |
| Endpoint refusal matrix is represented as isolated tests. | `tests/Tests/Isolated/AgentForge/AgentRequestHandlerTest.php` covers non-POST, bad CSRF, missing patient id, empty question, missing session user, missing chart context, mismatch, missing ACL, unverified patient, missing relationship, unclear repository state, unexpected parser failure, and allowed request. |

---

## Verification Notes

- [x] PHP syntax check passed for `RequestLog`, `RequestLogger`, `PsrRequestLogger`, `RequestLogTest`, and `agent_request.php`.
- [x] Direct PHP smoke assertion confirmed `PsrRequestLogger` emits one PHI-free context without `question`.
- [x] Direct PHP smoke assertion confirmed `AgentResponse::unexpectedFailure()` does not expose an internal SQL-style message.
- [x] PHP syntax check passed for `AgentRequestHandler`, `AgentRequestResult`, `AgentRequestParserInterface`, and `AgentRequestHandlerTest`.
- [x] Direct PHP smoke assertion confirmed `AgentRequestHandler` allows an authorized POST and returns the placeholder patient response.
- [x] PHPUnit proof captured in `agent-forge/docs/epic4-phpunit-output.txt`: `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'` passed with 29 tests and 103 assertions.
- [x] Endpoint JSON output is protected from accidental prior output by buffering before `globals.php` and cleaning before emitting the JSON response.

---

## Manual Verification Checklist

- [x] Open a fake patient chart and confirm the Clinical Co-Pilot panel renders in the dashboard.
  - Observed in local OpenEMR on Alex Testpatient, `AF-DEMO-900001`, patient id `900001`.
  - The `Clinical Co-Pilot` card rendered on the Medical Record Dashboard below the prescriptions card.
- [x] Submit an empty question and confirm the UI shows a visible warning without sending a useful request.
  - Observed warning text: `Enter a question before sending.`
- [x] Submit "What changed since last visit?" on an authorized chart and confirm a placeholder response names the active patient id.
  - Observed response: `AgentForge request shell received your question for patient 900001. Model behavior and chart evidence retrieval are intentionally disabled in Epic 4. This is a non-model placeholder response.`
- [x] Post a mismatched `patient_id` to `interface/patient_file/summary/agent_request.php` and confirm a structured refusal.
  - Browser console `fetch` to `patient_id=900002` returned HTTP `403`.
  - Observed JSON refusal: `The requested patient does not match the active chart.`
- [x] Use a user without a direct `patient_data.providerID` or encounter relationship and confirm authorization refuses before any answer.
  - Observed with seeded user `af_demo_unrelated`.
  - Seed verification: `af_demo_unrelated` is active, authorized, in OpenEMR group `Default`, and mapped to phpGACL group `11`.
  - Relationship verification: patient id `900001` remains linked to provider id `1` through `patient_data.providerID` and `form_encounter.provider_id`; `form_encounter.supervisor_id` is `0`.
  - Observed refusal text: `Patient-specific access could not be verified for this user.`

---

## Deployed VM Verification

- [x] VM deploy and seed completed on `master` at commit `95bf383a7c453c3d6538b7be85ef43123d41840a`.
  - Public readiness endpoint returned HTTP `200`.
  - Deploy ran `agent-forge/scripts/seed-demo-data.sh`.
  - Seed output: `PASS seed: fake demo patient pid=900001 loaded.`
  - Deploy output: `Deploy succeeded.`
- [x] Deployed authorized request shell path returned the Epic 4 placeholder response.
  - User: `admin`.
  - Patient: `AF-DEMO-900001`, patient id `900001`.
  - Observed response: `AgentForge request shell received your question for patient 900001. Model behavior and chart evidence retrieval are intentionally disabled in Epic 4. This is a non-model placeholder response.`
- [x] Deployed no-relationship refusal path failed closed.
  - User: `af_demo_unrelated`.
  - Patient: `AF-DEMO-900001`, patient id `900001`.
  - Observed refusal: `Patient-specific access could not be verified for this user.`

---

## Change Log

- Request logging contract added: `RequestLog`, `RequestLogger`, `PsrRequestLogger`, endpoint log-and-respond helper, and isolated logger tests.
- Exception handling tightened: expected parser validation failures return safe domain messages; unexpected failures are logged and return a generic refusal.
- Endpoint orchestration extracted into `AgentRequestHandler` so refusal/status-code behavior can be covered by isolated tests.
- Local proof blockers closed: AgentForge isolated PHPUnit suite passed, JSON output path hardened, seeded no-relationship user verified, and all local manual checks passed.
- Deployed VM proof closed: deploy, seed, authorized placeholder response, and no-relationship refusal were verified on the public VM.

---

## Definition Of Done Gate

Can I call this DONE-done?

- Source criteria mapped to code/proof/deferral? yes.
- Required automated tests executed and captured? yes, locally in `agent-forge/docs/epic4-phpunit-output.txt`.
- Required local manual checks executed and captured? yes.
- Required fixtures/data/users for local proof exist? yes, `af_demo_unrelated` is seeded for the no-relationship refusal path.
- Security/privacy/logging/error-handling requirements verified locally? yes.
- Known limitations and deferred relationship/scope shapes documented? yes.
- Epic status updated honestly? yes.
- Git left unstaged and uncommitted unless user asked otherwise? yes.
- Deployed VM verification executed and captured? yes.

Epic 4 is DONE-done: local proof and deployed VM proof are both captured.

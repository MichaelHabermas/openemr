# Epic: Agent Request Shell

**Scope:** patient-chart request shell and fail-closed authorization
**Status:** In Progress

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
- [ ] PHPUnit proof pending. Attempted `composer phpunit-isolated -- tests/Tests/Isolated/AgentForge/RequestLogTest.php` and `composer phpunit-isolated -- tests/Tests/Isolated/AgentForge/AgentRequestHandlerTest.php`; both failed with `phpunit: command not found`.

---

## Manual Verification Checklist

- [ ] Open a fake patient chart and confirm the Clinical Co-Pilot panel renders in the dashboard.
- [ ] Submit an empty question and confirm the UI shows a visible warning without sending a useful request.
- [ ] Submit "What changed since last visit?" on an authorized chart and confirm a placeholder response names the active patient id.
- [ ] Post a mismatched `patient_id` to `interface/patient_file/summary/agent_request.php` and confirm a structured refusal.
- [ ] Use a user without a direct `patient_data.providerID` or encounter relationship and confirm authorization refuses before any answer.

---

## Change Log

- Request logging contract added: `RequestLog`, `RequestLogger`, `PsrRequestLogger`, endpoint log-and-respond helper, and isolated logger tests.
- Exception handling tightened: expected parser validation failures return safe domain messages; unexpected failures are logged and return a generic refusal.
- Endpoint orchestration extracted into `AgentRequestHandler` so refusal/status-code behavior can be covered by isolated tests.

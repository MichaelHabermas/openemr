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

---

## Manual Verification Checklist

- [ ] Open a fake patient chart and confirm the Clinical Co-Pilot panel renders in the dashboard.
- [ ] Submit an empty question and confirm the UI shows a visible warning without sending a useful request.
- [ ] Submit "What changed since last visit?" on an authorized chart and confirm a placeholder response names the active patient id.
- [ ] Post a mismatched `patient_id` to `interface/patient_file/summary/agent_request.php` and confirm a structured refusal.
- [ ] Use a user without a direct `patient_data.providerID` or encounter relationship and confirm authorization refuses before any answer.

---

## Commit Log

_Commits will be logged here as tasks complete._

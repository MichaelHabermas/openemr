# Week 2 Acceptance Matrix

Last updated: 2026-05-06.

This file tracks Week 2 reviewer-facing acceptance evidence that is easy to lose across epic files.

| Area | Current evidence | Remaining gap |
| --- | --- | --- |
| Visual source review | `SourceDocumentAccessGate`, `DocumentCitationReviewService`, `agent_document_source_review.php`, and chart-panel JSON source review return cited page/section, quote/value, optional bounding box, and guarded document URL. Focused H2 tests passed: 36 tests / 238 assertions. | Browser proof of the reviewer-facing flow is pending. |
| Bounding-box review | Document citations with normalized `{x, y, width, height}` render an overlay in the Clinical Co-Pilot panel. | Full PDF.js-style page rendering is intentionally out of scope for H2. |
| No-box fallback | Citations without bounding boxes still open source review and display stored page/section plus quote/value with the guarded source-document link. | Browser proof is pending. |
| Deleted/deactivated exclusion | Source review and evidence SQL exclude deleted source documents and `documents.activity=0`; Zend document deactivation dispatches `DocumentRetractionHook`. Clinical document gate passed with artifact `agent-forge/eval-results/clinical-document-20260506-213410`; comprehensive AgentForge gate passed with artifacts `agent-forge/eval-results/eval-results-20260506-213643.json` and `agent-forge/eval-results/clinical-document-20260506-213715`. | Manual/browser deletion proof is pending. |
| No-PHI telemetry | Quote/value may exist in clinical DB payloads for authorized review, but should not be written to telemetry or logs. | Continue checking `no_phi_in_logs` in the clinical document gate. |

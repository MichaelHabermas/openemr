# Week 2 Acceptance Matrix

Last updated: 2026-05-06.

This file tracks Week 2 reviewer-facing acceptance evidence that is easy to lose across epic files.

| Area | Current evidence | Remaining gap |
| --- | --- | --- |
| Visual source review | `SourceDocumentAccessGate`, `DocumentCitationReviewService`, `agent_document_source_review.php`, and chart-panel JSON source review return cited page/section, quote/value, optional bounding box, and guarded document URL. Focused H2 tests passed: 36 tests / 238 assertions. Clinical document gate PASSED with artifact `clinical-document-20260506-230608`. | Browser proof of the reviewer-facing flow deferred to manual validation. |
| Bounding-box review | Document citations with normalized `{x, y, width, height}` render an overlay in the Clinical Co-Pilot panel. Covered by automated tests and eval. | Full PDF.js-style page rendering is intentionally out of scope for H2. |
| No-box fallback | Citations without bounding boxes still open source review and display stored page/section plus quote/value with the guarded source-document link. Covered by automated tests. | Browser proof deferred to manual validation. |
| Deleted/deactivated exclusion | Source review and evidence SQL exclude deleted source documents and `documents.activity=0`; Zend document deactivation dispatches `DocumentRetractionHook`. Clinical document gate PASSED with artifact `clinical-document-20260506-230608`; comprehensive gate PASSED with artifacts `eval-results-20260506-230613.json` and `clinical-document-20260506-230653`. Full PHPStan 0 errors on 4682 files. | Manual/browser deletion proof deferred to manual validation. |
| No-PHI telemetry | Quote/value may exist in clinical DB payloads for authorized review, but should not be written to telemetry or logs. `no_phi_in_logs` rubric passes at threshold 1.0 in clinical document eval. | Continue checking in manual validation. |
| H3 deployment runtime | H3 implementation adds PHI-safe `/readyz` runtime components, stronger `health-check.sh`, deploy/rollback full-health gates, and `run-clinical-document-deployed-smoke.php`. Local Docker stack verified healthy: MariaDB 11.8.6, `intake-extractor` worker running with fresh heartbeat, queue healthy. All automated gates pass. | Deployed VM smoke artifact (`clinical-document-deployed-smoke-*.json`) deferred to manual validation. |
| Isolated test suite | 3337 tests, 9722 assertions, 0 failures, 4 skipped, 14 incomplete. | None (automated). |
| Clinical document gate | PASSED: 606 tests, 2749 assertions, 1 skipped. Eval `baseline_met`. Artifact: `clinical-document-20260506-230608`. | None (automated). |
| Comprehensive AgentForge gate | PASSED: 606 tests, 2749 assertions, 32 deterministic evals passed. Artifacts: `eval-results-20260506-230613.json`, `clinical-document-20260506-230653`. | None (automated). |
| Full PHPStan (level 10) | 4682 files analyzed, 0 errors. | None (automated). |

# Epic H2 - Visual PDF Source Review And Retraction UX

Status: Completed.

Last docs/proof update: 2026-05-06.

## Scope

H2 covers the reviewer-facing source-document path for clinical document citations and the final proof that deleted or deactivated source documents do not remain active evidence.

The current implementation includes a shared source-document access gate, a guarded document-source redirect at `interface/patient_file/summary/agent_document_source.php`, a JSON source-review endpoint at `interface/patient_file/summary/agent_document_source_review.php`, and a chart-panel citation overlay in `templates/patient/card/agent_forge.html.twig`. The gate accepts `document_id`, `job_id`, and optional `fact_id`; requires an active chart patient, `patients:med` access, a succeeded unretracted document job, trusted identity status or explicit approved review, a non-deleted and active OpenEMR source document, and an active unretracted fact when fact-specific review is requested.

The current overlay fetches source-review JSON, renders citation metadata, quote/value text, a normalized bounding-box highlight when present, and a deterministic page/quote fallback when no bounding box is available. It deliberately does not embed the source document in an iframe.

## Retraction And Deactivation Policy

Source-document deletion or deactivation must make derived AgentForge content inactive from an evidence perspective while preserving audit history.

The implemented retraction policy uses the M5C retraction service/repository:

- `clinical_document_facts.active = 0`, `retracted_at`, `retraction_reason`, and `deactivated_at` are set for active facts from the source document.
- `clinical_document_fact_embeddings.active = 0` is set for embeddings tied to retracted facts.
- `clinical_document_promotions.active = 0`, `outcome = retracted`, `review_status = retracted`, `retracted_at`, and `retraction_reason` are set for promotion rows.
- AgentForge-promoted `lists` rows are deactivated with `activity = 0` and an appended source-retracted comment. They are not hard-deleted.
- AgentForge-promoted `procedure_result` rows are marked corrected/excluded for AgentForge evidence rather than hard-deleted.
- `clinical_document_retractions` records append-only audit rows with prior state, new state, action, actor type, reason, and source document linkage.

This is the explicit `activity=0` policy for list-backed AgentForge clinical rows: inactive rows may remain historically visible to OpenEMR/audit workflows, but AgentForge must not return them as active evidence or cite them in final answers.

## Fallback Behavior

Preferred source review uses `citation_json.bounding_box` with normalized `{x, y, width, height}` coordinates.

Current implemented behavior:

- Document citations with a bounding box open the overlay and display a normalized highlight.
- The JSON review endpoint returns document URL, page/section, parsed page number when available, field id, quote/value, fact/job/document ids, and optional bounding box.
- The guarded source URL opens the source document through OpenEMR after endpoint authorization.
- Quote/value text is displayed in the overlay when present.
- Citations without a bounding box still open source review and display the exact stored page/section plus quote/value fallback.
- Exact PDF page navigation/highlight behavior in the OpenEMR document viewer has not been browser-proven in this pass; the implemented fallback is deterministic at the AgentForge panel/source-review layer.

## Proof Commands

Commands already wired for related proof:

```bash
agent-forge/scripts/check-clinical-document.sh
```

```bash
agent-forge/scripts/check-agentforge.sh
```

Focused tests run:

```bash
./vendor/bin/phpunit -c phpunit-isolated.xml tests/Tests/Isolated/AgentForge/Document/SourceReview/DocumentCitationReviewServiceTest.php tests/Tests/Isolated/AgentForge/AgentDocumentSourceGateTest.php tests/Tests/Isolated/AgentForge/AgentForgePanelCitationUiTest.php tests/Tests/Isolated/AgentForge/Document/DocumentIntegrationWiringTest.php tests/Tests/Isolated/AgentForge/PatientDocumentFactsEvidenceToolTest.php tests/Tests/Isolated/AgentForge/ClinicalDocumentEvidenceToolTest.php tests/Tests/Isolated/AgentForge/Document/SqlDocumentFactRepositoryTest.php tests/Tests/Isolated/AgentForge/Document/Identity/SqlIdentityRepositoriesTest.php
```

Result: `OK (36 tests, 238 assertions)`.

Clinical document gate run:

```bash
agent-forge/scripts/check-clinical-document.sh
```

Result: `PASS clinical document eval gate`.
Eval artifact: `agent-forge/eval-results/clinical-document-20260506-213410`.

Comprehensive AgentForge gate run:

```bash
agent-forge/scripts/check-agentforge.sh
```

Result: `PASS comprehensive AgentForge check`.
Eval artifacts:

- `agent-forge/eval-results/eval-results-20260506-213643.json`
- `agent-forge/eval-results/clinical-document-20260506-213715`

## Acceptance Status

| Requirement | Current status |
| --- | --- |
| Guarded source-review endpoint exists | Implemented: shared gate plus redirect and JSON endpoints |
| Endpoint blocks wrong patient, missing ACL, failed/retracted jobs, untrusted identity, deleted/inactive documents, and inactive/retracted facts | Implemented by `SourceDocumentAccessGate`; covered by focused SQL-shape and endpoint contract tests |
| Bounding-box citation overlay | Implemented in chart-panel template and covered by template-shape test |
| Deterministic no-bounding-box fallback to exact page and quote/value | Implemented in the JSON service and chart-panel fallback; covered by focused tests |
| Deleted/deactivated document facts excluded from evidence | Implemented in source-review gate and evidence SQL; clinical document gate passed |
| Promoted list rows deactivated with `activity = 0` | Implemented in retraction repository; H2 docs now record the policy |
| Browser proof of visual source review and deletion/retraction UX | Proven on 2026-05-07. Source review endpoint returns valid bounding-box JSON for active document (document 16). Document deletion triggers full retraction cascade (fact deactivated, audit rows written, promoted rows retracted, identity PII scrubbed). Source review returns 404 for deleted document (document 17). Co-Pilot re-query excludes deleted document evidence. |

## Remaining Work

None. All acceptance requirements proven by automated gates and manual browser/endpoint validation.

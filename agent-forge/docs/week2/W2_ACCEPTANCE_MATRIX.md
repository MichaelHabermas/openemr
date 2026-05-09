# Week 2 Acceptance Matrix

Last updated: 2026-05-09 (Epic 10 per-format acceptance rows).

This file is the reviewer-facing map from Week 2 requirements to current proof.
It intentionally distinguishes checked-in proof from rerunnable commands and
explicit gaps.

## Current Proof Artifacts

| Artifact | What it proves |
| --- | --- |
| `agent-forge/eval-results/clinical-document-20260508-190800/summary.json` | Latest local 65-case clinical-document gate summary; verdict `baseline_met`. |
| `agent-forge/eval-results/clinical-document-20260508-190800/run.json` | Case-level extraction, citation, guideline, refusal, no-PHI, bounding-box, deletion/retraction, promotion, document-fact proof, and Epic 1 multi-format contract coverage. |
| `agent-forge/eval-results/eval-results-20260508-161500.json` | Latest local Tier 0 deterministic orchestration proof; 32 passed, 0 failed. |
| [tier2-live-20260503-202550.json](../../eval-results/tier2-live-20260503-202550.json) | Live provider Week 1/AgentForge baseline proof and available provider spend baseline. |
| [deployed-smoke-20260503-201547.json](../../eval-results/deployed-smoke-20260503-201547.json) | Deployed HTTP/session/audit smoke for the baseline AgentForge path. |
| [eval-results/](../../eval-results/) | Deployed clinical-document smoke proof directory; rerun the smoke script to generate a fresh artifact. |
| [browser-proof/MANIFEST.md](../submission/browser-proof/MANIFEST.md) | Browser proof screenshot manifest, including deployed source/citation UI evidence. |
| [CLINICAL-DOCUMENT-COST-LATENCY.md](../operations/CLINICAL-DOCUMENT-COST-LATENCY.md) | Week 2 cost/latency report rendered from current proof artifacts. |

Checked-in deployed clinical-document smoke artifacts are present under
`agent-forge/eval-results/`. The smoke command remains rerunnable when reviewer
deployment credentials are available.

## Acceptance Map

| Area | Current evidence | Remaining gap |
| --- | --- | --- |
| Lab PDF ingestion and strict extraction | Clinical gate artifact `clinical-document-20260508-190800` includes `lab_pdf` schema, citation, factual consistency, promotion, duplicate, and retraction cases. | None for deterministic/local gate. |
| Intake form ingestion and strict extraction | Clinical gate artifact `clinical-document-20260508-190800` includes `intake_form` schema, citation, document fact, needs-review, and identity-gating cases. | None for deterministic/local gate. |
| Multi-format contract and category mapping | `clinical-document-20260508-190800` includes DOCX, XLSX, TIFF, and HL7 v2 cases; Epic 2 maps `Referral Document`, `Clinical Workbook`, `Fax Packet`, and `HL7 v2 Message` categories. All four non-PDF formats now have bounded runtime support. | None for local gate. |
| DOCX referral ingestion | Bounded runtime via `DocxDocumentContentNormalizer` (OOXML text/table extraction). Golden cases cover schema, citation, document facts. Section/paragraph/table citations. | No chart promotion, no DOCX preview endpoint, no medication reconciliation from referrals. |
| XLSX workbook ingestion | Bounded runtime via `XlsxDocumentContentNormalizer` (PhpSpreadsheet sheet/table normalization). Golden cases cover schema, citation, document facts. Sheet/cell/range citations. | No chart promotion, no XLSX preview endpoint, no formula evaluation. |
| TIFF fax ingestion | Bounded runtime via `TiffDocumentContentNormalizer` (page rendering to PNG). Golden cases cover schema, citation, document facts. Page-level citations. | No chart promotion, no packet splitting into child documents. |
| HL7 v2 message ingestion | Deterministic runtime via `Hl7v2DocumentContentNormalizer` for ADT^A08 and ORU^R01 shapes. Golden cases cover schema, citation, document facts. Segment/field citations. No model call required. | Only ADT^A08 and ORU^R01 supported; other shapes fail closed. No chart promotion of OBX observations. |
| Source document storage before derived facts | M2-M5 implementation and eval cases prove OpenEMR source document references, job/fact provenance, and source-document deletion behavior. | None for local proof. |
| Duplicate prevention and provenance | Clinical gate artifact covers duplicate upload and promotion expectations; promotion rows carry document/job/fact/citation provenance. | None for local proof. |
| Wrong-patient and ambiguous identity safeguards | Identity checks gate active facts, embeddings, promotions, and evidence retrieval unless identity is verified or explicitly approved. | None for local proof. |
| Hybrid guideline RAG and rerank | Guideline retrieval cases in `clinical-document-20260508-190800/run.json` use the checked-in guideline corpus, sparse+dense retrieval, deterministic rerank, and out-of-corpus refusal. | Live Cohere rerank depends on `AGENTFORGE_COHERE_API_KEY`; deterministic rerank is the committed gate path. |
| Supervisor and required workers | `intake-extractor` is the only document-job claimer; `supervisor` records routing decisions through `SupervisorRuntime` and `clinical_supervisor_handoffs`; `evidence-retriever` is used for answer-time evidence retrieval rather than fake document-job processing. Focused local proof: `DocumentJobWorkerFactoryProcessorTest`, `SqlJobClaimerTest`, `ProcessDocumentJobsScriptShapeTest`, `SupervisorRuntimeTest`, and `VerifiedAgentHandlerTest`. | None for local proof. |
| Visual source review and bounding boxes | `DocumentCitationNormalizer`, `SourceDocumentAccessGate`, `DocumentCitationReviewService`, `agent_document_source_review.php`, clinical gate bounding-box rubrics, and browser proof show guarded citation review with page/section, quote/value, strict normalized bounding boxes, and fallback. Invalid boxes, including `x + width > 1` or `y + height > 1`, are rejected. | Full PDF.js-style page rendering is intentionally out of scope. |
| Deleted/deactivated source exclusion | Retraction tests and clinical gate cases prove deleted source content is excluded from active facts, embeddings, source review, final-answer evidence, and promoted-lab evidence. Manual proof on 2026-05-07 verified deletion reduced Co-Pilot citations and source review denied deleted content. | None for local proof. |
| No-PHI telemetry | `no_phi_in_logs` rubric passes at threshold `1.0`; `SensitiveLogPolicy` forbids raw document text, quote/value, extracted fields, images, and patient identifiers in logs. | Continue manual review for any newly captured screenshots or ad hoc logs. |
| Eval dataset and boolean rubrics | 65 checked-in clinical-document golden cases; required rubrics are boolean and runner-enforced. `summary.json` verdict is `baseline_met`. | None. |
| CI/Git hook style gate | `agent-forge/scripts/check-clinical-document.sh` is the Week 2 gate; `agent-forge/scripts/check-agentforge.sh` is the comprehensive AgentForge gate. | External CI wiring should still be verified in the hosting platform before final release claims. |
| Deployment runtime and health | H3 implementation starts `agentforge-worker`, checks MariaDB 11.8, worker heartbeat, queue health, deploy/rollback gates, and PHI-safe `/readyz`. Manual VM verification on 2026-05-07 reported health PASS, demo-data checks PASS, clinical-document deployed smoke 1/1 PASS, and UI citation/retraction verification. Latest checked-in deployed clinical smoke artifact: `clinical-document-deployed-smoke-20260508-001525.json`. | Rerun `php agent-forge/scripts/run-clinical-document-deployed-smoke.php` when credentials are available to refresh deployed proof. |
| Cost and latency report | [CLINICAL-DOCUMENT-COST-LATENCY.md](../operations/CLINICAL-DOCUMENT-COST-LATENCY.md) is rendered from clinical-document proof, Tier 2 live baseline, and deployed smoke baseline. Unknown clinical-document model cost is labeled instead of invented. | Live clinical-document model cost/deployed latency remain limited unless a live clinical-document artifact is captured. |
| Reviewer documentation | Root [README.md](../../../README.md), [AGENTFORGE-REVIEWER-GUIDE.md](../../../AGENTFORGE-REVIEWER-GUIDE.md), this matrix, and [README.md](README.md) separate Week 1 from Week 2 and link commands, env vars, artifacts, and caveats. | None after H5 docs tests pass. |

## Rerun Commands

```bash
agent-forge/scripts/check-clinical-document.sh
agent-forge/scripts/check-agentforge.sh
agent-forge/scripts/health-check.sh
agent-forge/scripts/verify-deployed.sh
php agent-forge/scripts/run-clinical-document-deployed-smoke.php
```

The deployed clinical smoke command requires reviewer/deployment credentials and
environment variables documented in [../../../AGENTFORGE-REVIEWER-GUIDE.md](../../../AGENTFORGE-REVIEWER-GUIDE.md).

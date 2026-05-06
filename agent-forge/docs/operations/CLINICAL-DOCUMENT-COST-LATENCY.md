# Week 2 Clinical Document Cost And Latency

**Updated:** 2026-05-06
**Scope:** Week 2 clinical-document checkpoint path: strict document extraction, guideline retrieval, supervisor handoffs, no-PHI logging, and deterministic eval artifacts.
**Status:** Concise reviewer report from existing telemetry and proof. This is not a production SLO or final 50-case submission benchmark.

## Executive Summary

The current Week 2 clinical-document proof is deterministic checkpoint evidence, not deployed production telemetry. The latest checked-in run, `agent-forge/eval-results/clinical-document-20260506-045203/`, covers 9 synthetic cases across lab PDFs, intake forms, duplicate upload, guideline retrieval, PHI-log trap, unsafe-advice refusal, and out-of-corpus refusal. It met the accepted baseline with all applicable rubrics passing, including schema validity, citations, factual consistency, guideline retrieval, supervisor handoff, answer citation coverage, no-PHI logging, and bounding boxes.

Latency and cost tracking are shaped but not yet fully measured for this path. The clinical-document run records structured supervisor handoffs and a `latency_ms` field, but all handoff latencies are currently `0 ms`; therefore p50 and p95 from that artifact are placeholders, not real runtime measurements. Week 1 deployed latency and live-provider cost telemetry remain the best available operational baseline for the shared AgentForge request path.

## Evidence Used

| Evidence | What it proves | Limitation |
| --- | --- | --- |
| `agent-forge/eval-results/clinical-document-20260506-045203/summary.json` | Latest 9-case clinical-document gate verdict: `baseline_met`; all applicable rubrics pass. | Deterministic checkpoint run, not deployed runtime proof. |
| `agent-forge/eval-results/clinical-document-20260506-045203/run.json` | Per-case categories, adapter status, supervisor handoffs, answer sections, citation coverage, and no-PHI rubric results. | Handoff `latency_ms` values are `0`; no model token/cost totals. |
| `agent-forge/fixtures/clinical-document-golden/README.md` | The current MVP has 9 cases and is explicitly not the final 50-case Week 2 gate. | The final submission set is still planned. |
| `agent-forge/docs/operations/COST-ANALYSIS.md` | Shared AgentForge token-cost formulas, non-token operating cost ranges, and live-model eval spend framing. | Week 1 chart-agent measurements, not clinical-document-specific costs. |
| `agent-forge/docs/operations/LATENCY-RESULTS.md` | Deployed demo latency proof for two shared request shapes: A1c p95 `3212 ms`, visit briefing p95 `8309 ms`. | Does not include document ingestion, extraction, embedding, rerank, or Week 2 supervisor path. |
| `agent-forge/docs/operations/LATENCY-DECOMPOSITION.md` | Stage-timing model and likely latency drivers: evidence collection, draft call, verification, and request handling. | Decomposition predates clinical-document runtime stages. |

## Current Week 2 Checkpoint Metrics

Latest clinical-document artifact: `agent-forge/eval-results/clinical-document-20260506-045203/`.

| Metric | Value | Interpretation |
| --- | ---: | --- |
| Executed at | `2026-05-06T04:52:03+00:00` | Latest checked-in clinical-document run found in the repo. |
| Cases | `9` | MVP checkpoint, not final 50-case gate. |
| Verdict | `baseline_met` | Current deterministic baseline was met. |
| Supervisor handoffs | `9` | Every case records a structured handoff or refusal path. |
| Recorded handoff p50 | `0 ms` | Placeholder only; instrumentation field is present but not populated with real timings. |
| Recorded handoff p95 | `0 ms` | Placeholder only; do not treat as a latency claim. |
| Model tokens / model cost | Not recorded in this artifact | Use Week 1 live-provider telemetry as the shared provider-cost baseline until Week 2 live path logs extraction, embedding, reranker, and draft costs. |

Rubric status from the same summary:

| Rubric | Passed | Failed | Not applicable |
| --- | ---: | ---: | ---: |
| Schema valid | 6 | 0 | 3 |
| Citation present | 7 | 0 | 2 |
| Factually consistent | 6 | 0 | 3 |
| Guideline retrieval | 2 | 0 | 7 |
| Safe refusal | 2 | 0 | 7 |
| Final answer sections | 9 | 0 | 0 |
| Supervisor handoff | 9 | 0 | 0 |
| Answer citation coverage | 7 | 0 | 2 |
| No PHI in logs | 9 | 0 | 0 |
| Bounding box present | 6 | 0 | 3 |

## Cost Position

Current clinical-document checkpoint artifacts do not include actual model tokens, extraction model costs, embedding costs, reranker costs, or deployed infrastructure deltas. The honest Week 2 cost position is therefore:

- Actual clinical-document model spend in checked-in artifacts: unknown.
- Actual clinical-document p50/p95 runtime spend drivers: unknown.
- Shared live-provider baseline: Week 1 Tier 2 records real provider tokens and estimated cost in `agent-forge/eval-results/tier2-live-20260503-202550.json` (`5943` input tokens, `2476` output tokens, estimated cost `$0.015599`, provider `openai/gpt-5.4-mini`).
- Shared one-request model-cost baseline: `COST-ANALYSIS.md` records the measured A1c request at `836` input tokens, `173` output tokens, estimated model cost `$0.0002292`.
- Nightly live-eval cost posture: low enough to keep expanding eval coverage; `COST-ANALYSIS.md` estimates a 14-case pass at roughly cents-per-month scale for `gpt-4o-mini`, with exact numbers model-dependent.

For production projection, reuse the non-token cost categories in `COST-ANALYSIS.md`: hosting, storage, monitoring, backup, support/on-call, compliance/admin, audit-log retention, vendor review, and incident operations. Week 2 adds cost drivers that are not present in the Week 1 chart-only path:

- Document storage and retention for original uploads.
- Extraction compute for typed PDFs, scanned PDFs, and image-backed forms.
- Embedding and index maintenance for document facts and guideline chunks.
- Sparse retrieval, vector retrieval, and rerank work.
- Human-review queue operations for identity mismatch, uncertain facts, duplicates, and retractions.
- Source-review UI support and citation/bounding-box preservation.

## Latency Position

The shared deployed AgentForge request path has demo-grade p95 proof under the `10000 ms` budget for A1c and visit briefing. Week 2 clinical-document latency has not yet been measured end to end.

Expected Week 2 bottlenecks, in likely order:

1. Extraction for scanned or image-backed documents.
2. Embedding and vector index writes for extracted facts.
3. Retrieval fan-out: patient document retrieval, sparse guideline retrieval, vector guideline retrieval, and rerank.
4. Live draft call after evidence assembly.
5. Persistence and duplicate/retraction checks.

The upload path should not be judged by extraction completion latency if the worker runs asynchronously. The user-facing budget should separate:

| Stage | Budget interpretation |
| --- | --- |
| Upload enqueue | Interactive; should stay sub-second to low-seconds and return control to the chart. |
| Background extraction | Queue/worker SLO; may be seconds to minutes depending on document type and model path. |
| First answer using processed document facts | Interactive; should be compared to the shared AgentForge `10000 ms` demo budget only after document facts are already indexed. |
| Reprocessing, duplicate, and retraction flows | Operational SLO; not the same as interactive chat response latency. |

## Measurement Gap And Next Capture

The next report revision should replace placeholders with measured fields from eval/job artifacts:

- Upload enqueue latency.
- Queue wait time.
- Document load latency.
- Extraction latency by document type.
- Validation and identity-gating latency.
- Persistence and duplicate-check latency.
- Embedding latency and embedding token/cost counts.
- Sparse retrieval, vector retrieval, and rerank latency.
- Draft and verification latency.
- Provider model, input tokens, output tokens, cached tokens when available, and estimated cost per case.
- p50 and p95 over the full 50-case gate and over deployed Week 2 smoke runs.

Until those fields are populated, the Week 2 claim should stay narrow: deterministic clinical-document checkpoint proof is passing; clinical-document cost and runtime latency are not yet production-measured.

## Acceptance Matrix

| Requirement | Status | Proof |
| --- | --- | --- |
| Cost/latency report exists. | Implemented | This document. |
| Actual dev spend is not invented. | Implemented | Clinical-document artifacts do not contain spend; this report marks it unknown and points to shared live-provider baselines. |
| Projected production cost includes non-token drivers. | Implemented | Cost position lists Week 2 document, extraction, embedding, retrieval, review, and operations drivers; shared non-token tiers remain in `COST-ANALYSIS.md`. |
| p50/p95 latency is reported honestly. | Implemented with caveat | Latest artifact contains `0 ms` handoff latency placeholders, so p50/p95 are explicitly not runtime claims. |
| Bottleneck analysis exists. | Implemented | Latency position names likely Week 2 bottlenecks and separates interactive upload, background extraction, and answer latency. |
| Existing proof is linked. | Implemented | Evidence table links current clinical-document artifacts and shared operations docs. |

# Week 2 Clinical Document Cost And Latency

**Updated:** 2026-05-09
**Scope:** Week 2 clinical-document path: strict document extraction, guideline retrieval, supervisor handoffs, no-PHI logging, and deterministic eval artifacts.
**Status:** Reproducible report rendered from AgentForge proof artifacts. Values are labeled as measured, projected, placeholder, or unknown.

## Executive Summary

The latest clinical-document gate artifact contains `65` cases with verdict `baseline_met`. Clinical-document handoff latency is a placeholder: the field is instrumented, but the current deterministic fixture artifact does not prove runtime latency. Clinical-document model cost remains `unknown/not recorded` unless a live clinical-document artifact provides provider token usage.

The available live-provider development spend baseline is $0.015599 using `gpt-5.4-mini` with `5943` input tokens and `2476` output tokens. Shared live request latency p50/p95 is `1699 ms` / `3691 ms`; deployed smoke p50/p95 is `1620 ms` / `6204 ms`.

## Format Coverage

| Document Type | Cases | Runtime Support |
| --- | ---: | --- |
| `clinical_workbook` | 1 | Bounded runtime |
| `fax_packet` | 1 | Bounded runtime |
| `hl7v2_message` | 2 | Deterministic runtime |
| `intake_form` | 12 | Full bounded runtime |
| `lab_pdf` | 14 | Full bounded runtime |
| `referral_docx` | 1 | Bounded runtime |

| Source Format | Cases |
| --- | ---: |
| `docx` | 1 |
| `hl7` | 2 |
| `pdf` | 17 |
| `png` | 9 |
| `tiff` | 1 |
| `xlsx` | 1 |

## Evidence Used

| Artifact | Role |
| --- | --- |
| `agent-forge/eval-results/clinical-document-20260508-190800/run.json` | Source input for this rendered report. |
| `agent-forge/eval-results/clinical-document-20260508-190800/summary.json` | Source input for this rendered report. |
| `agent-forge/eval-results/tier2-live-20260503-202550.json` | Source input for this rendered report. |
| `agent-forge/eval-results/deployed-smoke-20260503-201547.json` | Source input for this rendered report. |

## Current Metrics

| Metric | Value | Interpretation |
| --- | ---: | --- |
| Clinical run executed at | `2026-05-08T19:08:00+00:00` | Source clinical-document summary timestamp. |
| Clinical cases | `65` | Deterministic Week 2 gate cases. |
| Clinical verdict | `baseline_met` | Baseline/threshold result from the gate. |
| Clinical handoff p50 | `placeholder 0 ms` | Instrumentation placeholder, not runtime proof. |
| Clinical handoff p95 | `placeholder 0 ms` | Instrumentation placeholder, not runtime proof. |
| Tier 2 live p50 | `1699 ms` | Measured shared live-provider request latency. |
| Tier 2 live p95 | `3691 ms` | Measured shared live-provider request latency. |
| Deployed smoke p50 | `1620 ms` | Measured deployed smoke request latency. |
| Deployed smoke p95 | `6204 ms` | Measured deployed smoke request latency. |
| Actual available provider spend | `$0.015599` | From available Tier 2 live artifact; clinical-document spend is unknown if not present in artifacts. |

## Projected Production Cost Drivers

- Model calls for extraction, embeddings, reranking, and final draft generation.
- Document storage and retention for original uploads and source-review metadata.
- MariaDB vector index writes and query work for guideline and document evidence boundaries.
- Human-review operations for identity mismatch, uncertain facts, duplicate handling, and retractions.
- Audit retention, backup, monitoring, incident response, and vendor/compliance review.

## Projected Model Spend

Token-only projection from the available live-provider baseline. This excludes hosting, storage, monitoring, backups, audit retention, support/on-call, compliance work, and human-review operations.

| Monthly requests | Projected model spend | Basis |
| ---: | ---: | --- |
| `1,000` | `$15.599250` | $0.015599/request from the latest live artifact. |
| `10,000` | `$155.992500` | $0.015599/request from the latest live artifact. |
| `100,000` | `$1559.925000` | $0.015599/request from the latest live artifact. |
| `1,000,000` | `$15599.250000` | $0.015599/request from the latest live artifact. |

## Bottleneck Analysis

Stage-timing drivers from available artifacts:
1. `draft` - `12773 ms` aggregate.
2. `planner` - `5893 ms` aggregate.
3. `verify` - `4 ms` aggregate.
4. `conversation:start` - `2 ms` aggregate.
5. `evidence:Recent labs` - `1 ms` aggregate.

## Acceptance Matrix

| Requirement | Status | Proof |
| --- | --- | --- |
| Cost/latency report exists. | Implemented | This file is rendered by `agent-forge/scripts/render-clinical-document-cost-latency.php`. |
| Actual dev spend is not invented. | Implemented | Missing clinical-document cost renders as unknown; available Tier 2 spend is shown separately. |
| Projected production cost is labeled. | Implemented | Model-spend projections are derived only from measured live-provider spend and explicitly exclude non-token operating costs. |
| p50/p95 latency is reported honestly. | Implemented | Placeholder clinical latency is labeled separately from measured live/deployed latency. |
| Bottleneck analysis exists. | Implemented | Uses stage timings when present and deterministic fallback drivers otherwise. |

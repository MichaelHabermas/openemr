# Cost Analysis

**Updated:** 2026-05-01
**Scope:** AgentForge measured baseline, user-tier production scenarios, and scale-tier architecture plan
**Status:** Reviewer-grade scenario analysis complete; production numbers remain estimates until broader live telemetry exists

## Executive Summary

The single measured AgentForge request proves instrumentation, not production economics. The current live path records model name, token usage, estimated model cost, latency, verifier result, and source ids, but one A1c question on one fake patient is not a forecast for a clinical deployment.

This analysis therefore separates measured facts from assumptions. The measured A1c request is retained as a baseline; projections are built from active clinical users, requests per clinician per day, work days, question mix, token shape, retry rate, cache assumptions, non-token operating costs, and architecture changes at 100, 1,000, 10,000, and 100,000 users.

Conclusion: at the current `gpt-4o-mini` price, model tokens are not the main cost driver until very large scale. The real bottlenecks are reliability, audit-log retention, support/on-call, observability, compliance administration, model-provider rate limits, and the engineering work needed to move from a demo VM to a production clinical system.

## Pricing Source

Current AgentForge manual testing uses OpenAI `gpt-4o-mini` through the server-side draft provider.

Pricing verified from the OpenAI model documentation on 2026-05-01:

- Source: https://developers.openai.com/api/docs/models/gpt-4o-mini
- Input text tokens: $0.15 per 1M tokens.
- Cached input text tokens: $0.075 per 1M tokens.
- Output text tokens: $0.60 per 1M tokens.
- The same model page states that `gpt-4o-mini` supports structured outputs.

If the model changes, do not reuse these numbers. Update `AGENTFORGE_OPENAI_INPUT_COST_PER_1M` and `AGENTFORGE_OPENAI_OUTPUT_COST_PER_1M` from the exact model pricing source, or leave them blank so `estimated_cost` is logged as unknown.

## Measured Baseline

The single measured request is a baseline, not a production forecast.

| Measurement | Local Docker | Public VM |
| --- | ---: | ---: |
| Fake patient | `900001` | `900001` |
| Prompt | `Show me the recent A1c trend.` | `Show me the recent A1c trend.` |
| Model | `gpt-4o-mini` | `gpt-4o-mini` |
| Input tokens | 836 input tokens | 836 input tokens |
| Output tokens | 173 output tokens | 173 output tokens |
| Estimated model cost | $0.0002292 | $0.0002292 |
| Latency | 2,989 ms | 10,693 ms |
| Verifier result | passed | passed |

Measured request formula:

```text
estimated_cost =
  (input_tokens / 1,000,000 * input_cost_per_1m)
  + (output_tokens / 1,000,000 * output_cost_per_1m)
```

Measured A1c request:

```text
(836 / 1,000,000 * 0.15) + (173 / 1,000,000 * 0.60) = 0.0002292
```

The VM latency is also a product risk. A chart-orientation tool must feel like a seconds-scale workflow. The current VM measurement is acceptable as demo evidence only; production readiness needs p95 proof under the accepted latency budget and `stage_timings_ms` evidence for bottleneck analysis. Stage-level decomposition of the 10,693 ms VM measurement is captured in `LATENCY-DECOMPOSITION.md`, which identifies the dominant stage and the mitigations that would move it.

## Tier 2 Live-LLM Eval Spend

The nightly Tier 2 evaluation workflow (`.github/workflows/agentforge-tier2.yml`) exercises 12 live-model cases against the configured OpenAI or Anthropic provider. With `gpt-4o-mini` pricing and chart-evidence-sized prompts comparable to the measured A1c request, one full pass is roughly:

```text
12 cases * ~$0.0003 per case ≈ $0.004 per pass
```

Nightly cadence: ~$0.12 per month. The cost is small enough that the eval suite can grow without re-pricing, but the line item is tracked here so it is not silently absorbed into the demo cost.

## Assumptions Table

| Input | Low scenario | Base scenario | High scenario | Evidence quality |
| --- | ---: | ---: | ---: | --- |
| User definition | Active clinician user | Active clinician user | Active clinician user | Assumption; `USERS.md` targets physicians |
| Clinicians per practice | 5 | 10 | 25 | Estimated operating assumption |
| requests per clinician per workday | 6 | 8 | 24 | Estimated; broader telemetry unknown |
| work days per month | 21 | 21 | 21 | Explicit planning assumption |
| question mix | 60% short lookup / 30% briefing / 10% missing-data or refusal | 40% short lookup / 40% briefing / 20% follow-up or missing-data | 25% short lookup / 50% briefing / 25% complex chart review | Estimated; live question mix unknown |
| average chart evidence size | 1,000 input tokens | 1,800 input tokens | 3,500 input tokens | Estimated from measured 836-token A1c prompt plus broader chart context |
| model output size | 200 output tokens | 300 output tokens | 600 output tokens | Estimated from measured 173-token A1c output |
| model input/output tokens | 1,000 / 200 | 1,800 / 300 | 3,500 / 600 | Estimated; must be replaced by live tier telemetry |
| retry rate | 0% | 5% | 10% | Estimated; malformed-output and provider-retry rates unmeasured |
| cache hit rate | 25% input-token reduction | 15% input-token reduction | 0% input-token reduction | Estimated; prompt caching not yet implemented as a product guarantee |
| live-provider pricing source | OpenAI `gpt-4o-mini` model page | OpenAI `gpt-4o-mini` model page | OpenAI `gpt-4o-mini` model page | Measured source, verified 2026-05-01 |
| hosting | Scenario range by tier | Scenario range by tier | Scenario range by tier | Estimated until vendor bills exist |
| storage | Sensitive audit-log volume by tier | Sensitive audit-log volume by tier | Sensitive audit-log volume by tier | Estimated until retention policy and log volume are measured |
| monitoring | Managed logs, metrics, alerts | Managed logs, metrics, alerts | Managed logs, metrics, alerts plus incident tooling | Estimated |
| backup | Database and audit-log backup | Backup plus restore tests | DR-grade backup and restore tests | Estimated |
| support/on-call | Part-time support | Business-hours support and on-call | Dedicated support/SRE coverage | Estimated |
| compliance/admin | Security/privacy review and access governance | Security/privacy review, vendor review, periodic audit | Formal governance, retention, vendor, and incident programs | Estimated |

Unknowns are deliberately not filled with false precision. The first production pilot must replace the estimated rows with telemetry for question mix, token distributions, retry rates, cache behavior, latency percentiles, log volume, and support load.

## Low / Base / High Usage Scenarios

Model-cost formula for scenario projections:

```text
effective_input_tokens = input_tokens * (1 - cache_hit_rate)
cost_per_request =
  ((effective_input_tokens / 1,000,000 * 0.15)
  + (output_tokens / 1,000,000 * 0.60))
  * (1 + retry_rate)
monthly_requests = users * requests_per_clinician_per_workday * 21
```

| Scenario | Requests per user per month | Effective tokens per request | Estimated model cost per request |
| --- | ---: | ---: | ---: |
| Low | 126 | 750 input / 200 output | $0.0002325 |
| Base | 168 | 1,530 input / 300 output | $0.0004300 |
| High | 504 | 3,500 input / 600 output | $0.0009735 |

These are model-provider costs only. They exclude hosting, storage, logging, monitoring, backup, support/on-call, compliance/admin, engineering labor, and any negotiated enterprise pricing changes.

## User-Tier Monthly Projection

| User tier | Monthly request range | Model spend range | Non-token operating range | Estimated total monthly range | Base model spend |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 100 users | 12,600 - 50,400 | $2.93 - $49.06 | $2,050 - $7,300 | $2,053 - $7,349 | $7.22 |
| 1,000 users | 126,000 - 504,000 | $29.30 - $490.64 | $8,500 - $29,000 | $8,529 - $29,491 | $72.24 |
| 10,000 users | 1,260,000 - 5,040,000 | $292.95 - $4,906.44 | $52,000 - $190,000 | $52,293 - $194,906 | $722.36 |
| 100,000 users | 12,600,000 - 50,400,000 | $2,929.50 - $49,064.40 | $350,000 - $1,350,000+ | $352,930 - $1,399,064+ | $7,223.58 |

Non-token cost ranges are scenario estimates, not measured invoices:

| User tier | hosting | storage | monitoring | backup | support/on-call | compliance/admin |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 100 users | $400 - $1,500 | $50 - $300 | $50 - $300 | $50 - $200 | $1,000 - $3,000 | $500 - $2,000 |
| 1,000 users | $2,000 - $7,500 | $500 - $2,000 | $500 - $2,000 | $500 - $1,500 | $4,000 - $10,000 | $1,000 - $6,000 |
| 10,000 users | $12,000 - $45,000 | $4,000 - $15,000 | $4,000 - $15,000 | $2,000 - $10,000 | $20,000 - $75,000 | $10,000 - $30,000 |
| 100,000 users | $80,000 - $300,000 | $30,000 - $150,000 | $30,000 - $125,000 | $20,000 - $100,000 | $150,000 - $500,000 | $40,000 - $175,000+ |

The absolute numbers are less important than the shape: token cost is cheap enough that deleting verifier, logging, authorization, or audit controls to save model spend would be the wrong optimization. The expensive parts are the systems that make a clinical agent defensible.

## Architecture Changes By Tier

| User tier | Architecture posture | Rate-limit and model strategy | Logging, retention, and observability | Operational support |
| ---: | --- | --- | --- | --- |
| 100 users | Hardened single-region production deployment; managed database; server-side OpenEMR integration; no broad search or background worker unless measured product constraints prove need. | One primary provider/model; enforce request timeouts; keep fixture mode for deterministic regression; provider quota monitored manually. | Centralized sensitive audit logs, basic metrics, alerting on endpoint failure, backup and restore test. | Part-time support, documented deploy/rollback, privacy/security review before pilot. |
| 1,000 users | Horizontally scalable app tier; database index review for evidence tools; centralized config and secrets; separate log storage. | Quota management, circuit breaker, retry budget, possible cheaper model for short lookup questions after verifier proof. | Managed log aggregation, latency dashboards, p50/p95/p99 tracking, alerting for verifier failures, cost anomaly detection. | Business-hours support plus on-call for clinical hours; incident playbooks. |
| 10,000 users | Multi-tenant production posture; capacity-tested OpenEMR integration; read replicas or query optimization where measured; queue only for non-interactive work. | Model tiering, fallback-provider design, negotiated rate limits, prompt-cache strategy where it preserves citation integrity. | Formal retention policy, audit-log access controls, restore drills, SLOs, incident management, compliance evidence exports. | Dedicated operations and security/privacy administration. |
| 100,000 users | Redesign required before claiming support: high-availability or multi-region architecture, dedicated observability program, mature access governance, disaster recovery, and enterprise vendor management. | Dedicated capacity or negotiated pricing, provider redundancy, strict traffic shaping, per-tenant quotas, model quality regression gates. | Large-scale sensitive audit-log retention, regional data-governance review, SRE-owned telemetry, compliance reporting automation. | Dedicated SRE/support/security/compliance functions and formal change management. |

The 100,000-user tier is not the same system with a larger bill. It changes the risk profile: patient authorization governance, audit retention, incident response, model-provider capacity, and regional reliability become first-order architecture requirements.

## Non-Token Cost Categories

- Production hosting and database capacity.
- Storage for sensitive audit logs, retention, backup, and restore testing.
- Monitoring, metrics, alerting, incident-management tooling, and cost-anomaly detection.
- Support/on-call staffing, escalation workflow, and incident playbooks.
- Security/privacy review, access-control maintenance, vendor review, and compliance/admin work.
- Deployment, rollback, disaster-recovery operations, and change management.
- Model-provider quota management, fallback planning, model regression checks, and possible dedicated capacity at high scale.
- Engineering work required before production-readiness claims: live-path evals, multi-turn state, citation UI, verifier hardening, PHI-minimizing tool routing, medication completeness, authorization expansion, observability, and latency-budget proof.

## Known Unknowns And Measurement Plan

Unknowns that must be measured before production pricing is trusted:

- Question mix by clinician workflow.
- Token distributions by question type and chart density.
- Retry, malformed-output, timeout, and verifier-failure rates with the live provider.
- Prompt-cache hit rate after selective tool routing exists.
- Log volume per request and retention duration.
- Hosting, monitoring, backup, and storage invoices.
- Support/on-call load per practice and per clinician.
- Provider rate-limit behavior under bursty clinical schedules.
- Latency percentiles on production-shaped infrastructure.

Measurement plan:

1. Keep recording `model`, `input_tokens`, `output_tokens`, `estimated_cost`, `latency_ms`, `verifier_result`, `tools_called`, and `source_ids` for every request.
2. Add live-path eval tiers before claiming agent-level evaluation.
3. Add selective PHI-minimizing tool routing so token and latency projections are based on required evidence, not over-broad tool calls.
4. Add log-retention policy, latency SLOs, dashboards or query paths, alerts, and p95 latency proof using existing per-stage timing fields.
5. Recompute this cost analysis from real telemetry after a pilot contains enough requests across briefing, medication, lab, missing-data, refusal, and follow-up shapes.

## Acceptance Matrix

| Requirement | Status | Proof |
| --- | --- | --- |
| Cost assumptions exist before projection. | Implemented | `Assumptions Table` labels measured, estimated, and unknown inputs. |
| Measured A1c request is baseline, not forecast. | Implemented | `Measured Baseline` states the single measured request is a baseline, not a production forecast. |
| Cost projection covers 100, 1K, 10K, and 100K users. | Implemented | `User-Tier Monthly Projection` includes all four required user tiers. |
| Projection includes non-token costs. | Implemented | `Non-Token Cost Categories` and the non-token operating table include hosting, storage, monitoring, backup, support/on-call, and compliance/admin. |
| Architecture changes are documented per tier. | Implemented | `Architecture Changes By Tier` maps each tier to operational architecture, model strategy, observability, and support posture. |
| Unknowns are not guessed as facts. | Implemented | `Known Unknowns And Measurement Plan` names missing measurements and how to replace assumptions. |

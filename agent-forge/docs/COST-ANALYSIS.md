# Cost Analysis

**Updated:** 2026-04-30
**Scope:** AgentForge measured baseline plus remediation plan
**Status:** Local and VM measured requests recorded; production user-tier rewrite is planned in `PLAN.md` Epic 9

## Reviewer Status

This document does not yet satisfy the full `SPECS.txt` production-cost deliverable.

Already implemented:

- One local manual A1c request measurement.
- One public VM manual A1c request measurement.
- Model name, token usage, estimated model cost, latency, and verifier result for those requests.

Accepted limitation:

- The measurements below are single-request baselines. They are not a production forecast.
- The old monthly-request projection was token arithmetic only and did not address users, infrastructure, retention, monitoring, support, compliance work, or architecture changes.

Planned remediation:

- Epic 9 rewrites this document around 100 / 1K / 10K / 100K users, including non-token costs and architecture changes at each tier.

## Pricing Source

Current AgentForge manual testing uses OpenAI `gpt-4o-mini` through the server-side draft provider.

Pricing recorded from the OpenAI model documentation on 2026-04-30:

- Source: https://developers.openai.com/api/docs/models/gpt-4o-mini
- Input text tokens: $0.15 per 1M tokens.
- Output text tokens: $0.60 per 1M tokens.
- The same source also states `gpt-4o-mini` supports structured outputs.

If the model changes, do not reuse these numbers. Update `AGENTFORGE_OPENAI_INPUT_COST_PER_1M` and `AGENTFORGE_OPENAI_OUTPUT_COST_PER_1M` from the exact model pricing source, or leave them blank so `estimated_cost` is logged as unknown.

## Local Manual Measurement

Manual browser test:

- Environment: local Docker OpenEMR, fake patient `900001`.
- Prompt: `Show me the recent A1c trend.`
- Final observed answer: `The recent Hemoglobin A1c levels are as follows: 7.4 % on 2026-04-10 and 8.2 % on 2026-01-09.`
- Log entry: `agent_forge_request` in `/var/log/apache2/error.log`.
- Model: `gpt-4o-mini`.
- Input tokens: `836`.
- Output tokens: `173`.
- Estimated cost: `$0.0002292`.
- Latency: `2989 ms`.
- Verifier result: `passed`.

Cost formula:

```text
estimated_cost =
  (input_tokens / 1,000,000 * input_cost_per_1m)
  + (output_tokens / 1,000,000 * output_cost_per_1m)
```

For the measured request:

```text
(836 / 1,000,000 * 0.15) + (173 / 1,000,000 * 0.60) = 0.0002292
```

## VM Manual Measurement

Manual browser test:

- Environment: public VM OpenEMR at `https://openemr.titleredacted.cc/`, fake patient `900001`.
- Prompt: `Show me the recent A1c trend.`
- Final observed answer: `The recent Hemoglobin A1c results are as follows: 7.4 % on 2026-04-10 and 8.2 % on 2026-01-09.`
- Log entry: `agent_forge_request` in `/var/log/apache2/error.log` inside the VM OpenEMR container.
- Model: `gpt-4o-mini`.
- Input tokens: `836`.
- Output tokens: `173`.
- Estimated cost: `$0.0002292`.
- Latency: `10693 ms`.
- Verifier result: `passed`.

## Baseline-Only Projection

These projections use the measured A1c request as a single-request baseline. They are not production forecasts because real physician workflows will vary by question type, chart size, cache behavior, rate limits, and model choice.

| Monthly requests | Estimated model cost at measured request shape |
| ---: | ---: |
| 100 | $0.02292 |
| 1,000 | $0.22920 |
| 10,000 | $2.29200 |
| 100,000 | $22.92000 |

This table is intentionally retained only to show request-shape math. It must not be used as the final user-scale cost analysis.

## Required User-Tier Rewrite Structure

Epic 9 must replace the baseline-only projection with scenario tables at the required user levels:

| User tier | Required projection inputs | Required architecture discussion |
| ---: | --- | --- |
| 100 users | Clinicians per practice, requests per clinician per workday, token mix, live-provider pricing, demo-to-production hosting delta, log volume, backup, monitoring, and support assumptions. | Whether a hardened single-region app/database plus managed logging is enough; rate-limit and backup posture. |
| 1,000 users | Same inputs plus concurrency estimate, model retry rate, cache hit assumptions, alerting coverage, retention volume, and support/on-call staffing. | Horizontal app scaling, database read/index review, centralized log aggregation, SLOs, and model-provider quota management. |
| 10,000 users | Same inputs plus multi-tenant operations, regional latency, compliance/admin overhead, incident response, and vendor capacity planning. | Dedicated queues or workers where justified, stronger caching/routing, model tiering or fallback provider strategy, and capacity testing. |
| 100,000 users | Same inputs plus enterprise support, audit retention at scale, disaster recovery, privacy/security review, and possible dedicated model capacity. | Multi-region or high-availability architecture, formal SRE/observability program, dedicated capacity or negotiated pricing, and mature access-control governance. |

The rewrite must include low/base/high scenarios instead of one false exact number whenever assumptions are not measured.

## Non-Token Cost Categories To Include

- Production hosting and database capacity.
- Storage for sensitive audit logs, retention, backup, and restore testing.
- Monitoring, metrics, alerting, and incident-management tooling.
- Support and on-call staffing.
- Security/privacy review, access-control maintenance, and compliance administration.
- Deployment, rollback, and disaster-recovery operations.
- Model-provider quota management, fallback planning, and possible dedicated capacity at high scale.

## Unknowns Policy

- Unknown values must remain labeled unknown until measured or explicitly estimated.
- Estimated values must be marked as assumptions and separated from measured telemetry.
- Model costs must be tied to the exact model and pricing source in use at the time of projection.
- Production readiness must not be claimed from the single A1c request baseline.

## Known Unknowns

- Production hosting, storage, logging retention, monitoring, backup, and support costs are not measured yet.
- Broader question mix is not measured yet.
- Prompt caching savings are not assumed.
- Batch pricing is not assumed.

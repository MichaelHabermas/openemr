# Cost Analysis

**Updated:** 2026-04-30
**Scope:** AgentForge local manual Epic 7 proof
**Status:** Local and VM measured requests recorded

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

## Projection Placeholder

These projections use the measured A1c request as a single-request baseline. They are not production forecasts because real physician workflows will vary by question type, chart size, cache behavior, rate limits, and model choice.

| Monthly requests | Estimated model cost at measured request shape |
| ---: | ---: |
| 100 | $0.02292 |
| 1,000 | $0.22920 |
| 10,000 | $2.29200 |
| 100,000 | $22.92000 |

## Known Unknowns

- Production hosting, storage, logging retention, monitoring, backup, and support costs are not measured yet.
- Broader question mix is not measured yet.
- Prompt caching savings are not assumed.
- Batch pricing is not assumed.

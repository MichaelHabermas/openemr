# AgentForge Deployed Latency Results

Status: deployed latency trace captured on 2026-05-03.

Raw VM artifact:

`/root/repos/openemr/agent-forge/eval-results/deployed-latency-trace-20260503-190443.json`

Summary:

| Question Class | Iterations | Passed | p50 | p95 | Max | p95 Budget |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| A1c trend | 20 | 20 | `2822 ms` | `3212 ms` | `3408 ms` | `<10000 ms` |
| Visit briefing | 20 | 20 | `4654 ms` | `8309 ms` | `19032 ms` | `<10000 ms` |

Trace context:

- Executed at `2026-05-03T19:04:43+00:00`.
- Deployed URL: `https://openemr.titleredacted.cc/`.
- Provider model in traced requests: `gpt-5.4-mini`.
- Each traced request returned HTTP 200 and `status: ok`.
- A1c requests used the deterministic verified fallback path (`verifier_result: fallback_passed`) after model verification failed.
- Visit briefing requests verified successfully (`verifier_result: passed`).

Reviewer interpretation:

- This satisfies the final submission's deployed demo latency proof for the two primary supported request shapes.
- It is not a production SLO claim. Production readiness would still require broader p95/p99 measurement across real traffic mix, more request classes, dashboards, alerting, and retention/incident operations.

Reproduction command from the VM host:

```sh
php agent-forge/scripts/run-deployed-latency-trace.php
```

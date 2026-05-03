# AgentForge Deployed Latency Results

Status: pending live deployed capture.

Run this after smoke credentials, provider credentials, and VM audit-log access are available:

```sh
php agent-forge/scripts/run-deployed-latency-trace.php
```

The runner writes raw traces to `agent-forge/eval-results/deployed-latency-trace-{timestamp}.json` and replaces this file with p50, p95, max, and per-stage timing summaries for A1c and visit-briefing requests. The p95 pass budget is under `10000 ms` for each traced question.

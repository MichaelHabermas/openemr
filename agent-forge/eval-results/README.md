# Eval runner output

Running `php agent-forge/scripts/run-evals.php` writes a **timestamped** JSON summary here (for example `eval-results-20260501-120000.json`). Those files are gitignored.

**Committed reference:** [`canonical.json`](canonical.json) is the checked-in snapshot from a full passing Tier 0 deterministic fixture/orchestration proof run (`13` passed, `0` failed). Refresh it when `agent-forge/fixtures/eval-cases.json` or eval-runner semantics change intentionally.

This fixture result is valuable regression proof, but it is not full live-agent proof. It does not exercise the real LLM, live SQL evidence retrieval, browser display, deployed endpoint, or real session authorization. See [`../docs/evaluation/EVALUATION-TIERS.md`](../docs/evaluation/EVALUATION-TIERS.md) for the release rule and the gated live-path tiers.

Running `php agent-forge/scripts/run-sql-evidence-evals.php` writes a separate **timestamped** Tier 1 SQL-backed JSON summary here (for example `sql-evidence-eval-results-20260501-120000.json`). This runner uses the real `SqlChartEvidenceRepository` and evidence tools against seeded OpenEMR demo data. Do not create or commit SQL evidence result files unless that SQL tier actually ran.

Before running Tier 1 locally, seed and verify the demo database:

```bash
agent-forge/scripts/seed-demo-data.sh
agent-forge/scripts/verify-demo-data.sh
php agent-forge/scripts/run-sql-evidence-evals.php
```

To store results elsewhere (CI artifacts, a mounted volume), set:

```bash
export AGENTFORGE_EVAL_RESULTS_DIR=/path/to/writable/dir
```

For SQL evidence evals only, `AGENTFORGE_SQL_EVAL_RESULTS_DIR` overrides the output directory and `AGENTFORGE_SQL_EVAL_ENVIRONMENT` labels the environment in the result file.

# Eval runner output

Human-readable Markdown summaries (local CLI and GitHub Actions job summaries) are documented in [../docs/evaluation/EVAL-REPORTING.md](../docs/evaluation/EVAL-REPORTING.md).

After each tier run, the repo also overwrites **stable** per-tier Markdown files here (same content shape as the CLI renderer): `LATEST-SUMMARY-TIER0.md`, `LATEST-SUMMARY-TIER1.md`, `LATEST-SUMMARY-TIER2.md`, and `LATEST-SUMMARY-TIER4.md`. They are gitignored; open the one matching the tier you last ran for a quick human digest. Set `AGENTFORGE_SKIP_LATEST_SUMMARY` to a non-empty value to disable that write.

Running `php agent-forge/scripts/run-evals.php` writes a **timestamped** JSON summary here (for example `eval-results-20260501-120000.json`). Those files are gitignored.

**Committed reference:** [`canonical.json`](canonical.json) is the checked-in snapshot from a full passing Tier 0 deterministic fixture/orchestration proof run (`28` passed, `0` failed). Refresh it when `agent-forge/fixtures/eval-cases.json` or eval-runner semantics change intentionally.

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

## Clinical document eval artifacts

Running `php agent-forge/scripts/run-clinical-document-evals.php` writes a timestamped subdirectory here named like `clinical-document-20260504-120000/`.

Each clinical document run directory contains:

- `run.json` — one entry per golden case, including adapter status and per-rubric result.
- `summary.json` — per-rubric pass rates and the comparator verdict.

The current Week 2 H1/H5 gate uses the implemented clinical-document adapter
and the checked-in 59-case golden set. A passing run reports
`"verdict": "baseline_met"` in `summary.json`; a schema, citation, refusal,
no-PHI, deleted-document, promotion, document-fact, guideline, or bounding-box
regression fails the command.

Current reviewer-facing proof:

- `clinical-document-20260507-202311/summary.json`
- `clinical-document-20260507-202311/run.json`

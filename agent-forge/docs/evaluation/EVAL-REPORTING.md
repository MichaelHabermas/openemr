# AgentForge eval reporting (human-readable)

## Purpose

Eval runners write detailed **JSON** under `agent-forge/eval-results/` (see [eval-results/README.md](../../eval-results/README.md)). JSON is ideal for machines and auditors; it is a poor default for busy reviewers or non-engineering stakeholders.

This project adds a small **normalized report** path:

- **Locally:** turn any result file into readable Markdown.
- **In CI:** summaries are appended to the GitHub Actions **job summary** (`GITHUB_STEP_SUMMARY`) so anyone opening a workflow run sees pass/fail counts, tier context, and per-case outcomes without downloading artifacts.
- **For Week 2 clinical documents:** render cost/latency from clinical `run.json` and `summary.json` through the same reporting package, while preserving the hard clinical gate artifacts.

Full JSON artifacts remain the source of truth for timings, log context, verifier fields, baseline comparison, and clinical-document gate decisions.

## Stable latest Markdown (per tier, auto-overwrite)

Whenever a tier runner writes its timestamped JSON into the results directory, it also overwrites a **stable** Markdown file in that **same directory** (implemented by `OpenEMR\AgentForge\Reporting\EvalLatestSummaryWriter`):

| Tier | File |
|------|------|
| 0 | `LATEST-SUMMARY-TIER0.md` |
| 1 | `LATEST-SUMMARY-TIER1.md` |
| 2 | `LATEST-SUMMARY-TIER2.md` |
| 4 | `LATEST-SUMMARY-TIER4.md` |

The first line is an HTML comment recording the source JSON basename and generation time (UTC). These files are **gitignored** (`eval-results/LATEST-SUMMARY-TIER*.md`).

To skip writing them (for example when debugging), set a non-empty **`AGENTFORGE_SKIP_LATEST_SUMMARY`** environment variable. Write failures never change the eval process exit code; a one-line message is printed to `STDERR`.

## Tier taxonomy

See [EVALUATION-TIERS.md](EVALUATION-TIERS.md). The normalizer recognizes Tier **0** (fixture/orchestration), **1** (seeded SQL evidence), **2** (live LLM), and **4** (deployed HTTP/session smoke) JSON shapes. Week 2 clinical-document cost/latency rendering uses `ClinicalDocumentCostLatencyArtifactNormalizer` and does not replace `run.json`, `summary.json`, `thresholds.json`, or `baseline.json`.

## CLI

From the repository root:

```bash
php agent-forge/scripts/render-eval-summary.php --input=agent-forge/eval-results/canonical.json
```

Write to a file:

```bash
php agent-forge/scripts/render-eval-summary.php --input=agent-forge/eval-results/canonical.json --output=/tmp/summary.md
```

`--format=github` is accepted for symmetry with CI; output is the same Markdown today.

Render the Week 2 clinical-document cost/latency report from a passing clinical artifact:

```bash
php agent-forge/scripts/render-clinical-document-cost-latency.php \
  --clinical-run=agent-forge/eval-results/clinical-document-20260508-190800/run.json \
  --clinical-summary=agent-forge/eval-results/clinical-document-20260508-190800/summary.json
```

Exit codes:

- `0` — summary written to stdout or `--output`.
- `2` — missing/invalid arguments, unreadable input, invalid JSON, or unrecognized eval shape.

## CI

Workflows call `agent-forge/scripts/ci/append-eval-step-summary.sh`, which picks the **newest** JSON matching a glob and runs the CLI with append to `GITHUB_STEP_SUMMARY`:

- [.github/workflows/agentforge-evals.yml](../../../.github/workflows/agentforge-evals.yml) — Tier 0 and Tier 1 jobs.
- [.github/workflows/agentforge-tier2.yml](../../../.github/workflows/agentforge-tier2.yml) — Tier 2.
- [.github/workflows/agentforge-deployed-smoke.yml](../../../.github/workflows/agentforge-deployed-smoke.yml) — Tier 4.

If no file matches the glob, the summary records that the step was skipped (for example when a prior step failed before writing JSON).

## Implementation notes

- Normalization and rendering live under `src/AgentForge/Reporting/` (`OpenEMR\AgentForge\Reporting`) for reuse and isolated tests.
- `NormalizedEvalRun` / `NormalizedEvalCaseRow` feed Markdown and GitHub summary rendering.
- `ClinicalDocumentCostLatencyRun` feeds the clinical-document cost/latency report.
- Unknown future JSON shapes fail fast in the CLI so CI surfaces breakage instead of silently omitting summaries.

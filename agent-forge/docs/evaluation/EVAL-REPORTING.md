# AgentForge eval reporting (human-readable)

## Purpose

Eval runners write detailed **JSON** under `agent-forge/eval-results/` (see [eval-results/README.md](../../eval-results/README.md)). JSON is ideal for machines and auditors; it is a poor default for busy reviewers or non-engineering stakeholders.

This project adds a small **Markdown summary** path:

- **Locally:** turn any result file into readable Markdown.
- **In CI:** summaries are appended to the GitHub Actions **job summary** (`GITHUB_STEP_SUMMARY`) so anyone opening a workflow run sees pass/fail counts, tier context, and per-case outcomes without downloading artifacts.

Full JSON artifacts remain the source of truth for timings, log context, and verifier fields.

## Tier taxonomy

See [EVALUATION-TIERS.md](EVALUATION-TIERS.md). The normalizer recognizes Tier **0** (fixture/orchestration), **1** (seeded SQL evidence), **2** (live LLM), and **4** (deployed HTTP/session smoke) JSON shapes.

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
- Unknown future JSON shapes fail fast in the CLI so CI surfaces breakage instead of silently omitting summaries.

# Eval runner output

Running `php agent-forge/scripts/run-evals.php` writes a **timestamped** JSON summary here (for example `eval-results-20260501-120000.json`). Those files are gitignored.

**Committed reference:** [`canonical.json`](canonical.json) is the checked-in snapshot from a full passing Tier 0 deterministic fixture/orchestration proof run (`13` passed, `0` failed). Refresh it when `agent-forge/fixtures/eval-cases.json` or eval-runner semantics change intentionally.

This fixture result is valuable regression proof, but it is not full live-agent proof. It does not exercise the real LLM, live SQL evidence retrieval, browser display, deployed endpoint, or real session authorization. See [`../docs/EVALUATION-TIERS.md`](../docs/EVALUATION-TIERS.md) for the release rule and the gated live-path tiers.

To store results elsewhere (CI artifacts, a mounted volume), set:

```bash
export AGENTFORGE_EVAL_RESULTS_DIR=/path/to/writable/dir
```

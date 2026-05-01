# Eval runner output

Running `php agent-forge/scripts/run-evals.php` writes a **timestamped** JSON summary here (for example `eval-results-20260501-120000.json`). Those files are gitignored.

**Committed reference:** [`canonical.json`](canonical.json) is the checked-in snapshot from a full passing fixture run (`13` passed, `0` failed). Refresh it when `agent-forge/fixtures/eval-cases.json` or eval-runner semantics change intentionally.

To store results elsewhere (CI artifacts, a mounted volume), set:

```bash
export AGENTFORGE_EVAL_RESULTS_DIR=/path/to/writable/dir
```

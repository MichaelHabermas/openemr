# Eval runner output

Running `php agent-forge/scripts/run-evals.php` writes a timestamped JSON summary here (for example `eval-results-20260501-120000.json`).

These files are gitignored. To store results elsewhere (CI artifacts, a mounted volume), set:

```bash
export AGENTFORGE_EVAL_RESULTS_DIR=/path/to/writable/dir
```

# AgentForge Final Acceptance Matrix

| Requirement | Artifact | Verification command | Latest result | Caveat |
| --- | --- | --- | --- | --- |
| Root reviewer docs are canonical | `AUDIT.md`, `USERS.md`, `ARCHITECTURE.md` | `test -f AUDIT.md && test -f USERS.md && test -f ARCHITECTURE.md` | Present | None |
| Deployed URL visible from README | `README.md` | `rg "https://openemr.titleredacted.cc/" README.md` | Present | Reachability still checked separately |
| Seven use cases with traceability | `USERS.md` | `rg "Capability Matrix|Vital Trend|Medication Reconciliation|Allergy Review|Encounter And Last-Plan" USERS.md` | Present | Eval coverage is mixed local/live |
| LLM-backed tool selection with fallback | `src/AgentForge/Evidence/ToolSelectionProvider.php` | `composer phpunit-isolated -- --filter 'ChartQuestionPlannerTest'` | Passed in full AgentForge isolated suite, 298 tests / 1547 assertions | Live selector requires provider credentials |
| Tier 0 deterministic evals | `agent-forge/fixtures/eval-cases.json` | `php agent-forge/scripts/run-evals.php` | 32 passed, 0 failed (`eval-results-20260503-185620.json`) | None |
| Tier 1 SQL evidence evals | `agent-forge/scripts/run-sql-evidence-evals.php` | Docker SQL eval command in reviewer guide | 7 passed, 0 failed (`sql-evidence-eval-results-20260503-161657.json`) | Requires dev container |
| Tier 2 live evals | `agent-forge/fixtures/tier2-eval-cases.json` | `php agent-forge/scripts/run-tier2-evals.php` | VM artifact `tier2-live-20260503-183646.json`: 14 passed, 0 failed; tokens in/out `5943/2584`; cost `$0.016085` | Requires live provider credentials |
| Tier 4 deployed smoke | `agent-forge/scripts/run-deployed-smoke.php` | `php agent-forge/scripts/run-deployed-smoke.php` | VM artifact `deployed-smoke-20260503-190049.json`: 5 passed, 0 failed, 0 skipped; aggregate latency `11604 ms`; code version `6769aa908887` | Requires smoke credentials and VM audit-log access |
| Deployed latency trace | `agent-forge/scripts/run-deployed-latency-trace.php` | `php agent-forge/scripts/run-deployed-latency-trace.php` | Command available | Requires smoke credentials and VM audit-log access |
| Health check | `agent-forge/scripts/health-check.sh` | `agent-forge/scripts/health-check.sh` | Public app HTTP 200 and readiness HTTP 200 on 2026-05-03 | Network/deployed URL dependent |
| Cost analysis | `agent-forge/docs/operations/COST-ANALYSIS.md` | `test -f agent-forge/docs/operations/COST-ANALYSIS.md` | Present | Production p95 latency still pending |
| Browser proof attachment slot | `agent-forge/docs/submission/browser-proof/` | `test -f agent-forge/docs/submission/browser-proof/MANIFEST.md` | Four browser screenshots supplied; manifest prepared with target filenames and supporting Tier 4 request ids | Screenshots must use fake patient `900001 / AF-DEMO-900001` only |

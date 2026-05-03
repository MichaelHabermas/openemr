# AgentForge Final Acceptance Matrix

| Requirement | Artifact | Verification command | Latest result | Caveat |
| --- | --- | --- | --- | --- |
| Root reviewer docs are canonical | `AUDIT.md`, `USERS.md`, `ARCHITECTURE.md` | `test -f AUDIT.md && test -f USERS.md && test -f ARCHITECTURE.md` | Present | None |
| Deployed URL visible from README | `README.md` | `rg "https://openemr.titleredacted.cc/" README.md` | Present | Reachability still checked separately |
| Seven use cases with traceability | `USERS.md` | `rg "Capability Matrix|Vital Trend|Medication Reconciliation|Allergy Review|Encounter And Last-Plan" USERS.md` | Present | Eval coverage is mixed local/live |
| LLM-backed tool selection with fallback | `src/AgentForge/Evidence/ToolSelectionProvider.php` | `composer phpunit-isolated -- --filter 'ChartQuestionPlannerTest'` | Implemented | Live selector requires provider credentials |
| Tier 0 deterministic evals | `agent-forge/fixtures/eval-cases.json` | `php agent-forge/scripts/run-evals.php` | Command available | Must be rerun after implementation |
| Tier 1 SQL evidence evals | `agent-forge/scripts/run-sql-evidence-evals.php` | Docker seed plus SQL eval command in reviewer guide | Command available | Requires dev container |
| Tier 2 live evals | `agent-forge/fixtures/tier2-eval-cases.json` | `php agent-forge/scripts/run-tier2-evals.php` | Command available | Requires live provider credentials |
| Tier 4 deployed smoke | `agent-forge/scripts/run-deployed-smoke.php` | `php agent-forge/scripts/run-deployed-smoke.php` | Command available | Requires smoke credentials and VM audit-log access |
| Deployed latency trace | `agent-forge/scripts/run-deployed-latency-trace.php` | `php agent-forge/scripts/run-deployed-latency-trace.php` | Command available | Requires smoke credentials and VM audit-log access |
| Health check | `agent-forge/scripts/health-check.sh` | `agent-forge/scripts/health-check.sh` | Command available | Network/deployed URL dependent |
| Cost analysis | `agent-forge/docs/operations/COST-ANALYSIS.md` | `test -f agent-forge/docs/operations/COST-ANALYSIS.md` | Present | Production p95 latency still pending |
| Proof pack references present files only | `agent-forge/docs/submission/FINAL-PROOF-PACK.md` | `rg "/root/repos|captured externally" agent-forge/docs/submission/FINAL-PROOF-PACK.md` | Should return no missing local artifact claims | Green live artifacts must be attached when supplied |

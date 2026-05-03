# AgentForge Final Proof Pack

This pack is the reviewer-facing evidence map for the final AgentForge submission. It records exactly what was proven, where to find the artifacts, and what remains outside the claim. AgentForge is a demo-grade clinical co-pilot with verification and eval scaffolding; it is not claimed to be a production medical device or hospital-ready deployment.

## Code Versions

| Context | Version |
| --- | --- |
| Final remediation commit | `6769aa908` |
| Deployed proof build | `6769aa908887` |

The green VM artifacts below are the final proof targets for reviewer packaging. If the full JSON result files are copied into the checkout, keep their filenames unchanged and store them under `agent-forge/eval-results/`.

## Automated Proof

| Tier | Artifact | Result | What It Proves |
| --- | --- | --- | --- |
| Tier 0 deterministic fixture/orchestration | `agent-forge/eval-results/eval-results-20260503-185620.json`; `LATEST-SUMMARY-TIER0.md` | 32 passed, 0 failed | Planner, selector fallback, refusal policy, verifier/fallback behavior, fixture orchestration |
| Tier 1 seeded SQL evidence | `agent-forge/eval-results/sql-evidence-eval-results-20260503-161657.json`; `LATEST-SUMMARY-TIER1.md` | 7 passed, 0 failed | Real `SqlChartEvidenceRepository` and evidence tools against seeded fake OpenEMR data |
| Tier 2 live model | VM artifact `/var/www/localhost/htdocs/openemr/agent-forge/eval-results/tier2-live-20260503-183646.json`; `LATEST-SUMMARY-TIER2.md` when copied locally | 14 passed, 0 failed; tokens in/out `5943/2584`; estimated cost `$0.016085`; provider `openai/gpt-5.4-mini` | Live provider path, safety-critical refusal/hallucination/prompt-injection cases, selector behavior, token/cost telemetry |
| Tier 4 deployed smoke | VM artifact `/root/repos/openemr/agent-forge/eval-results/deployed-smoke-20260503-190049.json`; `LATEST-SUMMARY-TIER4.md` when copied locally | 5 passed, 0 failed, 0 skipped; aggregate latency `11604 ms`; audit assertions enabled; code version `6769aa908887` | Public deployed HTTP path, login/session, CSRF, chart endpoint, JSON response, sanitized audit-log assertions |

The final green Tier 4 smoke command was run from the VM host on 2026-05-03 at `19:00:49+00:00`:

```sh
php agent-forge/scripts/run-deployed-smoke.php
```

## Deployed Browser Proof

Manual browser screenshots are attached under `agent-forge/docs/submission/browser-proof/` for the four deployed UI scenarios below. They were captured on 2026-05-03 against fake patient `900001 / AF-DEMO-900001` only.

| Browser Proof | Request ID | Expected Visible Evidence |
| --- | --- | --- |
| `a1c-trend.png` | Tier 4 supporting request `7cf183f7-5607-403e-9559-e2689a0769aa` | Deployed URL, fake patient header, `8.2 %` and `7.4 %`, lab Sources |
| `visit-briefing.png` | Tier 4 supporting request `bbbddd92-df71-4835-951b-f14279abe18c` | Deployed URL, fake patient header, demographics/problems/medications/allergies/labs/vitals/notes with citations |
| `missing-microalbumin.png` | Tier 4 supporting request `e4ca6da4-9cd9-4222-a9c3-06651098fb49` | Missing-data response without invented normal/never-ordered claim |
| `clinical-advice-refusal.png` | Tier 4 supporting request `ee2fe6c2-56cc-47ac-8731-a3fd885ad9e3` | Clinical diagnosis/dosing/advice refusal before tools/model |

The deployed cross-patient/stale conversation boundary is covered by the green Tier 4 smoke case with HTTP 403, `tools_called=[]`, `model=not_run`, and `verifier_result=not_run`.

## Reproduce Tests

Local deterministic gate:

```sh
agent-forge/scripts/check-local.sh
```

This command runs PHP syntax checks, shell syntax checks, the isolated AgentForge PHPUnit suite, Tier 0 deterministic evals, PHPStan, and PHPCS. If PHPStan cannot bind `127.0.0.1` in a sandboxed terminal, rerun the same command in a normal local shell.

Seeded SQL evidence tier:

```sh
docker compose -f docker/development-easy/docker-compose.yml up -d openemr
docker compose -f docker/development-easy/docker-compose.yml exec -T openemr /var/www/localhost/htdocs/openemr/agent-forge/scripts/seed-demo-data.sh
docker compose -f docker/development-easy/docker-compose.yml exec -T openemr php /var/www/localhost/htdocs/openemr/agent-forge/scripts/run-sql-evidence-evals.php
```

Live model tier:

```sh
php agent-forge/scripts/run-tier2-evals.php
```

This requires provider credentials configured out of band.

Deployed smoke tier from the VM host:

```sh
export AGENTFORGE_SMOKE_USER='assigned-smoke-user'
export AGENTFORGE_SMOKE_PASSWORD='assigned-smoke-password'
export AGENTFORGE_VM_SSH_HOST='docker-compose'
export AGENTFORGE_VM_AUDIT_LOG_PATH='/var/log/apache2/error.log'
export AGENTFORGE_DEPLOYED_URL='https://openemr.titleredacted.cc/'
php agent-forge/scripts/run-deployed-smoke.php
```

Deployed latency trace from the VM host:

```sh
php agent-forge/scripts/run-deployed-latency-trace.php
```

This writes `agent-forge/eval-results/deployed-latency-trace-{timestamp}.json` and updates `agent-forge/docs/operations/LATENCY-RESULTS.md`.

## Limits Not Claimed

- Not production-ready or a medical device.
- No p95 production latency proof; current latency evidence is demo-grade.
- Multi-turn behavior is intentionally shallow and safety-scoped.
- Verifier is useful for source-grounded demo data, but not a full clinical truth layer.
- Observability includes structured request logs and timings, but not dashboards, alerting, SLO operations, retention governance, or incident workflows.

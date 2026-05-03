# AgentForge Final Proof Pack

This pack is the reviewer-facing evidence map for the final AgentForge submission. It records exactly what was proven, where to find the artifacts, and what remains outside the claim. AgentForge is a demo-grade clinical co-pilot with verification and eval scaffolding; it is not claimed to be a production medical device or hospital-ready deployment.

## Code Versions

| Context | Version |
| --- | --- |
| Current checkout after final packaging updates | `20b9239286bf` |
| Green deployed Tier 4 smoke artifact code version | `81c870a6aecb` |

The current checkout includes documentation and proof-packaging updates made after the green deployed smoke run. The deployed artifact records the VM code version that executed the HTTP/session/audit proof.

## Automated Proof

| Tier | Artifact | Result | What It Proves |
| --- | --- | --- | --- |
| Tier 0 deterministic fixture/orchestration | `agent-forge/eval-results/eval-results-20260503-034628.json`; `LATEST-SUMMARY-TIER0.md` | 28 passed, 0 failed | Planner, routing, refusal policy, verifier/fallback behavior, fixture orchestration |
| Tier 1 seeded SQL evidence | `agent-forge/eval-results/sql-evidence-eval-results-20260503-032517.json`; `LATEST-SUMMARY-TIER1.md` | 7 passed, 0 failed | Real `SqlChartEvidenceRepository` and evidence tools against seeded fake OpenEMR data |
| Tier 2 live model | `agent-forge/eval-results/tier2-live-20260503-034230.json`; `LATEST-SUMMARY-TIER2.md` | Local artifact: 12 passed, 0 failed | Live provider path, safety-critical refusal/hallucination/prompt-injection cases, token/cost telemetry |
| Tier 4 deployed smoke | `agent-forge/eval-results/deployed-smoke-20260503-030537.json`; `agent-forge/eval-results/deployed-smoke-20260503-033855.json`; `LATEST-SUMMARY-TIER4.md` | Current checkout contains historical smoke artifacts; green live proof must be recaptured with credentials | Public deployed HTTP path, login/session, CSRF, chart endpoint, JSON response, sanitized audit-log assertions |

Green Tier 4 request ids: A1c `a1fc75ea-7f5b-4496-920c-98d93ba58530`, dosing refusal `405dfab6-1eec-40bd-8664-49801d7c4655`, missing microalbumin `3b12ad5c-8930-4a19-b052-3b0ff62f58b3`, and cross-patient refusal `3c243d4f-afb2-487a-9682-864436f104d8`.

## Deployed Browser Proof

Manual screenshots or HTML captures must be attached under `agent-forge/docs/submission/browser-proof/` when supplied. They are not present in this checkout.

| Browser Proof | Request ID | Expected Visible Evidence |
| --- | --- | --- |
| A1c trend | `106f8c38-43b0-483d-82ce-825f59b38dd1` | Deployed URL, fake patient header, `8.2 %` and `7.4 %`, lab Sources |
| Visit briefing | `571e91f3-daad-4794-9124-e38cbe72395f` | Deployed URL, fake patient header, demographics/problems/medications/allergies/labs/vitals/notes with citations |
| Missing microalbumin | `ea02e9a5-a309-4c37-ac42-844bd2348cc6` | Missing-data response without invented normal/never-ordered claim |
| Diagnosis refusal | `e3f938db-0d9a-4539-92b9-7c6845899b2e` | Clinical diagnosis/advice refusal before tools/model |

The deployed cross-patient/stale conversation boundary is covered by Tier 4 request `3c243d4f-afb2-487a-9682-864436f104d8`: HTTP 403, `tools_called=[]`, `model=not_run`, and `verifier_result=not_run`.

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

# AgentForge Final Proof Pack

This pack is the reviewer-facing evidence map for the final AgentForge submission. It records exactly what was proven, where to find the artifacts, and what remains outside the claim. AgentForge is a demo-grade clinical co-pilot with verification and eval scaffolding; it is not claimed to be a production medical device or hospital-ready deployment.

## Code Versions

| Context | Version |
| --- | --- |
| Checked-in Tier 0 fixture/orchestration proof | `6736aed89fc2` |
| Checked-in Tier 1 SQL evidence proof | `d04b11e22014` |
| Checked-in Tier 2 live-model proof | `bd8dd6d05ce8` |
| Checked-in Tier 4 deployed-smoke proof | `81c870a6aecb` |

The proof rows below distinguish checked-in repository artifacts from later VM/container artifacts. Reviewers can inspect the checked-in `LATEST-SUMMARY` files directly from the repository; the latest Tier 2 live-provider JSON and latest deployed-smoke JSON are also checked in.

## Automated Proof

| Tier | Artifact | Result | What It Proves |
| --- | --- | --- | --- |
| Tier 0 deterministic fixture/orchestration | `agent-forge/eval-results/eval-results-20260503-185620.json`; `LATEST-SUMMARY-TIER0.md` | 32 passed, 0 failed | Planner, selector fallback, refusal policy, verifier/fallback behavior, fixture orchestration |
| Tier 1 seeded SQL evidence | `agent-forge/eval-results/sql-evidence-eval-results-20260503-161657.json`; `LATEST-SUMMARY-TIER1.md` | 7 passed, 0 failed | Real `SqlChartEvidenceRepository` and evidence tools against seeded fake OpenEMR data |
| Tier 2 live model | `agent-forge/eval-results/tier2-live-20260503-173557.json`; `LATEST-SUMMARY-TIER2.md` | 14 passed, 0 failed; tokens in/out `5697/2810`; estimated cost `$0.002541`; provider `openai/gpt-5.4-mini` | Live provider path, safety-critical refusal/hallucination/prompt-injection cases, selector behavior, token/cost telemetry |
| Tier 4 deployed smoke | `LATEST-SUMMARY-TIER4.md`; VM artifact `/root/repos/openemr/agent-forge/eval-results/deployed-smoke-20260503-042413.json` | 4 passed, 0 failed; audit assertions enabled; code version `81c870a6aecb` | Public deployed HTTP path, login/session, CSRF, chart endpoint, JSON response, sanitized audit-log assertions |
| Deployed latency trace | VM artifact `/root/repos/openemr/agent-forge/eval-results/deployed-latency-trace-20260503-190443.json`; `agent-forge/docs/operations/LATENCY-RESULTS.md` | A1c 20/20, p95 `3212 ms`; visit briefing 20/20, p95 `8309 ms`; both under `10000 ms` budget | Deployed demo latency across repeated A1c and visit-briefing requests |

> **What this proof does not prove:** no real PHI was used; the latency trace is not a production SLO; AgentForge is not a full clinical rules engine; authorization does not cover broad care-team, facility, schedule-derived, or delegated access; and medication evidence is not a medication reconciliation truth engine.

Additional later VM/container runs were captured after the checked-in summaries. The latest Tier 2 live-provider JSON and Tier 4 deployed smoke JSON are checked into this repository; the latest local gate artifact is referenced below and should be attached to the final submission packet if possible.

Latest VM proof supplied on 2026-05-03:

| Check | Artifact | Result |
| --- | --- | --- |
| Local AgentForge gate from a normal VM shell | `agent-forge/scripts/check-local.sh`; deterministic eval artifact `/root/repos/openemr/agent-forge/eval-results/eval-results-20260503-201548.json` | PASS; PHP syntax, shell syntax, isolated PHPUnit `298 tests / 1547 assertions`, deterministic evals `32 passed, 0 failed`, PHPStan `161/161`, PHPCS no changed AgentForge PHP files |
| Tier 2 live model from OpenEMR container | `agent-forge/eval-results/tier2-live-20260503-202550.json` | 14 passed, 0 failed; tokens in/out `5943/2476`; estimated cost `$0.015599`; provider `openai/gpt-5.4-mini` |
| Tier 4 deployed smoke from VM | `agent-forge/eval-results/deployed-smoke-20260503-201547.json` | 5 passed, 0 failed; aggregate latency `14734 ms`; audit assertions enabled; includes `tier4_visit_briefing_live_verified`; code version `6769aa908887` |

Attach the latest local gate artifact to the final submission packet if possible; the latest Tier 2 and Tier 4 JSON artifacts are checked in here.

## Final Submission Links

| Artifact | URL |
| --- | --- |
| Gauntlet Labs submission | https://labs.gauntletai.com/michaelhabermas/openemr |
| Deployed app | https://openemr.titleredacted.cc/ |
| Demo video | https://www.loom.com/share/bd57c6cd2c5346b397ed7f60ad8a8f32 |
| Social post | https://x.com/habermoose/status/2050766281515700369 |

The checked-in Tier 4 smoke command was run against the deployed app on 2026-05-03:

```sh
php agent-forge/scripts/run-deployed-smoke.php
```

If the current shell is already inside `agent-forge/`, run `php scripts/run-deployed-smoke.php` instead.

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

This requires provider credentials configured out of band. If host PHP reports that Tier 2 is using the fixture provider, run the command inside the OpenEMR container so Docker Compose supplies the configured provider environment:

```sh
cd docker/development-easy
docker compose exec openemr php /var/www/localhost/htdocs/openemr/agent-forge/scripts/run-tier2-evals.php
```

Deployed smoke tier from the VM host:

```sh
export AGENTFORGE_SMOKE_USER='assigned-smoke-user'
export AGENTFORGE_SMOKE_PASSWORD='assigned-smoke-password'
export AGENTFORGE_VM_SSH_HOST='assigned-vm-ssh-host'
export AGENTFORGE_VM_AUDIT_LOG_PATH='/var/log/apache2/error.log'
export AGENTFORGE_DEPLOYED_URL='https://openemr.titleredacted.cc/'
php agent-forge/scripts/run-deployed-smoke.php
```

If the current shell is already inside `agent-forge/`, use `php scripts/run-deployed-smoke.php`.
For remote deployed URLs, `AGENTFORGE_VM_SSH_HOST` must point at the same
deployment VM; use `docker-compose` only for local Docker smoke targets.

Deployed latency trace from the VM host:

```sh
php agent-forge/scripts/run-deployed-latency-trace.php
```

The final captured run wrote VM artifact `/root/repos/openemr/agent-forge/eval-results/deployed-latency-trace-20260503-190443.json` and updated `agent-forge/docs/operations/LATENCY-RESULTS.md`.

## Limits Not Claimed

- Not production-ready or a medical device.
- Deployed p95 latency proof is demo-grade and covers the A1c and visit-briefing request shapes only; it is not a production SLO claim.
- Multi-turn behavior is intentionally shallow and safety-scoped.
- Verifier is useful for source-grounded demo data, but not a full clinical truth layer.
- Observability includes structured request logs and timings, but not dashboards, alerting, SLO operations, retention governance, or incident workflows.

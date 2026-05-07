# AgentForge Reviewer Guide

This guide is the root reviewer entry point for the AgentForge Clinical
Co-Pilot work inside this OpenEMR fork. It separates the Week 1 chart
orientation path from the Week 2 multimodal clinical-document path so graders
do not have to infer which command, patient, service, or proof artifact applies.

## Deployed URL

Documented public app URL:

`https://openemr.titleredacted.cc/`

Final submission links:

| Artifact | URL |
| --- | --- |
| Gauntlet Labs submission | https://labs.gauntletai.com/michaelhabermas/openemr |
| Deployed app | https://openemr.titleredacted.cc/ |
| Demo video | https://www.loom.com/share/bd57c6cd2c5346b397ed7f60ad8a8f32 |
| Social post | https://x.com/habermoose/status/2050766281515700369 |

Run health before any live demo:

```sh
agent-forge/scripts/health-check.sh
```

The health command checks the public app URL and the PHI-safe readiness endpoint
when available:

`https://openemr.titleredacted.cc/meta/health/readyz`

A passing health check proves current reachability and runtime readiness only;
it is not a production-readiness claim.

## Demo Data And Credentials

AgentForge uses fake demo data only. Do not use real patient data or real PHI.
Demo credentials are not committed to the repository; use credentials assigned
out of band by the deployed environment owner.

| Demo patient | OpenEMR pid | Public id | Purpose |
| --- | ---: | --- | --- |
| Week 1 chart baseline | `900001` | `AF-DEMO-900001` | Seeded chart evidence, A1c trend, visit briefing, refusals, citations. |
| Week 2 clinical documents | `900101` by default for deployed clinical smoke | Configurable through `AGENTFORGE_CLINICAL_SMOKE_PID` | Lab/intake upload, extraction worker, guideline retrieval, source review, retraction proof. |

## Week 1 Baseline Demo Path

Use this path to review the original chart-grounded Clinical Co-Pilot behavior.

1. Authenticate to OpenEMR with assigned demo credentials.
2. Open fake patient `900001` / `AF-DEMO-900001`.
3. Use the chart-embedded Clinical Co-Pilot panel.
4. Ask `Show me the recent A1c trend.`
5. Confirm the answer is scoped to the active patient, includes `8.2 %` on
   `2026-01-09` and `7.4 %` on `2026-04-10`, and displays citations under
   Sources.

Week 1 proof and supporting docs:

- [ARCHITECTURE.md](ARCHITECTURE.md)
- [USERS.md](USERS.md)
- [AUDIT.md](AUDIT.md)
- [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md)
- [agent-forge/eval-results/canonical.json](agent-forge/eval-results/canonical.json)

## Week 2 Clinical Document Demo Path

Use this path to review the multimodal Week 2 flow.

1. Run `agent-forge/scripts/health-check.sh`.
2. Authenticate to OpenEMR with assigned demo credentials.
3. Open the configured Week 2 fake patient. The deployed clinical smoke default
   is `AGENTFORGE_CLINICAL_SMOKE_PID=900101`.
4. Upload or attach a lab PDF using the mapped `lab_pdf` category. The smoke
   runner defaults can be overridden with
   `AGENTFORGE_CLINICAL_SMOKE_LAB_PATH` and
   `AGENTFORGE_CLINICAL_SMOKE_LAB_CATEGORY`.
5. Upload or attach an intake form using the mapped `intake_form` category. The
   smoke runner defaults can be overridden with
   `AGENTFORGE_CLINICAL_SMOKE_INTAKE_PATH` and
   `AGENTFORGE_CLINICAL_SMOKE_INTAKE_CATEGORY`.
6. Watch the background `agentforge-worker` process claim jobs as
   `intake-extractor`; health/readiness exposes worker heartbeat and queue
   health.
7. Ask the Clinical Co-Pilot a Week 2 cited question such as:
   `What changed, what should I pay attention to, and what evidence supports it?`
8. Confirm the final answer separates Patient Findings, Needs Human Review,
   Guideline Evidence, and Missing or Not Found.
9. Inspect citations/source review from the answer. Document citations should
   open guarded source review with page/section, quote/value, and a bounding box
   when available or deterministic page/quote fallback when unavailable.
10. Inspect handoffs/evals through the artifacts and commands below.

Rerunnable deployed clinical smoke:

```sh
export AGENTFORGE_SMOKE_USER='assigned-smoke-user'
export AGENTFORGE_SMOKE_PASSWORD='assigned-smoke-password'
export AGENTFORGE_DEPLOYED_URL='https://openemr.titleredacted.cc/'
export AGENTFORGE_VM_SSH_HOST='docker-compose'
php agent-forge/scripts/run-clinical-document-deployed-smoke.php
```

No `clinical-document-deployed-smoke-*.json` artifact is checked into this
checkout. Treat that as an explicit artifact gap; rerun the command above in an
authorized deployed environment to create the artifact.

## Week 2 Proof Snapshot

Canonical Week 2 docs:

- [W2_ARCHITECTURE.md](W2_ARCHITECTURE.md)
- [agent-forge/docs/week2/README.md](agent-forge/docs/week2/README.md)
- [agent-forge/docs/week2/SPECS-W2.md](agent-forge/docs/week2/SPECS-W2.md)
- [agent-forge/docs/week2/PLAN-W2.md](agent-forge/docs/week2/PLAN-W2.md)
- [agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md](agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md)

Current checked-in proof snapshot:

| Check | Artifact or command | Status |
| --- | --- | --- |
| Week 2 clinical-document gate | `agent-forge/eval-results/clinical-document-20260507-202311/summary.json` and `run.json` | 59 cases, verdict `baseline_met`. |
| Tier 0 deterministic orchestration | `agent-forge/eval-results/eval-results-20260507-202234.json` and `LATEST-SUMMARY-TIER0.md` | 32 passed, 0 failed. |
| Source review/browser proof | [agent-forge/docs/submission/browser-proof/MANIFEST.md](agent-forge/docs/submission/browser-proof/MANIFEST.md) | Browser screenshots and request ids for reviewer UI evidence. |
| Cost/latency | [agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md) | Rendered from current clinical-document artifact and available live/deployed baselines. |
| Deployed runtime health | `agent-forge/scripts/health-check.sh` and `agent-forge/scripts/verify-deployed.sh` | Rerunnable; health covers MariaDB 11.8, worker heartbeat, and queue state. |
| Deployed clinical smoke artifact | `php agent-forge/scripts/run-clinical-document-deployed-smoke.php` | Rerunnable; no checked-in `clinical-document-deployed-smoke-*.json` artifact in this checkout. |

Older but still relevant AgentForge baseline proof:

- [agent-forge/eval-results/tier2-live-20260503-202550.json](agent-forge/eval-results/tier2-live-20260503-202550.json)
- [agent-forge/eval-results/deployed-smoke-20260503-201547.json](agent-forge/eval-results/deployed-smoke-20260503-201547.json)
- [agent-forge/docs/submission/FINAL-PROOF-PACK.md](agent-forge/docs/submission/FINAL-PROOF-PACK.md)

## Commands

Local Week 2 clinical-document gate:

```sh
agent-forge/scripts/check-clinical-document.sh
php agent-forge/scripts/run-clinical-document-evals.php
```

Comprehensive AgentForge gate:

```sh
agent-forge/scripts/check-agentforge.sh
```

Week 1/local deterministic baseline:

```sh
agent-forge/scripts/check-local.sh
php agent-forge/scripts/run-evals.php
```

Seed and verify fake demo data:

```sh
agent-forge/scripts/seed-demo-data.sh
agent-forge/scripts/verify-demo-data.sh
```

Deployment/runtime checks:

```sh
agent-forge/scripts/health-check.sh
agent-forge/scripts/verify-deployed.sh
php agent-forge/scripts/run-clinical-document-deployed-smoke.php
php agent-forge/scripts/run-deployed-smoke.php
```

Cost/latency report rendering:

```sh
php agent-forge/scripts/render-clinical-document-cost-latency.php \
  --clinical-run=agent-forge/eval-results/clinical-document-20260507-202311/run.json \
  --clinical-summary=agent-forge/eval-results/clinical-document-20260507-202311/summary.json
```

## Environment Variables

Reviewer-facing Week 2 variables are documented here and in
[agent-forge/.env.sample](agent-forge/.env.sample). Do not commit real
credentials or patient data.

Core model/extraction variables:

```text
AGENTFORGE_DRAFT_PROVIDER
AGENTFORGE_OPENAI_API_KEY
AGENTFORGE_OPENAI_MODEL
AGENTFORGE_VLM_PROVIDER
AGENTFORGE_VLM_MODEL
AGENTFORGE_COHERE_API_KEY
AGENTFORGE_EMBEDDING_MODEL
AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS
AGENTFORGE_CLINICAL_DOCUMENT_ENABLED
```

Deployment and smoke variables:

```text
AGENTFORGE_APP_URL
AGENTFORGE_READYZ_URL
AGENTFORGE_CONNECT_TIMEOUT_SECONDS
AGENTFORGE_MAX_TIME_SECONDS
AGENTFORGE_SMOKE_USER
AGENTFORGE_SMOKE_PASSWORD
AGENTFORGE_DEPLOYED_URL
AGENTFORGE_CLINICAL_SMOKE_PID
AGENTFORGE_CLINICAL_SMOKE_LAB_PATH
AGENTFORGE_CLINICAL_SMOKE_INTAKE_PATH
AGENTFORGE_CLINICAL_SMOKE_LAB_CATEGORY
AGENTFORGE_CLINICAL_SMOKE_INTAKE_CATEGORY
AGENTFORGE_CLINICAL_SMOKE_JOB_TIMEOUT_S
AGENTFORGE_CLINICAL_SMOKE_POLL_INTERVAL_MS
AGENTFORGE_EVAL_RESULTS_DIR
AGENTFORGE_SMOKE_EXECUTOR
AGENTFORGE_DB_USER
AGENTFORGE_DB_PASS
AGENTFORGE_DB_NAME
AGENTFORGE_VM_SSH_HOST
AGENTFORGE_REPO_DIR
AGENTFORGE_COMPOSE_DIR
```

## Evaluation And CI

Evaluation tier taxonomy:

- [agent-forge/docs/evaluation/EVALUATION-TIERS.md](agent-forge/docs/evaluation/EVALUATION-TIERS.md)
- [agent-forge/eval-results/README.md](agent-forge/eval-results/README.md)
- [agent-forge/eval-results/canonical.json](agent-forge/eval-results/canonical.json)

Workflow evidence:

- [.github/workflows/agentforge-evals.yml](.github/workflows/agentforge-evals.yml)
- [.github/workflows/agentforge-tier2.yml](.github/workflows/agentforge-tier2.yml)
- [.github/workflows/agentforge-deployed-smoke.yml](.github/workflows/agentforge-deployed-smoke.yml)

## Artifact Map

Required root submission artifacts:

- [AUDIT.md](AUDIT.md)
- [USERS.md](USERS.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [W2_ARCHITECTURE.md](W2_ARCHITECTURE.md)

Week 2 and operations:

- [agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md](agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md)
- [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md)
- [agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md)
- [agent-forge/docs/evaluation/GAUNTLET-INSTRUCTOR-REVIEWS.md](agent-forge/docs/evaluation/GAUNTLET-INSTRUCTOR-REVIEWS.md)
- [agent-forge/docs/submission/REVIEWER-PACKAGING-PLAN.md](agent-forge/docs/submission/REVIEWER-PACKAGING-PLAN.md)

Durable implementation and proof records:

- [agent-forge/docs/epics/COMPLETED_EPICS_LOG.md](agent-forge/docs/epics/COMPLETED_EPICS_LOG.md)
- [agent-forge/docs/epics/DECISIONS.md](agent-forge/docs/epics/DECISIONS.md)
- [agent-forge/docs/MEMORY.md](agent-forge/docs/MEMORY.md)

## Known Caveats

Production readiness is not claimed.

- No checked-in `clinical-document-deployed-smoke-*.json` artifact exists in
  this checkout, even though the smoke command is implemented and documented.
- The clinical-document cost/latency report honestly labels deterministic
  clinical handoff latency as placeholder when runtime timing is not present in
  the artifact.
- Live clinical-document provider cost remains unknown unless a live
  clinical-document artifact records provider token usage.
- Tier 0 and the clinical-document gate are deterministic local proof. Live
  provider, deployed HTTP/session, and browser-rendered UI proof remain separate
  tiers.
- Demo credentials, deployment secrets, and provider keys are never committed.

## Reviewer Navigation Checklist

Use this checklist from a fresh root checkout:

- [ ] Open [README.md](README.md) and find `AgentForge Reviewer Entry Point`.
- [ ] Open this guide from the README link.
- [ ] Confirm Week 1 and Week 2 demo paths are separate.
- [ ] Confirm [agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md](agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md) is linked.
- [ ] Confirm [agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md) is linked.
- [ ] Confirm `agent-forge/scripts/check-clinical-document.sh` and `agent-forge/scripts/check-agentforge.sh` are visible.
- [ ] Confirm env vars are visible without committed secret values.
- [ ] Confirm known caveats are visible before relying on deployment proof.

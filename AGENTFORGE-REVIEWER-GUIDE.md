# AgentForge Reviewer Guide

## Documented Deployed URL

Documented public app URL:

`https://openemr.titleredacted.cc/`

Final submission links:

| Artifact | URL |
| --- | --- |
| Gauntlet Labs submission | https://labs.gauntletai.com/michaelhabermas/openemr |
| Deployed app | https://openemr.titleredacted.cc/ |
| Demo video | https://www.loom.com/share/bd57c6cd2c5346b397ed7f60ad8a8f32 |
| Social post | https://x.com/habermoose/status/2050766281515700369 |

Current health and readiness check:

```sh
agent-forge/scripts/health-check.sh
```

The script checks the public app URL and the public readiness endpoint when available:

`https://openemr.titleredacted.cc/meta/health/readyz`

Run the health check before any live demo. A passing health check is current reachability evidence only; it is not a production-readiness claim.

## Fake Patient And Demo Credentials Policy

AgentForge uses fake demo data only. Do not use real patient data or real PHI for this submission.

Primary fake patient:

| Field | Value |
| --- | --- |
| OpenEMR pid | `900001` |
| Public patient id | `AF-DEMO-900001` |
| Name | `Alex Testpatient` |
| Purpose | Repeatable chart-orientation demo and eval fixture |

Demo credentials are not committed to the repository. Use credentials assigned out-of-band by the deployed environment owner, then open only the fake demo patient above.

## Demo Path

Start at the repository root:

1. Open `README.md`.
2. Follow the `AgentForge Reviewer Entry Point` link to this guide.
3. Run `agent-forge/scripts/health-check.sh` if reviewing the deployed URL.
4. Authenticate to the deployed or local OpenEMR environment with assigned demo credentials.
5. Open fake patient `900001` / `AF-DEMO-900001`.
6. Use the chart-embedded Clinical Co-Pilot panel.
7. Ask `Show me the recent A1c trend.`
8. Confirm the answer is scoped to the active patient, includes the known A1c values, and displays citations under Sources.

Expected A1c facts for the fake patient are `8.2 %` on `2026-01-09` and `7.4 %` on `2026-04-10`.

## Final Submission Status

Canonical final proof pack: [agent-forge/docs/submission/FINAL-PROOF-PACK.md](agent-forge/docs/submission/FINAL-PROOF-PACK.md).

### Final Delta From Early Review

The early submission review identified three priority gaps. The current final package addresses them as follows:

| Early review gap | Final remediation | Reviewer proof |
| --- | --- | --- |
| `ChartQuestionPlanner` looked like keyword routing, not agentic tool selection | Added LLM-backed chart-section selection through `ToolSelectionProvider` with OpenAI/Anthropic implementations, structured JSON output, deterministic fallback, and server-side normalization guardrails for high-risk clinical scopes | `src/AgentForge/Evidence/ToolSelectionProvider.php`, `src/AgentForge/Evidence/OpenAiToolSelectionProvider.php`, `src/AgentForge/Evidence/ChartQuestionPlanner.php`, `ChartQuestionPlannerTest`, Tier 0 selector cases, Tier 2 selector cases |
| `USERS.md` had only three use cases | Expanded to seven traceable use cases: visit briefing, follow-up drill-down, missing/unclear data, vital trends, medication reconciliation context, allergy review, and encounter/last-plan review | Root `USERS.md` capability matrix |
| `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` were not reviewer-rooted; README lacked the app URL | Moved/kept required artifacts at repo root and added the deployed app URL plus reviewer entry point to root `README.md` | Root `README.md`, `AUDIT.md`, `USERS.md`, `ARCHITECTURE.md` |

AgentForge is agentic only within bounded chart-section tool selection. The model chooses from allowlisted tools; the server validates and may override high-risk selections. That is intentional because this is clinical software: the model can help choose relevant bounded chart sections, but it cannot request arbitrary SQL, expand patient scope, bypass authorization, or turn chart orientation into clinical advice.

Current checked-in proof snapshot:

| Check | Latest local artifact or result | Status |
| --- | --- | --- |
| Code version | Checked-in proof artifacts span the final remediation window; exact per-run hashes are recorded in each eval summary | Current checkout includes the final reviewer remediation work |
| Deployed health | `agent-forge/scripts/health-check.sh` returned public app HTTP 200 and readiness HTTP 200 on 2026-05-03 | Passed |
| Tier 0 fixture/orchestration | `agent-forge/eval-results/eval-results-20260503-185620.json`; `LATEST-SUMMARY-TIER0.md` | 32 passed, 0 failed |
| Tier 1 seeded SQL evidence | `agent-forge/eval-results/sql-evidence-eval-results-20260503-161657.json`; `LATEST-SUMMARY-TIER1.md` | 7 passed, 0 failed |
| Tier 2 live-provider proof | `agent-forge/eval-results/tier2-live-20260503-173557.json`; `LATEST-SUMMARY-TIER2.md` | 14 passed, 0 failed; tokens in/out `5697/2810`; estimated cost `$0.002541`; provider `openai/gpt-5.4-mini` |
| Tier 4 deployed HTTP/session/audit smoke | `LATEST-SUMMARY-TIER4.md`; VM artifact `/root/repos/openemr/agent-forge/eval-results/deployed-smoke-20260503-042413.json` | 4 passed, 0 failed; audit assertions enabled |
| Deployed latency trace | VM artifact `/root/repos/openemr/agent-forge/eval-results/deployed-latency-trace-20260503-190443.json`; `agent-forge/docs/operations/LATENCY-RESULTS.md` | A1c 20/20, p95 `3212 ms`; visit briefing 20/20, p95 `8309 ms`; both under `10000 ms` demo budget |
| Deployed browser proof pack | `agent-forge/docs/submission/browser-proof/` | Four attached browser proof screenshots for A1c trend, visit briefing, missing microalbumin, and clinical-advice refusal |

Latest VM proof supplied on 2026-05-03 after the checked-in summaries:

| Check | Latest VM artifact or result | Status |
| --- | --- | --- |
| Local AgentForge gate from a normal VM shell | `agent-forge/scripts/check-local.sh`; deterministic eval artifact `/root/repos/openemr/agent-forge/eval-results/eval-results-20260503-201548.json` | PASS; PHP syntax, shell syntax, isolated PHPUnit `298 tests / 1547 assertions`, deterministic evals `32 passed, 0 failed`, PHPStan `161/161`, PHPCS no changed AgentForge PHP files |
| Tier 2 live-provider proof from the OpenEMR container | `agent-forge/eval-results/tier2-live-20260503-202550.json` | 14 passed, 0 failed; tokens in/out `5943/2476`; estimated cost `$0.015599`; provider `openai/gpt-5.4-mini` |
| Tier 4 deployed HTTP/session/audit smoke from the VM | `agent-forge/eval-results/deployed-smoke-20260503-201547.json` | 5 passed, 0 failed; aggregate latency `14734 ms`; audit assertions enabled; includes `tier4_visit_briefing_live_verified`; code version `6769aa908887` |

The checked-in summaries above are the local artifacts a reviewer can inspect directly from the repository. The latest Tier 2 live-provider JSON and Tier 4 deployed smoke JSON are also checked in. The latest local gate artifact should be attached to the final submission packet if possible.

To reproduce the deployed smoke proof from the VM host:

```sh
export AGENTFORGE_SMOKE_USER='assigned-smoke-user'
export AGENTFORGE_SMOKE_PASSWORD='assigned-smoke-password'
export AGENTFORGE_VM_SSH_HOST='docker-compose'
export AGENTFORGE_VM_AUDIT_LOG_PATH='/var/log/apache2/error.log'
export AGENTFORGE_DEPLOYED_URL='https://openemr.titleredacted.cc/'
php agent-forge/scripts/run-deployed-smoke.php
```

If the current shell is already inside `agent-forge/`, run `php scripts/run-deployed-smoke.php` instead.

Browser proof request ids from the later deployed UI proof pass: A1c `7cf183f7-5607-403e-9559-e2689a0769aa`, visit briefing `bbbddd92-df71-4835-951b-f14279abe18c`, dosing refusal `ee2fe6c2-56cc-47ac-8731-a3fd885ad9e3`, missing microalbumin `e4ca6da4-9cd9-4222-a9c3-06651098fb49`, and cross-patient refusal `7489b25d-2af1-42d8-9c04-ec7ee3166dbc`.

Browser proof files are stored under `agent-forge/docs/submission/browser-proof/`, with request ids listed in `agent-forge/docs/submission/FINAL-PROOF-PACK.md` and `agent-forge/docs/submission/browser-proof/MANIFEST.md`. The stale/cross-patient conversation boundary is covered by the deployed smoke runner and should return HTTP 403 with `tools_called=[]` and `verifier_result=not_run`.

## How To Reproduce Tests

For a local deterministic gate, run:

```sh
agent-forge/scripts/check-local.sh
```

This performs PHP syntax checks, shell syntax checks, the isolated AgentForge PHPUnit suite, Tier 0 deterministic evals, PHPStan, and PHPCS. If PHPStan cannot bind `127.0.0.1` under a sandboxed terminal, rerun the same command in a normal local shell.

For seeded SQL evidence proof, start the development stack, seed the fake patient data, and run the SQL-backed tier inside the OpenEMR container:

```sh
docker compose -f docker/development-easy/docker-compose.yml up -d openemr
docker compose -f docker/development-easy/docker-compose.yml exec -T openemr /var/www/localhost/htdocs/openemr/agent-forge/scripts/seed-demo-data.sh
docker compose -f docker/development-easy/docker-compose.yml exec -T openemr php /var/www/localhost/htdocs/openemr/agent-forge/scripts/run-sql-evidence-evals.php
```

For live model proof, configure the provider secrets out of band and run:

```sh
php agent-forge/scripts/run-tier2-evals.php
```

For deployed smoke proof, run the command above from the VM host with `AGENTFORGE_VM_SSH_HOST=docker-compose` and `AGENTFORGE_VM_AUDIT_LOG_PATH=/var/log/apache2/error.log`.

## Seed And Verify Commands

Seed the fake demo patient:

```sh
agent-forge/scripts/seed-demo-data.sh
```

Verify the fake demo patient and evidence-contract rows:

```sh
agent-forge/scripts/verify-demo-data.sh
```

The seed script is idempotent for fake patient `900001`. It must not be replaced by database-volume deletion or real patient data import.

## Deterministic Eval Command

Run the repeatable fixture/orchestration eval tier:

```sh
php agent-forge/scripts/run-evals.php
```

The current deterministic eval runner proves request orchestration, authorization decisions, verifier behavior, refusal behavior, citation counting, latency capture shape, and sensitive-log guardrails. It does not prove the full live SQL, live model, browser, deployed endpoint, or real session path.

Evaluation tier taxonomy:

- [agent-forge/docs/evaluation/EVALUATION-TIERS.md](agent-forge/docs/evaluation/EVALUATION-TIERS.md)
- [agent-forge/eval-results/README.md](agent-forge/eval-results/README.md)
- [agent-forge/eval-results/canonical.json](agent-forge/eval-results/canonical.json)

## What's Tested At The Live-LLM Layer

Tier 0 (fixture orchestration) and Tier 1 (seeded SQL evidence) gate every PR via [.github/workflows/agentforge-evals.yml](.github/workflows/agentforge-evals.yml). Tier 2 exercises the same orchestration against the configured live LLM provider (`gpt-5.4-mini` in the final VM proof) instead of the fixture provider.

To run Tier 2 locally, export `AGENTFORGE_OPENAI_API_KEY` (or `AGENTFORGE_ANTHROPIC_API_KEY`) into the environment and invoke:

```sh
php agent-forge/scripts/run-tier2-evals.php
```

The runner refuses to start with the fixture provider, so a model-off pass is never reported as live-provider proof.

The 14-case Tier 2 fixture covers four risk shapes:

| Shape | Example cases | What it proves |
| --- | --- | --- |
| Supported chart questions | `a1c_trend`, `visit_briefing` | The live model produces cited answers grounded in the chart evidence bundle. |
| Missing-data and hallucination pressure | `missing_microalbumin`, `hallucination_pressure_birth_weight` | The verifier blocks unsupported tails; "not found in chart" is preserved under live-model phrasing variation. |
| Refusal classes | `refusal_diagnosis`, `refusal_treatment`, `refusal_dosing`, `refusal_medication_change` | Clinical-advice refusal fires before the model is called. The output JSON shows `verifier_result: not_run` and `failure_reason: clinical_advice_refusal`. |
| Prompt injection and conversation scope | `prompt_injection_user_question`, `prompt_injection_chart_text`, `cross_patient_conversation_reuse`, `stale_conversation` | Injected instructions in the question or in chart content do not change behavior; cross-patient `conversation_id` reuse and expired conversations refuse before chart tools run. |

Output is written to `agent-forge/eval-results/tier2-live-{timestamp}.json` and uploaded as a workflow artifact. The summary records `provider_mode`, `provider_model`, real `aggregate_input_tokens`, real `aggregate_output_tokens`, real `aggregate_estimated_cost_usd`, and per-case verdicts. The nightly schedule lives in [.github/workflows/agentforge-tier2.yml](.github/workflows/agentforge-tier2.yml); per-pass spend is tracked in [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md).

Tier 4 (deployed smoke) exercises the deployed VM end-to-end: Apache + PHP-FPM dispatch, OpenEMR session establishment, `csrf_token_form` validation, session-bound `pid` selection, the `agent_request.php` controller, and the deployed PSR-3 `agent_forge_request` audit-log line. None of those layers are exercised by Tier 0/1/2 or by `run-evals-vm.sh`. Invocation:

```sh
php agent-forge/scripts/run-deployed-smoke.php
```

The runner requires `AGENTFORGE_SMOKE_USER`, `AGENTFORGE_SMOKE_PASSWORD`, and `AGENTFORGE_VM_SSH_HOST` (the audit-log assertion uses SSH grep). Output is written to `agent-forge/eval-results/deployed-smoke-{timestamp}.json` with per-case verdicts, HTTP status, `request_id`, latency, verifier result, and audit-log present/forbidden-key assertions. The result file does not record question text, answer text, or chart content. The nightly schedule and post-deploy invocation live in [.github/workflows/agentforge-deployed-smoke.yml](.github/workflows/agentforge-deployed-smoke.yml). The browser-rendered citation UI on the deployed VM is still validated manually under Tier 4 of [agent-forge/docs/evaluation/EVALUATION-TIERS.md](agent-forge/docs/evaluation/EVALUATION-TIERS.md).

## Artifact Map

Required root submission artifacts:

- [AUDIT.md](AUDIT.md)
- [USERS.md](USERS.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)

Canonical planning and product docs:

- [agent-forge/docs/week1/SPECS.txt](agent-forge/docs/week1/SPECS.txt)
- [agent-forge/docs/week1/PRD.md](agent-forge/docs/week1/PRD.md)
- [agent-forge/docs/week1/PLAN.md](agent-forge/docs/week1/PLAN.md)
- [agent-forge/docs/README.md](agent-forge/docs/README.md)
- [agent-forge/docs/operations/KNOWN-FACTS-AND-NEEDS.md](agent-forge/docs/operations/KNOWN-FACTS-AND-NEEDS.md)

Operations, cost, and evaluation:

- [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md)
- [agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md)
- [agent-forge/docs/evaluation/EVALUATION-TIERS.md](agent-forge/docs/evaluation/EVALUATION-TIERS.md)
- [agent-forge/docs/evaluation/GAUNTLET-INSTRUCTOR-REVIEWS.md](agent-forge/docs/evaluation/GAUNTLET-INSTRUCTOR-REVIEWS.md)
- [agent-forge/docs/submission/REVIEWER-PACKAGING-PLAN.md](agent-forge/docs/submission/REVIEWER-PACKAGING-PLAN.md)

Key implementation and proof records:

- [agent-forge/docs/epics/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md](agent-forge/docs/epics/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md)
- [agent-forge/docs/epics/EPIC3-DEMO-DATA-AND-EVAL-GROUND-TRUTH.md](agent-forge/docs/epics/EPIC3-DEMO-DATA-AND-EVAL-GROUND-TRUTH.md)
- [agent-forge/docs/epics/EPIC_OBSERVABILITY_COST_EVAL.md](agent-forge/docs/epics/EPIC_OBSERVABILITY_COST_EVAL.md)
- [agent-forge/docs/epics/EPIC_COST_ANALYSIS_SCALE_TIERS.md](agent-forge/docs/epics/EPIC_COST_ANALYSIS_SCALE_TIERS.md)
- [agent-forge/docs/epics/EPIC_EVALUATION_HONESTY.md](agent-forge/docs/epics/EPIC_EVALUATION_HONESTY.md)
- [agent-forge/docs/epics/EPIC_CONVERSATION_SCOPE_AND_CITATION_SURFACING.md](agent-forge/docs/epics/EPIC_CONVERSATION_SCOPE_AND_CITATION_SURFACING.md)
- [agent-forge/docs/epics/EPIC_MEDICATION_AUTH_INDEX_REMEDIATION.md](agent-forge/docs/epics/EPIC_MEDICATION_AUTH_INDEX_REMEDIATION.md)
- [agent-forge/docs/epics/EPIC_OBSERVABILITY_LATENCY_AUDIT_LOGS.md](agent-forge/docs/epics/EPIC_OBSERVABILITY_LATENCY_AUDIT_LOGS.md)
- [agent-forge/docs/epics/EPIC_REVIEWER_ENTRY_POINT_SUBMISSION_MAP.md](agent-forge/docs/epics/EPIC_REVIEWER_ENTRY_POINT_SUBMISSION_MAP.md)

## Implemented Proof Summary

Current implemented proof is demo-grade and deterministic-regression-grade, not production-grade.

- Fake patient `900001` has repeatable seed and verification scripts.
- The deterministic eval runner has a checked-in fixture/orchestration result and current runner command.
- Browser proof has been recorded for the fake patient A1c trend with visible citations.
- VM proof has been recorded for the fake patient A1c trend with `verifier_result=passed`.
- Citation surfacing is implemented for current response payloads; it is not persistent conversation memory.
- Structured sensitive request logging records request id, user id, patient id, decision, latency, model, token counts, estimated cost, source ids, and verifier result while avoiding full prompts and full chart text.
- Cost evidence includes the original measured A1c request (`836` input tokens, `173` output tokens, estimated model cost `$0.0002292`) plus later deployed p95 latency proof for A1c and visit briefing. The original local `2,989 ms` and VM `10,693 ms` A1c latencies are historical baselines, not the final latency proof.

Primary proof references:

- [agent-forge/docs/epics/EPIC_OBSERVABILITY_COST_EVAL.md](agent-forge/docs/epics/EPIC_OBSERVABILITY_COST_EVAL.md)
- [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md)
- [agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md)
- [agent-forge/docs/evaluation/EVALUATION-TIERS.md](agent-forge/docs/evaluation/EVALUATION-TIERS.md)

## Known Blockers And Production-Readiness Caveats

Production-Readiness is not claimed.

Known blockers and caveats:

- Tier 0 (fixture) and Tier 1 (seeded SQL) gate every PR; Tier 2 (live model) runs nightly and on demand; Tier 4 (deployed HTTP/session/CSRF/audit-log path) runs nightly and post-deploy via [.github/workflows/agentforge-deployed-smoke.yml](.github/workflows/agentforge-deployed-smoke.yml). The browser-rendered citation UI in Tier 3 (local browser) and the citation UI portion of Tier 4 (deployed browser) are still validated manually.
- Multi-turn conversation state is implemented as short-lived server-bound state. The endpoint issues a `conversation_id` bound to the active OpenEMR session user and patient; cross-patient or expired reuse is refused before chart tools run. Same-patient follow-up re-fetches current chart evidence and re-cites source rows. There is no persistent transcript; prior-turn text is a planner hint only.
- Authorization intentionally fails closed outside direct provider, encounter provider, and supervisor relationships.
- Production latency needs p95 proof under the accepted budget, not single-request demo measurements.
- Observability has structured logs and stage timings, but production dashboards, SLOs, alerting, retention governance, and access-control operations remain incomplete.
- Broader medication reconciliation, duplicate/conflict resolution, and production index migration proof remain limited.
- Demo credentials, deployment secrets, and provider keys are never committed here.

## Reviewer Navigation Checklist

Use this checklist from a fresh root checkout:

- [ ] Open `README.md` and find `AgentForge Reviewer Entry Point`.
- [ ] Open `AGENTFORGE-REVIEWER-GUIDE.md` from the README link.
- [ ] Confirm root `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` exist.
- [ ] Confirm the documented deployed URL and `agent-forge/scripts/health-check.sh` are visible.
- [ ] Confirm fake patient `900001` / `AF-DEMO-900001` is visible without any real PHI.
- [ ] Confirm seed and verify commands are visible.
- [ ] Confirm `php agent-forge/scripts/run-evals.php` is visible.
- [ ] Confirm `agent-forge/docs/operations/COST-ANALYSIS.md` is linked.
- [ ] Confirm implemented proof and known blockers are visible.
- [ ] Confirm local markdown links in this guide resolve from the repository root.

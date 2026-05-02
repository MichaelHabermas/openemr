# AgentForge Reviewer Guide

## Documented Deployed URL

Documented public app URL:

`https://openemr.titleredacted.cc/`

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

## Artifact Map

Required root submission artifacts:

- [AUDIT.md](AUDIT.md)
- [USERS.md](USERS.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)

Canonical planning and product docs:

- [agent-forge/docs/SPECS.txt](agent-forge/docs/SPECS.txt)
- [agent-forge/docs/PRD.md](agent-forge/docs/PRD.md)
- [agent-forge/docs/PLAN.md](agent-forge/docs/PLAN.md)
- [agent-forge/docs/README.md](agent-forge/docs/README.md)
- [agent-forge/docs/operations/KNOWN-FACTS-AND-NEEDS.md](agent-forge/docs/operations/KNOWN-FACTS-AND-NEEDS.md)

Operations, cost, and evaluation:

- [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md)
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
- Cost evidence includes one measured A1c request: `836` input tokens, `173` output tokens, estimated model cost `$0.0002292`, local latency `2,989 ms`, and VM latency `10,693 ms`.

Primary proof references:

- [agent-forge/docs/epics/EPIC_OBSERVABILITY_COST_EVAL.md](agent-forge/docs/epics/EPIC_OBSERVABILITY_COST_EVAL.md)
- [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md)
- [agent-forge/docs/evaluation/EVALUATION-TIERS.md](agent-forge/docs/evaluation/EVALUATION-TIERS.md)

## Known Blockers And Production-Readiness Caveats

Production-Readiness is not claimed.

Known blockers and caveats:

- Live SQL, live model, local browser/session, deployed browser/session, and real authorization paths are not fully automated eval tiers.
- Multi-turn conversation state is not implemented; current questions are independent single-shot requests against the active patient.
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


# AgentForge Reviewer Guide

This is the repository-root map for grading the AgentForge Clinical Co-Pilot submission. The canonical working docs remain under `agent-forge/docs/`; the required root artifacts `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` are byte-for-byte copies of the canonical docs at the time of this packaging pass.

## Quick Start

| Item | Value |
| --- | --- |
| Documented public app URL | `https://openemr.titleredacted.cc/` |
| Documented readiness URL | `https://openemr.titleredacted.cc/meta/health/readyz` |
| Fake OpenEMR pid | `900001` |
| Fake public patient id | `AF-DEMO-900001` |
| Fake patient name | Alex Testpatient |
| Primary demo prompt | `Show me the recent A1c trend.` |
| Expected A1c facts | `8.2 %` on `2026-01-09`; `7.4 %` on `2026-04-10` |

Demo credentials are intentionally not published in this repository-root guide. Use the credentials assigned with the deployed demo environment, or use the standard local OpenEMR development credentials only when running a local Docker environment. Current public availability must still be verified with `agent-forge/scripts/health-check.sh` before a live demo.

## Reviewer Path

1. Read the required root docs: `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md`.
2. Read the implementation source map in `agent-forge/docs/PRD.md` and the remediation plan in `agent-forge/docs/PLAN.md`.
3. Check deployment proof in `agent-forge/docs/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md`.
4. Check demo-data ground truth in `agent-forge/docs/EPIC3-DEMO-DATA-AND-EVAL-GROUND-TRUTH.md`.
5. Read the evaluation taxonomy in `agent-forge/docs/EVALUATION-TIERS.md`.
6. Run deterministic evals with `php agent-forge/scripts/run-evals.php`.
7. Inspect the committed fixture snapshot at `agent-forge/eval-results/canonical.json`, or run `php agent-forge/scripts/run-evals.php` and open the printed timestamped path under `agent-forge/eval-results/`.
8. Review measured cost and latency baselines in `agent-forge/docs/COST-ANALYSIS.md`.
9. Review the honest limitation list below before evaluating production readiness.

## Commands

Check root artifacts do not drift from canonical docs:

```sh
cmp AUDIT.md agent-forge/docs/AUDIT.md
cmp USERS.md agent-forge/docs/USERS.md
cmp ARCHITECTURE.md agent-forge/docs/ARCHITECTURE.md
```

Check current public app and readiness endpoint availability:

```sh
agent-forge/scripts/health-check.sh
```

Seed fake demo data in a local or VM Docker-backed OpenEMR environment:

```sh
agent-forge/scripts/seed-demo-data.sh
```

Verify fake demo data:

```sh
agent-forge/scripts/verify-demo-data.sh
```

Run deterministic fixture/orchestration evals:

```sh
php agent-forge/scripts/run-evals.php
```

## Artifact Map

| Artifact | Path | What It Proves |
| --- | --- | --- |
| Required audit | `AUDIT.md` and `agent-forge/docs/AUDIT.md` | Security, architecture, performance, data-quality, and compliance risks driving the agent design. |
| Required user doc | `USERS.md` and `agent-forge/docs/USERS.md` | Target user, workflow, use cases, non-goals, and current single-shot limitation. |
| Required architecture | `ARCHITECTURE.md` and `agent-forge/docs/ARCHITECTURE.md` | Integration point, trust boundaries, data access, verification, observability, eval status, and roadmap. |
| PRD | `agent-forge/docs/PRD.md` | Product scope, requirements, implementation facts, and delivery gates. |
| Plan | `agent-forge/docs/PLAN.md` | Epic-by-epic remediation plan and acceptance criteria. |
| Reviewer packaging proof | `agent-forge/docs/EPIC8-REVIEWER-SUBMISSION-PACKAGING.md` | Epic 8 implementation plan, traceability, proof commands, and claim checklist. |
| Cost analysis | `agent-forge/docs/COST-ANALYSIS.md` | Measured local/VM A1c request cost and the planned user-tier rewrite. |
| Evaluation tiers | `agent-forge/docs/EVALUATION-TIERS.md` | Tiered proof taxonomy, release rule, planned live SQL/model/browser/deployed smoke checks, and fixture-proof boundaries. |
| Deployment proof | `agent-forge/docs/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md` | Public URL, deploy/rollback workflow, health checks, and VM seed proof. |
| Demo-data proof | `agent-forge/docs/EPIC3-DEMO-DATA-AND-EVAL-GROUND-TRUTH.md` | Fake patient facts, expected demo answers, seed command, and verifier contract. |
| Eval cases | `agent-forge/fixtures/eval-cases.json` | Deterministic fixture cases for supported behavior and safety-critical failures. |
| Eval runner | `agent-forge/scripts/run-evals.php` | Repeatable local fixture/orchestration eval execution. |
| Eval results | `agent-forge/eval-results/canonical.json` | Committed passing fixture snapshot; timestamped runs from `run-evals.php` are local-only (gitignored). |
| Instructor reviews | `agent-forge/docs/GAUNTLET-INSTRUCTOR-REVIEWS.md` | External-review findings mapped into remediation epics. |

## Demo Path

After `agent-forge/scripts/health-check.sh` returns green, open the public app or use a local OpenEMR instance, authenticate with the configured demo credentials, open fake patient `900001` / `AF-DEMO-900001`, and use the AgentForge panel in the patient chart. The lowest-risk demo prompt is:

```text
Show me the recent A1c trend.
```

The answer should cite or trace to the seeded `procedure_result` evidence for `8.2 %` on `2026-01-09` and `7.4 %` on `2026-04-10`. The request log proof in `agent-forge/docs/EPIC_OBSERVABILITY_COST_EVAL.md` records local and VM browser verification for this path.

## Implemented Proof

- Embedded patient-chart panel and server-side request endpoint exist for active-chart questions.
- Patient-specific authorization fails closed before chart evidence is read.
- Evidence tools use bounded, read-only, patient-scoped SQL.
- The LLM draft is verified deterministically before display.
- Structured request logs capture request id, user id, patient id, decision, total latency, question type, tools, source ids, model, token counts, estimated cost, failure reason, and verifier result.
- Tier 0 fixture/orchestration evals currently pass for safety-critical and demo-path cases; see `agent-forge/eval-results/canonical.json` for a checked-in snapshot. This is not full live-agent proof; see `agent-forge/docs/EVALUATION-TIERS.md`.
- Local and VM browser proof exists for the A1c trend path with real OpenAI drafting. The latest Epic 8 deployed verification on 2026-05-01 passed public health, readiness, demo-data verification, browser A1c answer, and request-log inspection.

## Planned Remediation

These are not claimed as complete:

- Full live-path eval tiers for live SQL, live model, browser UI, deployed endpoint, and real session behavior.
- Production-grade cost analysis at 100, 1K, 10K, and 100K users.
- Persistent multi-turn conversation state, transcript display, retention policy, and follow-up evals.
- Physician-visible citation UI as a completed acceptance item.
- Verifier hardening against model-supplied claim-type bypass and brittle matching.
- PHI-minimizing selective evidence-tool routing.
- Complete medication evidence across `prescriptions`, `lists`, and `lists_medication`.
- Broader care-team, facility, schedule, group, and delegation authorization modeling.
- Composite-index remediation and performance proof for agent query shapes.
- Sensitive audit-log retention/access policy, per-step timing, aggregation, SLOs, alerting, and latency budget.

## Production-Readiness Statement

AgentForge is a safety-first clinical chart-orientation prototype with concrete deployment, eval, logging, and manual proof. It should not be described as hospital-production-ready until the planned remediation above is implemented or explicitly scoped out with reviewer-visible justification.

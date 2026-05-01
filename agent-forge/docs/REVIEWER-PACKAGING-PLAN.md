# Reviewer Packaging Plan

## Purpose

This is a documentation-only remediation record for making AgentForge reviewable from the repository root. Epic 8 completed the root packaging layer; later epics still own cost, live-eval, and runtime production-readiness remediation.

`SPECS.txt` expects required submission artifacts at the repository root, including `./AUDIT.md`, `./USERS.md`, and `./ARCHITECTURE.md`. The canonical docs live under `agent-forge/docs/`, and the root `README.md` now points reviewers to the AgentForge reviewer guide. Instructor reviews identified root packaging as a gating submission risk.

## Current State

Already present under `agent-forge/docs/`:

- `AUDIT.md`
- `USERS.md`
- `ARCHITECTURE.md`
- `PRD.md`
- `PLAN.md`
- `COST-ANALYSIS.md`
- `GAUNTLET-INSTRUCTOR-REVIEWS.md`
- Epic proof notes for deployment, demo data, evidence tools, model verification, and observability/evals

Completed in Epic 8:

- Root-level `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` expected by the spec are present.
- Root docs were verified as byte-for-byte identical to their canonical `agent-forge/docs/` versions during the Epic 8 packaging pass.
- Root `README.md` points reviewers to `AGENTFORGE-REVIEWER-GUIDE.md`.
- `AGENTFORGE-REVIEWER-GUIDE.md` maps documented deployed URL, fake patient, demo flow, seed verification, eval commands, cost analysis, implemented proof, current health-check command, and known limitations.

Still intentionally pending:

- Epic 9 production user-tier cost rewrite.
- Epic 10 live-path eval tiers.
- Epic 11+ runtime remediation items listed in the reviewer guide.

## Packaging Remediation Checklist

### Root-Level Required Docs

- Packaging choice: root copies are used for `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md`, while `agent-forge/docs/` remains the canonical documentation workspace.
- Drift check:

```sh
cmp AUDIT.md agent-forge/docs/AUDIT.md
cmp USERS.md agent-forge/docs/USERS.md
cmp ARCHITECTURE.md agent-forge/docs/ARCHITECTURE.md
```

- Root-level content remains reviewer-facing and free of implementation claims that are not already proven.

Definition of done:

- A reviewer starting at the repository root can open `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` without knowing about `agent-forge/docs/`.

### Reviewer Landing Page

- Done: root `README.md` includes an AgentForge reviewer section that links to `AGENTFORGE-REVIEWER-GUIDE.md`.
- Done: the reviewer guide includes:
  - Deployed app URL.
  - Current public health-check command and the requirement to verify availability before a live demo.
  - Fake patient identifiers and demo credential handling.
  - Demo path through the OpenEMR UI.
  - Seed command and seed verification command.
  - Eval command and explanation of eval tiers.
  - Artifact map for audit, users, architecture, plan, cost analysis, and instructor-review remediation.
  - Known limitations and production-readiness blockers.

Definition of done:

- A grader can follow one page from clone to review without asking for missing paths or commands.

### Evaluation And Demo Honesty

- Label fixture evals as deterministic verifier/orchestration proof.
- Add links or instructions for future seeded SQL, live model, browser UI, deployed endpoint, and real session eval tiers once implemented.
- Do not describe fixture-only green results as full live-agent validation.

Definition of done:

- The reviewer page states which eval tiers exist, which are planned, and what each tier proves.

### Cost And Scale Artifacts

- Link to `COST-ANALYSIS.md`.
- State that the current A1c measurements are baselines, not production forecasts.
- Point reviewers to the required 100 / 1K / 10K / 100K user-tier rewrite once Epic 9 is complete.

Definition of done:

- A reviewer can identify cost assumptions, measured values, unknowns, and scale-tier architecture changes.

### Production-Readiness Disclosures

The reviewer landing page must explicitly disclose that production readiness is blocked until these are completed or scoped out:

- Root-level required artifact packaging.
- User-tier cost analysis with infrastructure/support assumptions.
- Live-path eval tiers.
- Multi-turn conversation state or an explicit accepted single-shot scope.
- Citation UI surfacing.
- Verifier hardening against claim-type bypass and brittle substring matching.
- PHI-minimizing selective tool routing.
- Medication evidence across `prescriptions`, `lists`, and `lists_medication`.
- Care-team/facility/schedule/delegation authorization modeling.
- Composite-index remediation planning and proof.
- Sensitive audit-log retention/access policy.
- Per-step timing, aggregation, SLOs, alerting, and latency budget.

Definition of done:

- The reviewer can tell what is implemented today, what is a v1 limitation, what is planned, and what blocks production-readiness claims.

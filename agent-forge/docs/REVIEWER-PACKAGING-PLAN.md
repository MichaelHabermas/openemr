# Reviewer Packaging Plan

## Purpose

This is a documentation-only remediation plan for making AgentForge reviewable from the repository root. It does not claim the packaging work is complete today.

`SPECS.txt` expects required submission artifacts at the repository root, including `./AUDIT.md`, `./USERS.md`, and `./ARCHITECTURE.md`. The current canonical docs live under `agent-forge/docs/`, and the root `README.md` remains the generic OpenEMR README. Instructor reviews identified this as a gating submission risk.

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

Not yet complete:

- Root-level `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` expected by the spec.
- Root reviewer landing page or README section that points to AgentForge artifacts.
- One obvious reviewer map for deployed URL, fake patient, demo flow, seed verification, eval commands, cost analysis, and known limitations.

## Packaging Remediation Checklist

### Root-Level Required Docs

- Decide whether root-level required docs are copies, symlinks, or short root stubs that link to canonical docs.
- Ensure root-level docs cannot drift from canonical `agent-forge/docs/` content.
- Keep root-level content reviewer-facing and free of implementation claims that are not already proven.

Definition of done:

- A reviewer starting at the repository root can open `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` without knowing about `agent-forge/docs/`.

### Reviewer Landing Page

- Add an AgentForge reviewer section to the root `README.md` or create a root-level reviewer guide linked from the README.
- Include:
  - Deployed app URL.
  - Demo user and fake patient identifiers.
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

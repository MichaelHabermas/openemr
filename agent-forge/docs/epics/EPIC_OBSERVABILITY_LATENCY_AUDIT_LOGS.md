# Epic: Observability, Latency Budget, And Sensitive Audit Logs

**Generated:** 2026-05-02
**Scope:** AgentForge documentation, proof, sensitive audit-log policy, observability maturity plan, and latency budget
**Status:** Implemented as documentation-and-proof remediation; human log-policy review remains unchecked until actually performed
**Latency budget update (2026-05-02):** A first-principles latency review produced a 12-item recommendation list; eight items shipped against `src/AgentForge` in the May 2026 latency pass. See [`../operations/LATENCY-OPTIMIZATION-2026-05.md`](../operations/LATENCY-OPTIMIZATION-2026-05.md) for what shipped, the regression caught mid-pass, and what is still owed (cache hit-rate telemetry, before/after `StageTimer` proof, Tier 4 deployed smoke verification).

---

## Overview

Epic 14 corrects the logging and observability claims around AgentForge. The runtime logging and timing foundation now lives under `src/AgentForge/Observability`: `SensitiveLogPolicy`, `RequestLog`, `AgentTelemetry`, and `StageTimer`. No dashboard, alerting provider, log shipper, database migration, or telemetry backend is added in this pass. Runtime dashboards, alerting infrastructure, and a new telemetry backend are not implemented.

The first-principles constraint is that production readiness should be blocked by unclear sensitive audit controls, missing SLO/alert policy, or an unaccepted `10,693 ms` VM A1c path. The current local `2,989 ms` and VM `10,693 ms` A1c measurements are accepted as demo evidence only because the output was verified and cited.

---

## Tasks

### Task 14.1.1: Rename PHI-Free Claims To PHI-Minimized Sensitive Audit Logging

**Status:** Implemented; human verification pending
**Description:** Remove broad de-identification claims and document AgentForge logs as PHI-minimized sensitive audit metadata because they contain user, patient, and source identifiers.
**Acceptance Map:** `PLAN.md` Task 14.1.1; `ARCHITECTURE.md` Observability; `PRD.md` technical risks; `SPECS.txt` security and audit logging requirements.
**Proof Required:** Document tests proving reviewer-facing docs use sensitive audit-log language, document allowed/forbidden fields, and include retention/access expectations.

**Subtasks:**

- [x] Search reviewer-facing docs for `PHI-free`, `PHI free`, de-identification, and logging claims that imply de-identification.
- [x] Document allowed sensitive audit fields: request id, user id, patient id, decision, timestamp, total latency, `stage_timings_ms`, question type, tools, source ids, model, token counts, estimated cost, failure reason, and verifier result.
- [x] Document forbidden default log content: raw question, full answer, full prompt, full chart text, patient name, credentials, and raw exception internals.
- [x] Document restricted operational access, retention governance, and review responsibility before production readiness.
- [x] Add isolated document proof for the policy.

**Automated Proof:**

- `ARCHITECTURE.md` describes logs as PHI-minimized sensitive audit logs, not de-identified logs.
- `ARCHITECTURE.md`, `PRD.md`, `AUDIT.md`, and this epic document describe forbidden raw content and retention/access expectations.
- `ObservabilityLatencyAuditLogDocumentTest` guards against broad PHI-free or de-identified claims in reviewer-facing docs.

**Pending Human Verification:** A reviewer still needs to inspect the policy and confirm they understand which sensitive data is present and why.

**Suggested Commit:** `docs(agent-forge): define sensitive audit log policy`

### Task 14.2.1: Plan Per-Step Timing, Aggregation, SLOs, And Alerts

**Status:** Implemented; human verification pending
**Description:** Document the current timing fields honestly and define the work still required for production observability.
**Acceptance Map:** `PLAN.md` Task 14.2.1; `ARCHITECTURE.md` Observability; `SPECS.txt` observability and operations questions.
**Proof Required:** Document tests proving current structured logs plus `stage_timings_ms` are distinguished from dashboards, aggregation, SLOs, alerts, and percentile reporting.

**Subtasks:**

- [x] Document current implemented timing: `StageTimer` records evidence-tool, draft, and verification durations into `AgentTelemetry::stageTimingsMs`.
- [x] Document remaining target spans and aggregation needs: authorization, routing, response serialization, total request, p50/p95/p99 latency, verifier-failure tracking, failure rate, and cost anomaly tracking.
- [x] Propose SLO and alert thresholds before production readiness without claiming dashboards or alerts exist.
- [x] Add isolated document proof for the observability distinction.

**Automated Proof:**

- `ARCHITECTURE.md` now states that current logs include `stage_timings_ms` and that aggregation, dashboards or queries, SLOs, and alerts are not implemented.
- `PLAN.md`, `AUDIT.md`, `PRD.md`, and `COST-ANALYSIS.md` no longer describe all per-step timing as unavailable.
- `ObservabilityLatencyAuditLogDocumentTest` verifies current and unavailable observability language.

**Pending Human Verification:** A reviewer still needs to confirm whether the docs make clear which stages are currently timed and which observability features remain unavailable.

**Suggested Commit:** `docs(agent-forge): document observability maturity gates`

### Task 14.3.1: Define Latency Budget Against Measured VM Baseline

**Status:** Implemented; human verification pending
**Description:** Record the measured local and VM A1c latencies, define the demo and production-readiness gates, and tie optimization work to measured stages.
**Acceptance Map:** `PLAN.md` Task 14.3.1; `PRD.md` technical risks; `COST-ANALYSIS.md` measured baseline; `SPECS.txt` deployment and operations questions.
**Proof Required:** Document tests proving the docs include the local `2989 ms` and VM `10693 ms` baselines, demo-only acceptance, p95 production-readiness gate, and concrete optimization plan.

**Subtasks:**

- [x] Record the local A1c path baseline: `2,989 ms` / `2989 ms`.
- [x] Record the VM A1c path baseline: `10,693 ms` / `10693 ms`.
- [x] Define current VM latency as acceptable for demo evidence only.
- [x] Define production readiness as blocked until p95 verified-answer-or-clear-failure latency is under 10 seconds for the demo path and per-stage timing identifies bottlenecks.
- [x] Tie optimization work to selective routing, evidence-size reduction, model timeout tuning, citation-safe prompt/cache strategy, query/index proof, and infrastructure measurement.
- [x] Add isolated document proof for the budget and release gate.

**Automated Proof:**

- `ARCHITECTURE.md`, `AUDIT.md`, `PRD.md`, `COST-ANALYSIS.md`, and this epic file include the measured local and VM baselines.
- The VM measurement is accepted only for demo evidence, not production readiness.
- `ObservabilityLatencyAuditLogDocumentTest` verifies both baselines and production-readiness blocking language.

**Pending Human Verification:** A reviewer still needs to confirm they can see the current `10,693 ms` VM result and the plan to reduce or justify it.

**Suggested Commit:** `docs(agent-forge): define latency budget gate`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed; this epic changes no runtime boundary.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [ ] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks; no fixture data is needed for this documentation-only epic.

---

## Acceptance Matrix

| Requirement | Implementation / Proof |
| --- | --- |
| Docs describe logs as PHI-minimized sensitive audit logs. | `ARCHITECTURE.md`; `PLAN.md`; this epic; `ObservabilityLatencyAuditLogDocumentTest`; human review pending. |
| Allowed and forbidden log fields are documented. | `ARCHITECTURE.md`; `PLAN.md`; this epic. |
| Retention and access-control expectations are documented. | `ARCHITECTURE.md`; `PLAN.md`; this epic. |
| Remaining PHI-free wording is not used as a broad de-identification claim. | `ObservabilityLatencyAuditLogDocumentTest`; remaining historical review quotes are treated as instructor-review source material, not current claim text. |
| Current per-stage timing is documented honestly. | `ARCHITECTURE.md`; `AUDIT.md`; `PRD.md`; `COST-ANALYSIS.md`; this epic. |
| Aggregation, dashboards or queries, SLOs, and alerts are not claimed as implemented. | `ARCHITECTURE.md`; `PRD.md`; this epic; document tests. |
| Latency budget includes local and VM baselines. | `ARCHITECTURE.md`; `AUDIT.md`; `PRD.md`; `COST-ANALYSIS.md`; this epic. |
| Production readiness is blocked until latency has p95 proof under budget and bottleneck timing. | `ARCHITECTURE.md`; `PRD.md`; `COST-ANALYSIS.md`; this epic; document tests; human review pending. |

---

## Commands Run

```bash
composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge\\ObservabilityLatencyAuditLogDocumentTest'
```

Result: passed, 5 tests and 99 assertions.

```bash
composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'
```

Result: passed, 198 tests and 787 assertions.

```bash
agent-forge/scripts/check-local.sh
```

Result: first sandboxed run passed whitespace, syntax, isolated PHPUnit, and deterministic evals, then failed during PHPStan with `Failed to listen on "tcp://127.0.0.1:0": Operation not permitted (EPERM)`. Rerunning the same command with approved escalation passed the full local AgentForge check, including deterministic evals, focused PHPStan, and PHPCS.

---

## Change Log

- 2026-05-02: Added sensitive audit-log policy, current/unavailable observability language, latency budget, and document-test coverage.

---

## Definition Of Done Gate

Can I call this done?

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes
- Required manual checks executed and captured? no; human reviewer inspection remains unchecked
- Required fixtures/data/users for proof exist? yes; none required
- Security/privacy/logging/error-handling requirements verified? yes for documentation and regression proof; human policy review remains unchecked
- Known limitations and unavailable relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left uncommitted? yes. Current index/worktree staging should be reviewed before commit; no commit or push was performed.

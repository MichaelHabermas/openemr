# Epic: Seeded SQL Evidence Eval Tier

**Generated:** 2026-05-02T20:53:00-04:00
**Scope:** backend/evaluation
**Status:** In Progress

---

## Overview

Epic 21 adds a dedicated Tier 1 SQL-backed evidence eval runner for AgentForge. The runner is separate from deterministic fixture/orchestration evals and validates real SQL evidence repositories, evidence tools, seeded source ids, missing-data signals, stale/inactive exclusions, and SQL-backed authorization decisions.

---

## Tasks

### Task 21.1.1: Build A Real Database Eval Runner Against Demo Data
**Status:** [ ] In Progress
**Description:** Add a model-free SQL evidence eval tier that runs against seeded OpenEMR demo data and writes SQL eval results only after the SQL runner actually executes.
**Acceptance Map:** `PLAN.md` Epic 21; `EVALUATION-TIERS.md` Tier 1; `demo-patient-ground-truth.json`.
**Proof Required:** Isolated PHPUnit for loader/assertions/docs, fixture eval regression, and real SQL runner execution after seeded DB is available.

**Subtasks:**
- [x] Add a dedicated SQL evidence eval runner separate from `run-evals.php`.
- [x] Derive SQL eval cases from seeded demo-patient ground truth.
- [x] Run real `SqlChartEvidenceRepository` evidence tools in the SQL runner.
- [x] Add missing reason-for-visit evidence from linked encounters.
- [x] Include SQL-backed authorization cases for allowed relationship, unrelated user refusal, and chart mismatch refusal.
- [x] Add isolated proof for SQL eval case loading and assertion behavior.
- [x] Update evaluation docs/readme to distinguish Tier 0 fixture and Tier 1 SQL-backed results.
- [ ] Run `agent-forge/scripts/seed-demo-data.sh`, `agent-forge/scripts/verify-demo-data.sh`, and `php agent-forge/scripts/run-sql-evidence-evals.php` against an available seeded database.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agent-forge): add seeded sql evidence eval tier`

---

## Proof Log

- `vendor/bin/phpunit -c phpunit-isolated.xml --filter 'SqlEvidenceEvalRunnerTest|EvaluationTiersDocumentTest|EvidenceToolsTest|SqlChartEvidenceRepositoryIsolationTest'` passed: 39 tests, 226 assertions.
- `vendor/bin/phpunit -c phpunit-isolated.xml --filter AgentForge` passed: 244 tests, 1231 assertions.
- `php agent-forge/scripts/run-evals.php` passed: 28 passed, 0 failed.
- `php agent-forge/scripts/run-sql-evidence-evals.php` did not write a result file because SQL preflight failed: `mysqli_query(): Argument #1 ($mysql) must be of type mysqli, false given`.
- `agent-forge/scripts/seed-demo-data.sh` timed out waiting for MySQL after 120 seconds.

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [ ] Human verification items are checked only after they were actually performed.
- [ ] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Change Log

- 2026-05-02: Implemented SQL eval runner, SQL eval classes, encounter reason evidence surfacing, isolated tests, and documentation updates. Real SQL acceptance proof remains blocked until MySQL/OpenEMR database is available and seeded.

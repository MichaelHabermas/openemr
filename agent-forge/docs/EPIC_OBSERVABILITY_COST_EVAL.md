# Epic: Observability, Cost, And Eval Runner

**Generated:** 2026-04-30
**Scope:** AgentForge observability, model usage/cost tracking, deterministic evals, and local smoke proof
**Status:** Implemented; Local And VM Browser Verification Complete

---

## Overview

Epic 7 makes the AgentForge path measurable. The implementation extends the existing PHI-free PSR request log with sanitized telemetry, records draft provider usage and unknown cost explicitly when pricing is not configured, and adds a deterministic in-process eval runner for safety and demo-path cases.

The clinician-facing response contract is unchanged. Observability remains internal: request logs include ids, decisions, latency, question type, tools called, source ids, model usage, failure reason, and verifier result, but not raw questions, full answers, full prompts, patient names, or full chart text.

---

## Tasks

### Task 7.1.1: Define And Extend The Log Contract

**Status:** [x] Complete
**Acceptance Map:** `PLAN.md` Epic 7.1.1; `PRD.md` observability and PHI-minimized logging requirements.
**Proof Required:** Isolated tests for complete success/failure log context and absence of PHI-bearing fields.

**Proof:**

- `RequestLog` now merges an optional `AgentTelemetry` DTO into the PSR log context.
- `AgentRequestHandler` passes sanitized telemetry from telemetry-aware handlers into endpoint logging.
- `RequestLogTest` verifies required Epic 7 fields and confirms forbidden PHI-bearing keys are absent.
- Focused PHPUnit passed: `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'` with 97 tests and 311 assertions.

**Suggested Commit:** `feat(agent-forge): extend request telemetry`

### Task 7.1.2: Add Token And Cost Tracking

**Status:** [x] Complete
**Acceptance Map:** `PLAN.md` Epic 7.1.2; `PRD.md` cost and usage observability requirements.
**Proof Required:** Isolated tests proving fixture usage appears in telemetry and unknown pricing is not guessed.

**Proof:**

- `VerifiedAgentHandler` records `DraftUsage` model, input tokens, output tokens, and estimated cost after drafting.
- Fixture-first drafting logs `fixture-draft-provider`, `0` input tokens, `0` output tokens, and `estimated_cost: null`.
- OpenAI drafting logs configured model name, provider token counts, and estimated cost when pricing env vars are configured.
- `VerifiedAgentHandlerTest` verifies fixture token/cost telemetry and verification result.
- `OpenAiDraftProviderTest` verifies provider usage parsing and optional estimated cost without making a live network call.

**Suggested Commit:** `feat(agent-forge): record fixture draft usage`

### Task 7.2.1: Create Eval Dataset

**Status:** [x] Complete
**Acceptance Map:** `PLAN.md` Epic 7.2.1; `PRD.md` evaluation strategy; safety-critical eval list in `PLAN.md`.
**Proof Required:** Deterministic eval fixture mapping each case to expected behavior.

**Proof:**

- Added `agent-forge/fixtures/eval-cases.json`.
- Cases cover visit briefing, active medications, A1c trend, missing data, clinical advice refusal, unauthorized access, cross-patient request, prompt injection, malicious chart text, tool failure, hallucinated claim, unclear role boundary, and latency capture.
- Safety-critical cases are flagged so any failure blocks the runner.

**Suggested Commit:** `test(agent-forge): add deterministic eval cases`

### Task 7.2.2: Run Evals And Save Results

**Status:** [x] Complete
**Acceptance Map:** `PLAN.md` Epic 7.2.2; final submission eval dataset/results requirement.
**Proof Required:** Repeatable local eval runner and saved result artifact.

**Proof:**

- Added executable runner: `agent-forge/scripts/run-evals.php`.
- Runner executes AgentForge in process through parser, authorization gate, verified handler, fixture draft provider, verifier, response checks, telemetry logging context, and latency capture.
- Runner saves JSON results under `agent-forge/eval-results/`.
- Local run passed: 13 passed, 0 failed.
- Saved result: `agent-forge/eval-results/eval-results-20260430-233329.json`.

**Suggested Commit:** `test(agent-forge): add eval runner`

### Task 7.2.3: Add End-To-End Smoke Proof

**Status:** [x] Complete For Local In-Process, Local Browser, And VM Browser Smoke
**Acceptance Map:** `PLAN.md` Epic 7.2.3; full path proof requirement.
**Proof Required:** Local smoke proof and precise browser verification step.

**Proof:**

- The eval runner covers the local composition path: request parse -> auth gate -> evidence tools -> fixture draft -> verifier -> response citations -> PHI-free log context -> latency captured.
- The result artifact includes `log_context` for each case with `verifier_result`, `source_ids`, `tools_called`, model usage, latency, and no forbidden PHI-bearing keys.
- Local browser/UI verification was performed against fake patient `900001`.
- VM browser/UI verification was performed against fake patient `900001`.
- Live OpenAI API-key verification passed inside the recreated OpenEMR container.
- The PSR request telemetry is emitted at warning level so it is visible under OpenEMR's default Docker logger threshold.

**Local Manual Browser Verification:**

1. Recreated local Docker OpenEMR with `docker compose up -d --force-recreate openemr`.
2. Confirmed the container was healthy and saw `AGENTFORGE_DRAFT_PROVIDER=openai`, `AGENTFORGE_OPENAI_MODEL=gpt-4o-mini`, and a present API key without printing the secret.
3. Opened local OpenEMR as admin and opened Alex Testpatient, fake patient `900001`.
4. Asked: `Tell me about this chart`.
5. Observed patient-specific chart summary covering demographics, active problems, active medications, recent A1c labs, and last plan.
6. Asked: `Show me the recent A1c trend.`
7. Initial local attempts exposed two defects, both fixed before marking verification complete:
   - OpenAI transport timeout surfaced as generic processing failure; timeout is now configurable and provider transport failures are classified separately.
   - Verifier-internal rejected draft claim text was displayed to the clinician; verifier warnings are now generic and PHI-minimized.
8. Final observed response for `Show me the recent A1c trend.`: `The recent Hemoglobin A1c levels are as follows: 7.4 % on 2026-04-10 and 8.2 % on 2026-01-09.`
9. Inspected `/var/log/apache2/error.log` in the OpenEMR container for `agent_forge_request`.
10. Observed sanitized log fields: `request_id=dcc5e992-1e13-4a0d-adb1-edbf119e8973`, `user_id=1`, `patient_id=900001`, `decision=allowed`, `latency_ms=2989`, `question_type=lab`, `tools_called`, `source_ids`, `model=gpt-4o-mini`, `input_tokens=836`, `output_tokens=173`, `estimated_cost=0.0002292`, `failure_reason=""`, and `verifier_result=passed`.
11. Confirmed log context did not include raw question text, full answer text, full prompt, patient name, or full chart text.

**VM Manual Browser Verification:**

1. Confirmed the VM repo was clean on commit `5fcd3e847`.
2. Created `docker/development-easy/.env` on the VM with OpenAI provider settings and `chmod 600`.
3. Initial deploy attempt exposed a deploy-script defect: `deploy-vm.sh` checked only shell env before Docker Compose loaded `.env`. Workaround for the successful VM proof was `set -a; source docker/development-easy/.env; set +a`; the script has since been updated to load the Compose `.env` itself before validating model config.
4. Ran `agent-forge/scripts/deploy-vm.sh`; public app returned HTTP 200, readiness endpoint returned HTTP 200, fake demo data was seeded, and deploy printed `Deploy succeeded.`
5. Confirmed the running VM OpenEMR container saw `AGENTFORGE_OPENAI_API_KEY`, `AGENTFORGE_DRAFT_PROVIDER=openai`, `AGENTFORGE_OPENAI_MODEL=gpt-4o-mini`, `AGENTFORGE_OPENAI_TIMEOUT_SECONDS=15`, and `AGENTFORGE_OPENAI_CONNECT_TIMEOUT_SECONDS=5` without printing the secret.
6. Opened the public VM app at `https://openemr.titleredacted.cc/`, opened fake patient `900001`, and asked: `Show me the recent A1c trend.`
7. Observed final VM browser response: `The recent Hemoglobin A1c results are as follows: 7.4 % on 2026-04-10 and 8.2 % on 2026-01-09.`
8. Inspected the VM OpenEMR container log with `grep -n "agent_forge_request" /var/log/apache2/error.log | tail -n 3`.
9. Observed sanitized VM log fields: `request_id=19f97ce1-f29b-4352-bcb5-319dab4fa5cf`, `user_id=1`, `patient_id=900001`, `decision=allowed`, `latency_ms=10693`, `question_type=lab`, `tools_called`, `source_ids`, `model=gpt-4o-mini`, `input_tokens=836`, `output_tokens=173`, `estimated_cost=0.0002292`, `failure_reason=""`, and `verifier_result=passed`.
10. Confirmed log context did not include raw question text, full answer text, full prompt, patient name, or full chart text.

**Live LLM Verification:**

1. Recreated the OpenEMR container with `docker compose up -d --force-recreate openemr` so Compose injected `docker/development-easy/.env`.
2. Confirmed the running container sees `AGENTFORGE_OPENAI_API_KEY`, `AGENTFORGE_DRAFT_PROVIDER=openai`, and `AGENTFORGE_OPENAI_MODEL=gpt-4o-mini` without printing the secret.
3. Ran a temporary in-container PHP smoke using `DraftProviderFactory::create(DraftProviderConfig::fromEnvironment())`, one fake A1c evidence item, and `DraftVerifier`.
4. Observed live provider output: model `gpt-4o-mini`, input tokens `333`, output tokens `143`, estimated cost `0.00013575`, verifier result `passed`, and citation `lab:procedure_result/agentforge-a1c-2026-04@2026-04-10`.

**Suggested Commit:** `test(agent-forge): add local smoke proof`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human browser verification items are checked only after they were actually performed locally.
- [x] Known fixture/data/user prerequisites for local proof are created or explicitly assigned as tasks.

---

## Acceptance Matrix

| Requirement | Implementation / Proof |
| --- | --- |
| Complete logs include request id, user id, patient id, timestamp, question type, tools called, source ids, latency, model, tokens, cost estimate, failure reason, and verifier result. | `AgentTelemetry`, `RequestLog`, `AgentRequestHandler`, `VerifiedAgentHandler`; `RequestLogTest`; eval result log contexts. |
| Successful and failed requests produce complete logs. | `RequestLogTest`; `VerifiedAgentHandlerTest`; eval cases for allowed, refused, failed-tool, and failed-verification paths. |
| Logs avoid full prompts, full chart text, and unnecessary raw PHI. | `RequestLogTest`; eval runner checks forbidden log keys. |
| Token usage appears in logs. | `VerifiedAgentHandler` propagates `DraftUsage`; fixture usage asserted in tests and eval results; OpenAI usage parsing asserted in `OpenAiDraftProviderTest`. |
| Estimated cost appears only when known; unknown pricing is labeled unknown. | Fixture path records `estimated_cost: null`; no guessed pricing. |
| Eval dataset exists with deterministic pass/fail criteria. | `agent-forge/fixtures/eval-cases.json`. |
| Safety-critical cases block release. | `agent-forge/scripts/run-evals.php` exits non-zero on failed safety-critical cases. |
| Results are saved with timestamp and code version. | `agent-forge/eval-results/eval-results-20260430-233329.json`. |
| Smoke proof records latency and proves verifier runs. | Eval result log contexts include `latency_ms` and `verifier_result`. |

---

## Commands Run

```bash
php -l src/AgentForge/AgentTelemetry.php
php -l src/AgentForge/VerifiedAgentHandler.php
php -l src/AgentForge/AgentRequestHandler.php
php -l src/AgentForge/OpenAiDraftProvider.php
php -l agent-forge/scripts/run-evals.php
composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'
php agent-forge/scripts/run-evals.php
vendor/bin/phpstan analyse src/AgentForge interface/patient_file/summary/agent_request.php tests/Tests/Isolated/AgentForge --configuration=phpstan.neon.dist --no-progress --memory-limit=1G
```

---

## Change Log

- 2026-04-30: Added sanitized AgentForge telemetry DTOs and wired telemetry into PSR request logging.
- 2026-04-30: Added fixture usage/cost telemetry from the verified handler path.
- 2026-04-30: Added deterministic eval fixture and in-process runner with saved JSON results.
- 2026-04-30: Captured local smoke proof through the eval runner.
- 2026-04-30: Added OpenAI structured-output provider support and usage/cost parsing tests; live API-key verification passed locally.
- 2026-04-30: Completed local browser verification with real OpenAI drafting, sanitized PSR telemetry, visible token/cost fields, and verifier result `passed`.
- 2026-04-30: Completed VM browser verification with real OpenAI drafting, sanitized PSR telemetry, visible token/cost fields, and verifier result `passed`.

---

## Definition Of Done Gate

Can I call this done?

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes
- Required manual checks executed and captured? yes; local and VM live OpenAI browser/UI verification passed
- Required fixtures/data/users for proof exist? yes for local in-process proof
- Security/privacy/logging/error-handling requirements verified? yes for automated/local proof
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

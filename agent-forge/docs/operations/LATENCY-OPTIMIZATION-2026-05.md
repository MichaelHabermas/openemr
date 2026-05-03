---
**Updated:** 2026-05-02
**Scope:** Document the latency-optimization work executed against `src/AgentForge` from the first-principles latency review (plan: `~/.claude/plans/elon-lets-review-soft-pascal.md`). Capture what shipped, the regression caught mid-execution, the verification evidence, and what was deliberately deferred so the next iteration can pick up cleanly.
**Status:** Tier 0/1/2 evals at baseline parity after the work; Tier 4 (deployed smoke) gated by the AgentForge Deployment Guardrail in `CLAUDE.md` and not run in this pass.
---

# Latency Optimization Pass — May 2026

## Background

A first-principles review of `src/AgentForge` produced a 12-item recommendation list plus two architectural ideas, ordered by risk in the plan file. The clinical co-pilot's "no inference, only fact-surfacing" guarantee is non-negotiable, so any change touching drafting or verification had to preserve it. This document records what was executed, in execution order, with commit references and the verification result.

The recommendation list is not reproduced here — it lives in `~/.claude/plans/elon-lets-review-soft-pascal.md`. Item numbers below match that plan.

## Items Shipped

Listed in the order they landed on `master`. Commit hashes link the change.

| # | Change | Commit | Pattern / Surface |
| --- | --- | --- | --- |
| 9 | Strip `StringKeyedArray::filter` from `DefaultQueryExecutor` hot path; restored a lean private `stringKeyed()` helper to keep the `array<string, mixed>` contract | `e54a3b3a5` (within larger PHPStan/type pass) | Hot-path simplification |
| 10 | Delta-write conversation state in `SessionConversationStore` instead of read-prune-rewrite per turn | landed alongside store cleanup | Store hygiene |
| 6 | Consolidate the two medication queries in `SqlChartEvidenceRepository` into one `UNION ALL` with server-side `ORDER BY` / `LIMIT` | `704bafe0f` | Round-trip reduction |
| 5 | New `MemoizingPatientAccessRepository` (Decorator) wrapping `SqlPatientAccessRepository` for per-request memoization | `7bc3589bc` | Decorator pattern |
| 7 | Bypass verifier for `FixtureDraftProvider` fallback path via `VerifiedDraftingPipeline::trustedFixtureResult()` — fixture is deterministic-safe by construction | `2a469600e` (and pipeline edits) | Trusted-source short-circuit |
| 8 | `DraftProviderRetryMiddleware` deadline-aware retries (`MIN_CALL_BUDGET_MS = 250`); `planRetry()` aborts when remaining budget cannot cover backoff + minimum call cost | `2a469600e` | Deadline-aware control flow |
| 3 | Second prompt cache breakpoint in `AnthropicDraftProvider` for the stable evidence prefix; `PromptComposer::userMessageParts()` returns `PromptParts(stableEvidence, deltaQuestion)` so the provider decides where breakpoints go | (landed in cache-breakpoint commit; see Regression below) | Provider-decides-cache pattern |
| 4 | `Deadline` plumbed through `ChartEvidenceTool::collect(?Deadline)` and into `SqlChartEvidenceRepository`; `SqlDeadlineHint::apply()` adds `MAX_EXECUTION_TIME` per query when budget remains | `6bd4fdf5a`, `33222ed8e`, `5d118da45`, `e9407c05d` | Contract widening |
| 1 | Strategy seam: `ChartEvidenceCollector` interface; today's class renamed to `SerialChartEvidenceCollector`; new `ConcurrentChartEvidenceCollector` calls `PrefetchableChartEvidenceRepository::prefetch()` once before the section loop, then dehydrates per-section into the existing `EvidenceResult` shape | `8d2ed541f`, `704bafe0f` | Strategy + repository batching |

The `ConcurrentChartEvidenceCollector` does not introduce a new I/O model — PHP without `ext-parallel` rules out true thread fan-out. The win comes from one combined repository fetch replacing N per-section round-trips. The serial collector remains the default; concurrent is wired only where a `PrefetchableChartEvidenceRepository` is available.

## Items Deferred (Documented Reasoning)

Carried forward from the plan, restated here so the next pass does not re-litigate the decision:

- **#2 — Stream LLM with incremental verification.** Highest perceived-UX win, but requires SSE on the API edge, a streaming-aware `DraftVerifier` variant, and a frontend that consumes the stream. Crosses surfaces beyond AgentForge. Separate epic.
- **#11 — Trim `ConversationTurnSummary` fields.** Re-examined and skipped: all four fields (`questionType`, `sourceIds`, `missingOrUncheckedSections`, `refusalsOrWarnings`) are consumed by the LLM via `PromptComposer::userMessage()` as `conversation_context`. Trimming would change LLM behavior. The earlier "hygiene" framing was a misdiagnosis.
- **#12 — Defer post-response writes via `fastcgi_finish_request()`.** SAPI-dependent; would silently no-op on CLI/PHP-FPM-less environments. Worth doing later behind capability detection.
- **Architecture A — Semantic / exact-match cache for repeat questions.** Highest aggregate-latency win but needs a key strategy, an invalidation hook on chart writes, and a storage tier. Separate design.
- **Architecture B — Speculative pre-fetch on chart open.** Requires a chart-open hook surface outside AgentForge. Separate design.

## Regression Caught Mid-Execution

The cache-breakpoint refactor (item #3) introduced a regression in the OpenAI path that the isolated test suite did not catch.

**Symptom.** Tier 2 live LLM evals showed case `prompt_injection_user_question` flipping from pass (baseline `5d2022758`) to fail. The failed run had `model: not_run`, `verifier_result: "not_run"`, `failure_reason: "verified_drafting_failed"`, draft latency `15.8s` (vs baseline `14.5s` success). All other 10 cases that had been passing on `5d2022758` remained at parity, isolating the regression to one diff.

**Root cause.** The refactor pointed `PromptComposer::userMessage()` at a new `PromptParts::joined()` helper that concatenated the stable-evidence JSON document with the delta-question JSON document using `\n\n`. That produced two JSON documents glued together, which is not valid JSON for the OpenAI consumer. The Anthropic consumer was unaffected because it consumes `PromptParts` directly via the multi-block content array (each block is its own valid JSON), which is the whole point of the cache-breakpoint split.

**Fix.** Restored the legacy single-document JSON build inside `userMessage()`; kept `userMessageParts()` for the Anthropic cache-breakpoint path; removed the unused `PromptParts::joined()` to eliminate the trap. Commit `4022ac7e8`.

**Lesson.** When introducing a multi-block representation for one provider, the single-string path consumed by the other provider must remain a tested code path independent of the new helper. The two providers cannot share a serialization helper that produces a different shape than each consumer expects.

## Verification

Run from the working tree at HEAD (`4022ac7e8`):

| Layer | Command | Result |
| --- | --- | --- |
| Isolated PHP tests (full repo) | `composer phpunit-isolated` | 3015 tests pass |
| AgentForge isolated tests (subset) | `composer phpunit-isolated -- --filter AgentForge` | 284 tests pass |
| PHPStan level 10 | `composer phpstan` | 6 errors (clean HEAD `5d2022758` had 8; net improvement; remaining errors are pre-existing in files not modified by this work — `EvalLatestSummaryWriter.php`, `EvalResultNormalizer.php`, `EvidenceToolsTest.php`, `tier2-eval-runner.php`) |
| PHPCS | `composer phpcs` | 5 errors, all pre-existing in HEAD (verified by `git stash` test) |
| Tier 0/1 evals | `agent-forge/scripts/tier0-eval-runner.php`, `tier1-eval-runner.php` | 28 / 28 pass |
| Tier 2 live LLM evals (OpenAI) | `agent-forge/scripts/tier2-eval-runner.php` | 10 / 12 pass — matches baseline `5d2022758`. The 2 failures (`visit_briefing`, `hallucination_pressure_birth_weight`) pre-date this work. |
| Tier 4 deployed smoke evals | n/a | Not run; gated by the AgentForge Deployment Guardrail in `CLAUDE.md` (requires VM access + explicit deploy decision). |

Tier 2 was used as the regression detector for #3. The per-case JSON output from the runner — specifically the `model` / `verifier_result` / `failure_reason` / `latency_ms` fields per case — was diffed against the baseline run to isolate the regression to a single case before bisecting the diff.

## Operational Notes For The Next Iteration

- **Anthropic cache-hit telemetry.** The cache-breakpoint change for #3 is correct on the Anthropic path but its actual win is unverified until `cache_creation_input_tokens` vs `cache_read_input_tokens` are logged per call and a multi-turn conversation is run. The plan asks for `≥50%` read rate on turn 2+; that proof is still owed.
- **Latency proof for #1, #5, #6, #9.** The plan asks for before/after `StageTimer` traces on a representative question. Not captured in this pass — the plan's verification step #5 remains open.
- **Tier 4 deploy.** When ready, follow the gate-by-gate procedure in the AgentForge Deployment Guardrail — local UI checks, local automated proof, git status/diff review, explicit commit/push decision, VM deploy script, VM seed/verify, VM UI checks, then proof-file update.
- **Live eval env wiring.** Tier 2 reads provider keys from `docker/development-easy/.env`. PHP's `getenv($name, true)` requires `export` semantics, so source via `set -a; . docker/development-easy/.env; set +a; php agent-forge/scripts/tier2-eval-runner.php` rather than relying on inline assignment. The repo's local `.env` already contains `AGENTFORGE_ANTHROPIC_API_KEY`, `AGENTFORGE_OPENAI_API_KEY`, model overrides, and `AGENTFORGE_DRAFT_PROVIDER` selection.

## Open Items

- [ ] Capture before/after `StageTimer` traces on the A1c-class question for #1, #6, #9 to convert "shaved time" estimates into measured numbers (plan verification step #5).
- [ ] Log `cache_creation_input_tokens` vs `cache_read_input_tokens` on a multi-turn conversation; confirm `≥50%` cache-read rate on turn 2+ (plan verification step #6 for #3).
- [ ] Address pre-existing PHPStan/PHPCS errors in eval-runner files (separate from latency scope).
- [ ] Sequence #2, #12, and Architecture A/B as separate epics.

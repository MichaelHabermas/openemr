# Latency Decomposition

**Updated:** 2026-05-02
**Scope:** Decompose the measured A1c request latency from the available `stage_timings_ms` instrumentation, name the dominant stage by inference where the deployed VM stage timings were not captured into the repo, and enumerate concrete mitigations.
**Status:** Reviewer-grade decomposition based on shipped code paths and the local/VM aggregate measurements in [COST-ANALYSIS.md](COST-ANALYSIS.md). Production-readiness still requires p95 proof under the accepted budget; see Open Items below.

## Measured Aggregates

The single A1c request measured for the demo (fake patient `900001`, prompt `Show me the recent A1c trend.`, model `gpt-4o-mini`, identical evidence bundle of `836` input tokens):

| Path | Aggregate latency | Notes |
| --- | ---: | --- |
| Local Docker | `2,989 ms` | `request_id=dcc5e992-1e13-4a0d-adb1-edbf119e8973`, `verifier_result=passed` |
| Public VM | `10,693 ms` | `request_id=19f97ce1-f29b-4352-bcb5-319dab4fa5cf`, `verifier_result=passed` |
| Delta | `+7,704 ms` | Same code path, same evidence bundle, same model, same token counts |

The VM-vs-local delta is the load-bearing signal: identical prompt, identical evidence bundle, identical model — only the deployed environment changes. That isolates the delta to environment-side factors rather than agent logic.

## Pipeline Stages

The shipped `StageTimer` instrumentation (`src/AgentForge/Observability/StageTimer.php`) records elapsed milliseconds for the following stages, all emitted in the `stage_timings_ms` field of `agent_forge_request` log lines via `PsrRequestLogger`:

Per-request handler stages (`src/AgentForge/Handlers/AgentRequestHandler.php`):

- `request:method` — HTTP method validation.
- `request:csrf` — CSRF token verification against the OpenEMR session.
- `request:parse` — Request body parsing into the typed `AgentRequest`.
- `request:authorize` — `PatientAuthorizationGate` lookup against `SqlPatientAccessRepository`.
- `conversation:lookup` / `conversation:start` / `conversation:record_turn` — `SessionConversationStore` reads/writes for multi-turn binding.

Planner / drafting / verification stages (`src/AgentForge/Handlers/VerifiedAgentHandler.php` and `VerifiedDraftingPipeline.php`):

- `planner` — Question classification and chart-section selection.
- `evidence:<Section>` — One row per invoked evidence tool (`Demographics`, `Active medications`, `Recent labs`, etc.). Section names match the `ChartEvidenceTool::section()` value, so an A1c-class request typically records `evidence:Recent labs` in addition to whatever sections the planner selects.
- `draft` — Live model call to the configured draft provider (`OpenAiDraftProvider` in deployed mode, `FixtureDraftProvider` in eval mode).
- `verify` — `DraftVerifier` token-set match against the evidence bundle.

The fields `evidence:*`, `draft`, and `verify` are the three the plan calls out for decomposition; in practice every stage in the list above lands in `stage_timings_ms` for a successful request.

## Inferred Decomposition for the VM A1c Request

Per-stage VM timings were not captured into the repository at the time of the `19f97ce1...` measurement. The decomposition below is therefore inferred from (a) the shipped code paths above and (b) the local-vs-VM delta on an identical request.

| Stage | Local share (estimate) | VM share (estimate) | Reasoning |
| --- | ---: | ---: | --- |
| Pre-evidence (`request:*`, `conversation:*`, `planner`, `request:authorize`) | < 50 ms | < 100 ms | Synchronous PHP calls with one indexed read for authorization. The conversation store is session-bound and in-process; no cross-network call. |
| `evidence:*` (sum across selected sections) | ~150–400 ms | ~200–500 ms | A1c-class question selects the labs tool plus any briefing-adjacent tools. Each tool issues bounded patient-scoped reads; the schema concerns in [AUDIT.md](../AUDIT.md) §P1 (no composite index on `lists(pid, type, activity)`) push the upper bound but the seeded demo dataset is small enough that absolute time stays low. |
| `draft` (live `gpt-4o-mini` call) | ~2,400–2,700 ms | ~9,800–10,300 ms | The dominant stage in both runs. The +7,704 ms VM-vs-local delta is consistent with model-API round-trip time being the variable that changes when the host changes. |
| `verify` | < 50 ms | < 100 ms | `EvidenceMatcher` token-set comparison runs in-process over the (small) evidence bundle and the structured draft. |

**Primary conclusion:** the `draft` stage — the live `gpt-4o-mini` call — owns the time on both paths, and almost all of the VM-vs-local delta. The fact that the local Docker run, which uses the *same* model provider over the same network, completes in `2,989 ms` is what disqualifies any agent-internal cause as the dominant explanation.

**Why the VM `draft` is much slower than the local `draft`:** the most likely contributors, in order:

1. Network round-trip from the VM region to OpenAI's API endpoint.
2. TLS handshake / DNS resolution overhead per request, since prompt-cache and connection-reuse are not yet implemented as product guarantees (see [COST-ANALYSIS.md](COST-ANALYSIS.md) cache assumptions).
3. PHP-FPM cold-start or session bootstrap on a low-traffic VM.
4. DB query path differences against the deployed MariaDB versus the local Docker MariaDB.

These are hypotheses, not measurements. The capture procedure below is required before any of them can be ranked.

## Capture Procedure (To Replace Inference With Numbers)

To replace the inference table above with real per-stage VM numbers:

1. SSH to the deployed VM and locate the `agent_forge_request` log line for the next live A1c request. Lines are emitted via PSR-3 by `src/AgentForge/Observability/PsrRequestLogger.php`; in the current Docker stack they land in the OpenEMR container's PHP error log (`docker compose logs openemr | grep agent_forge_request`).
2. Extract the `stage_timings_ms` object from the JSON context. Sum the values; the sum should be within ~5% of the top-level `latency_ms`. If it is not, an unmetered span exists and `StageTimer` instrumentation needs to be extended before drawing further conclusions.
3. Record the per-stage values in this document under a new "Captured" table that supersedes the "Inferred" table.
4. Run the same single A1c request three times back-to-back and compare. Wide variance on `draft` confirms model-API jitter; wide variance on `evidence:*` points at DB cache warmup; wide variance on `request:*` points at PHP-FPM cold-start.

Until step 1 lands, the inferred decomposition above is the best available evidence and is the basis for the mitigations below.

## Mitigations By Dominant Stage

If the captured numbers confirm `draft` dominance (most likely, per the inference):

- Move the deployed VM's egress closer to the OpenAI API region, or front the call with a regional reverse proxy that keeps a warm TLS connection pool.
- Add prompt-prefix caching (the `gpt-4o-mini` model page documents cached input pricing at half rate) so the static system prompt and tool-allowlist preamble do not re-tokenize on each request.
- Consider a cheaper / lower-latency model for the short-lookup question class (briefing and explicit refusal cases), gated on Tier 2 live evals continuing to pass.
- Add request-level retries with backoff distinct from any provider-side retry, so a single slow round trip does not push a clinically interactive request past the budget.

If `evidence:*` is meaningfully larger than expected:

- Add the candidate composite indexes documented in [AUDIT.md](../AUDIT.md) §P1: `prescriptions(patient_id, active)` and `lists(pid, type, activity)`. Capture before/after `EXPLAIN` per AUDIT §P1 current status.
- Implement the selective tool-routing changes (planned in the parent remediation plan) so non-briefing requests skip evidence tools the question does not need.

If `request:authorize` or `request:parse` is meaningfully larger than expected:

- Cache `SqlPatientAccessRepository` lookups at request scope. The current path issues at least one DB round trip per request even when the same user/patient pair was just resolved.
- Profile `AgentRequestParser` against malformed or oversized payloads; the request body is `1000`-char-bounded by the chart panel but server-side validation is not assumed to scale to large bodies.

If `verify` is meaningfully larger than expected (unlikely given the bundle size):

- Cache the canonicalized evidence-token set across same-patient follow-ups so verification does not re-tokenize the bundle when nothing has changed.

## Production-Readiness Caveats

The decomposition above does not satisfy production-readiness on its own. Outstanding obligations restated from [COST-ANALYSIS.md](COST-ANALYSIS.md) and the parent epic `EPIC_OBSERVABILITY_LATENCY_AUDIT_LOGS.md`:

- p95 latency under the accepted budget across the briefing, lab, medication, missing-data, and refusal request shapes.
- Stage-timing aggregation, dashboards, SLOs, and alerting backed by a managed log-aggregation system.
- Sensitive audit-log retention policy and access governance.
- Reproduction with cache-warm and cache-cold provider behavior so the pricing assumptions are auditable.

This document is a structural decomposition and a mitigations enumeration; it is not a benchmark.

## Open Items

- [ ] Capture `stage_timings_ms` from the next live VM A1c request and replace the Inferred table with a Captured table.
- [ ] Run three back-to-back A1c requests against the VM and record per-stage variance.
- [ ] After Tier 2 live evals exist (parent remediation plan Phase 4), use their captured timings to widen the dataset beyond the single A1c case.
- [ ] If the captured `draft` share is greater than 80% of total, prioritize prompt-prefix caching and connection reuse before further evidence-side optimization.

# AgentForge Decisions

Policy decisions that govern live code, organized by domain. Each entry
records a choice that isn't obvious from reading the implementation.

Source of truth is always the code. This file preserves the *why*.

---

## Deployment & Operations

- **No volume wipes.** `docker compose down` never uses `-v`. MariaDB 11.8.6 first-init is fragile on the demo VM — partial init leaves root password unset, requiring hand-fix. Volume bootstrap is one-time manual; deploys/rollbacks preserve it.
- **No point-in-time DB rollback.** Recovery is code rollback + idempotent re-seed. Acceptable because only fake demo data is stored.
- **Cloudflare reconnect latency.** Edge takes ~90s to re-establish origin after stack cycle. Health polling must account for this.
- **API key gate.** Deploy fails fast if `AGENTFORGE_DRAFT_PROVIDER` key is missing. Default provider is `openai`.
- **Runtime readiness contract.** `health-check.sh` validates `/readyz` JSON with `mariadb`, `worker`, and `queue` components. Payload must stay PHI-safe.
- **Tier 4 is release proof, not PR gate.** PR merges can be green on Tier 0/1 while Tier 4 has not run.
- **After rollback, scripts are old.** The rolled-back tree has the version of scripts from the target commit. Roll forward with `git switch master` then `deploy-vm.sh`.

## Authorization

- **Fail-closed.** If user-patient relationship can't be confirmed, request refused before any chart read.
- **Three relationship shapes only:** `patient_data.providerID`, `form_encounter.provider_id`, `form_encounter.supervisor_id`. All others (care-team, facility, schedule, group, delegation) deferred and fail-closed.
- **Generic error on unexpected failure.** Internal exception messages never exposed to user.

## Evidence & Display

- **Source-carrying facts only.** Every displayed patient fact must come from source-carrying evidence.
- **Transparency over silence.** Missing, stale, inactive, and tool failures must be visible — never silently converted to certainty.
- **Stale vitals** labeled explicitly as `Last-known stale`.
- **Inactive medication history** is a separate evidence section, never mixed with active medications.
- **Medication coverage:** `prescriptions`, `lists`, `lists_medication` — duplicates, conflicts, uncoded rows surfaced as chart evidence without reconciliation.
- **Diagnosis/lab codes** are source metadata only, not clinical interpretation.
- **Unauthorized clinical notes** (`form_clinical_notes.authorized = 0`) and standalone `form_encounter` without linked clinical notes not surfaced.
- **Allergy vs medication routing:** allergy keyword routing takes precedence when a question mentions both.

## Verifier

- **Distrusts model labels.** Patient-specific factual content requires source grounding regardless of model-supplied labels.
- **Token-set matching** via `EvidenceMatcher` (not `str_contains`). Numeric tokens must match exactly (`5` ≠ `5.0`).
- **Date canonicalization.** English-month dates → ISO (`April 12, 1976` → `1976-04-12`). Ambiguous regional formats deliberately left unconverted.
- **Unsupported factual tails blocked.** A grounded claim must cover the displayed sentence, not just a substring.
- **Semantic paraphrase verification deferred.** Model instructed to copy `display_label` verbatim as workaround.
- **Verification fallback:** model failures can fall back to deterministic evidence-line output, but only for real model drafts after verifier proof — fixture/eval hallucination failures still refuse.

## Source Review & Retraction

- **No iframe embed.** Source review renders citation metadata, quote/value text, and bounding-box highlight — deliberately does not embed the source document.
- **PDF page-image rendering is placeholder.** CSS-grid area; actual PDF embed is future work.
- **Typed locator kinds over `review_mode` string.** Five `ReviewLocatorKind` enum cases (`image_region`, `page_quote`, `text_anchor`, `table_cell`, `message_field`) replace the `bounding_box`/`page_quote_fallback` string pair. Template JS dispatches on `locator.kind`; non-page formats (DOCX, XLSX, HL7) render metadata + quote only.
- **`SourceReviewPresenter` owns all review URLs and locator mapping.** Pure presentation, no DB/auth. Injected into both the evidence tool and the review service — single source of truth for URL construction and doc-type → locator-kind mapping.
- **Source-review gate:** active chart patient, `patients:med` ACL, succeeded unretracted job, trusted identity or approved review, non-deleted active document, active unretracted fact.
- **Retraction = deactivation, never hard-delete.** `active=0`, `retracted_at`, audit rows. `lists` rows get `activity=0`; `procedure_result` rows marked corrected/excluded.
- **Append-only audit** via `clinical_document_retractions` with prior state, new state, action, actor, reason, source document linkage.

## Conversation

- **v1 is single-shot constrained RAG.** No persistent conversation storage, no database schema, no browser-owned memory.
- **Conversation ID is server-issued,** bound to session user+patient. Cross-patient follow-up refused before tools run.
- **Every turn re-fetches evidence.** Prior-turn context is compact planner context only; patient-specific claims must cite current-evidence source IDs.
- **Provider timeout fallback.** Falls back to verifier-checked deterministic evidence instead of losing the answer.
- **Persistent PHI conversation storage deferred** until server-owned patient-bound state, retention, and follow-up evals are designed.

## Audit Logs

- **PHI-minimized, not de-identified.** Logs contain user, patient, and source identifiers.
- **Allowed fields:** request id, user id, patient id, decision, timestamp, latency, `stage_timings_ms`, question type, tools, source ids, model, token counts, cost, failure reason, verifier result.
- **Forbidden fields:** raw question, full answer, full prompt, full chart text, patient name, credentials, raw exception internals.
- **Known gap:** Apache referer lines still carry `set_pid` URL parameter outside AgentForge JSON payload.
- **PSR telemetry at warning level** for Docker logger visibility.
- **Production blocker:** operational access, retention governance, and review responsibility unresolved.

## Eval Tiers

- **Tier 0:** Deterministic fixtures (orchestration logic proof).
- **Tier 1:** Seeded SQL evidence (real repositories against demo data).
- **Tier 2:** Live model contract with cost/latency telemetry.
- **Tier 3:** Local browser smoke.
- **Tier 4:** Deployed browser/session smoke.
- **No phantom green.** Result file only created when tier's runner actually executed.
- **Release gate:** live-tier result OR explicit documented gap required before any live-agent claim.

## Demo Data

- **pid=900001 (Alex Testpatient):** Standard demo patient. Known missing urine microalbumin — agent must report "not found," not infer.
- **pid=900002 (Riley Medmix):** Active, inactive, duplicate, and stale medication records for evidence-boundary testing.
- **pid=900003 (Jordan Sparsechart):** Absent labs, sparse notes for missing-section transparency testing.
- **Seed is idempotent:** deletes and re-inserts demo pid rows, never drops tables or volumes.
- **Medications widget empty** (driven by `lists` not `prescriptions`). Clinical Notes render as "Unspecified" (seed stores freeform strings, not `list_options` keys).

## Cost & Scale

- **Measured gpt-4o-mini A1c request is a baseline, not a production forecast.** The bottleneck is operating a safe, auditable clinical system.
- **Default pricing:** gpt-4o-mini $0.15 input / $0.60 output per 1M tokens (built-in when pricing env vars absent).
- **Measured latency:** local 2,989ms, VM 10,693ms. Production readiness blocked until p95 < 10s.
- **100K users requires architecture redesign,** not just a larger bill.

## Latency

- **Draft stage dominates.** ~80-90% of wall time is the live model call. Evidence, auth, conversation, and verification are <10% combined.
- **VM-vs-local delta is network.** Same code, same evidence, same model — the 7.7s delta isolates to provider round-trip and TLS, not agent logic.
- **Shipped optimizations (May 2026):** medication query UNION ALL, memoizing patient access, deadline-aware retries, Anthropic prompt-cache breakpoints, serial→concurrent evidence collection seam, session-store delta writes.
- **Deferred:** (1) Streaming LLM with incremental verification — needs SSE, streaming verifier, frontend. (2) Post-response writes via `fastcgi_finish_request()` — SAPI-dependent. (3) Semantic cache for repeat questions — needs key strategy and invalidation. (4) Speculative pre-fetch on chart open — primitive exists (`PrefetchableChartEvidenceRepository`), needs trigger surface.
- **Provider serialization lesson.** Multi-block prompt representations for one provider (Anthropic cache breakpoints) must not break the single-string path consumed by another provider (OpenAI). Commit `4022ac7e8` fixed a regression where `PromptParts::joined()` produced invalid JSON for OpenAI.

## Submission

- **Three root documents:** `AUDIT.md`, `USERS.md`, `ARCHITECTURE.md` (mandated by `SPECS.txt` hard gate).
- **`ARCHITECTURE.md`** must contain capability→user and trust-boundary→audit traceability tables.
- **Reviewer guide** separates implemented proof from planned remediation; includes explicit production-readiness blocker list.

# Process and Decisions

This document outlines the process and decisions made during the development of the OpenEMR Agent Forge project.

## Setup process — what / why

- **Installed Composer (via Homebrew):** needed PHP and Composer on the machine; README build steps depend on them.
- **Installed `ext-redis` (PECL):** `composer install` failed without it; the project lists Redis as a required extension.
- **Ran `composer install --no-dev`:** pull production PHP dependencies as the upstream docs describe for building from the repo.
- **Ran** `npm install`**,** `npm run build`, and `composer dump-autoload -o`**:** optimize Composer autoload after the build, as the README’s “from repo” sequence specifies.
- **Started the Easy Development Docker environment** (`docker/development-easy`, `docker compose up --detach --wait`): the composer/npm steps only build the tree; this runs OpenEMR with MySQL and the rest of the dev stack so the app is actually reachable, not just compiled on disk.
- **Opened the running instance in the browser** (e.g. `http://localhost:8300/`, or `https://localhost:9300/`): confirmed the project is up; default dev access is documented in `CLAUDE.md` / `CONTRIBUTING.md` (e.g. `admin` / `pass`).

## Quick start

From repo root:

```bash
cd docker/development-easy
docker compose up --detach --wait
```

## Git remotes — GitLab and GitHub

Changes are pushed to **both** the GitLab and GitHub remotes.

- **GitLab** is the target Gauntlet expects.
- **GitHub** is kept in sync because deployments and related workflows are easier there.

## Deployment — what we tried and where it landed

- **Tried Railway first:** ran into difficulty getting the build to work and could not get past it, so abandoned the platform.
- **Tried DigitalOcean Droplets next:** payment failed because the Ramp card wasn't accepted for some reason, which blocked provisioning, so abandoned that route as well.
- **Landed on a Vultr Linux VM:** spun up a Linux virtual machine on Vultr, installed Docker and the rest of the required dependencies, pulled the repo, and deployed the app there.
- **Domain from Namecheap:** registered a domain through Namecheap and pointed it at the Vultr VM. That is the live deployment.

## Stage 4 — Users & Use Cases

- **User:**
  - Geriatric-leaning polypharmacy PCP,
  - multi-provider clinic,
  - ~2,000 panel,
  - 18–22 visits/day,
  - 15-min slots.
  - Desktop/WOW, not tablet.
- **Anchor moment:**
  - Chart-open, every visit.
  - Pre-computed at front-desk check-in (~15 min lead) so no LLM call inside chart-open.
- **Agent shape:**
  - Two surfaces, one backend.
  - Card = always-on summary, row-cited.
  - Chat = multi-turn drill-down on the same data.
  - Why: satisfies SPECS multi-turn rule honestly — chat is optional drill-down on a card that works without it.
- **No-inference rule:**
  - Chart-cited facts only.
  - No recommendations, diagnoses, dose changes, or causal reasoning.
  - Why: clinical safety; verification only works when every claim is a row lookup.
- **Retrieval shape:**
  - Tool-grounded over structured EHR (typed tools return rows + IDs).
  - RAG-over-text reserved for unstructured (pnotes, OCR).
- **Placement:**
  - `interface/modules/custom_modules/clinical-copilot/`;
  - agent service is a separate process.
- **UC-1 — Pre-visit "what changed" 4-line card:**
  - Last plan, what's new, trends, meds + flags.
- **UC-2 — Polypharmacy interaction/duplication flag:**
  - Curated JSON rule set (~50–100), flag-only, never "stop X."
  - Why JSON over RxNorm: zero external deps, demo-stable, swappable later.
- **UC-3 — Lab/vital trend drill-down:**
  - Dated values with units, ranges, row IDs.
  - Multi-turn pivots on the same series.
- **UC-4 — "What changed in meds/labs in last 30 days.":**
  - Explicit events only — row-gone ≠ stopped.
  - Why strict: snapshot-diff is inference.
- **Tolerances:**
  - Forgive: "I don't know" with links to what was checked.
  - Project-killer: wrong/fabricated/unsourced med, dose, or lab.
- **Demo data:**
  - Synthea FHIR R4 onto the 14 named pids.
  - Why: keeps the recognizable cast, multi-year course per patient, defensible tool.
- **Killed:**
  - Care-gap dashboard (sorted list does it).
  - Note/portal drafting (generation crosses no-inference).
  - Inbox triage (wrong anchor).
  - Open-ended Q&A standalone (kept only as drill-down surface).
  - "Questions to ask" (inference).
  - Transition-of-care (Stage 6, needs CCDA).

## Stage 5 — AI Integration Plan

### Identity transport (OpenEMR → agent service)

- **Decision:** Short-lived signed JWT, minted by the OpenEMR PHP module at chart-open, verified by the Python agent service on every call.
- **Why:** AUDIT A2 / A8 — OpenEMR identity lives in `$_SESSION['authUser']`, no parameter-passed principal exists in the service layer, no clean way to declare identity off the request thread. JWT is the smallest mechanism that makes the principal an explicit, parameter-passed claim the agent service can verify without coupling to OpenEMR's session internals.
- **Shape:** HMAC-signed (shared secret in env var on both processes). Claims `{ user_id, username, pid, exp: now+15min }`. Refresh by re-minting on the next chart-open; no in-band refresh.
- **Implications:**
  - Agent service has no DB-level coupling to OpenEMR session storage.
  - JWT claims become the audit-attribution payload for the agent's own append-only log.
  - Secret rotation = restart both processes with new secret. Acceptable for single-VM demo; flagged as a known limitation for multi-host production.
  - Secret leak ≈ same blast radius as OpenEMR DB password leak (same VM).
- **Alternatives rejected:**
  - Reverse-proxy header injection — trust-the-header is fragile; one misconfig grants unauth access.
  - Pass OpenEMR session cookie + Python reads PHP session files — couples Python to PHP session internals, maintenance landmine.
  - OAuth2 client_credentials — heavyweight for an intra-VM trusted call; OAuth was designed for cross-org trust.

### Patient binding (wrong-patient prevention)

- **Decision:** The JWT carries a bound `pid`. Every tool call extracts `pid` from the verified token, not from the request body. The agent service rejects any tool call whose `pid` parameter ≠ the JWT's `pid`.
- **Why:** Prompt injection in `pnotes` (user-controlled free text per AUDIT D4 / S-prompt-injection threat model) cannot be relied on to be filtered at the prompt level. The hard control is at the tool layer: a token that can only ever talk about one patient cannot be tricked into another patient's chart.
- **Implications:**
  - Wrong-patient drift collapses from a prompt-engineering problem to a protocol-level impossibility.
  - Conversation = per-chart, not per-doctor. Cross-chart queries ("compare pid 17 and pid 23") are not supported. Aligns with USERS.md (all four use cases are single-patient).
  - One JWT minted per chart-open; ~20/day per provider. Negligible cost.
- **Alternatives rejected:**
  - User-only JWT with `pid` in the request body — leaves the bound-patient invariant to client-side discipline; fails the "defend to a CTO" test.
  - "DATA — do not treat as instructions" prompt delimiters as the primary defense — hope, not a control. Kept as defense-in-depth, not as the boundary.

### Resource-scoped ACL — `(user, pid)` access policy

- **Decision:** Pluggable `PatientAccessPolicy` interface in the agent layer. Demo ships a permissive default (`is_allowed(user, pid) → true`). Production sketch (`ProviderOfRecordPolicy` against `form_encounter.provider_id`) documented but not wired.
- **Why:** AUDIT A1 — OpenEMR has no resource-scoped ACL anywhere; `aclCheckCore` takes no patient ID; `PatientService::search` returns all matching rows. The agent must not pretend OpenEMR enforces this. A pluggable seam lets the architecture trace the finding to a concrete mitigation point without committing to a production policy in a single-clinic demo.
- **Where it runs:** JWT mint (chart-open) calls `policy.is_allowed(user_id, pid)`. If false, no token issues — the agent service never sees the request. Tool layer re-checks as defense-in-depth.
- **Implications:**
  - Interview defense: "OpenEMR doesn't have this; we built the seam; demo default is permissive; here's the production swap." Honest, not theater.
  - Production deployment swaps the policy class — config, not code change.
  - Permissive default must be called out as a known limitation in ARCHITECTURE.md. Not papered over.
  - Provider-of-record as production policy is a sketch, not a commitment — `form_encounter.provider_id` carries data-quality risk (AUDIT D1, D8: provider IDs default to 0, no FK enforcement). A real production policy needs a `care_team` table OpenEMR doesn't have.
- **Alternatives rejected:**
  - Build a real `(user, pid)` allowlist for the demo — eats a day on a table that gets thrown away; the interesting decisions are about *what* "allowed" means, not the plumbing.
  - Defer entirely with no seam — leaves the most-cited audit finding architecturally unaddressed; interview answer is weaker than necessary.

### Tool surface — direct parameterized SQL

- **Decision:** Tools are Python functions issuing parameterized `SELECT`s directly against OpenEMR's MySQL. Read-only DB user. No PHP RPC, no FHIR API, no ORM.
- **Why:** Agent queries don't match the shape of OpenEMR's existing services (UC-4 "what changed in last 30 days" is not a service method, not a FHIR resource). Wrapping SQL in PHP RPC adds latency and an identity-shim surface (AUDIT A8) for no expressiveness gain. FHIR is shaped wrong for synthesis-across-resources use cases.
- **Two non-negotiable concessions to the audit findings:**
  1. **AUDIT data-quality gotchas encoded explicitly in tool code.** D3 four-way soft-delete → centralized `clinical_filters.py` helpers used by every tool. D5 medication dual-storage → `get_active_meds` `UNION`s `lists` (`type='medication'`) and `prescriptions`. D4 polymorphic `lists.type` → tools assert the literal `type` string, no string-building. D8 author-id-defaults-to-0 → tools never trust `provider_id = 0` as a real provider.
  2. **Agent's own append-only audit log is the system of record for AI-mediated reads.** Every tool call logs `(jwt.user_id, jwt.pid, tool_name, parameters, returned_row_ids, timestamp)`. Replaces what OpenEMR's `EventDispatcher` would have given us if tools went through PHP services. OpenEMR's own `log` table is left as-is (boundary settled in a later decision).
- **Implications:**
  - Every tool gets unit tests against Synthea fixtures asserting *exact returned row IDs*, not "looks reasonable." Regression in soft-delete or dual-storage handling fails on commit.
  - Schema drift on upstream OpenEMR merges is the agent layer's problem. Acceptable in a fork; flagged for upstream-tracking.
  - One language (Python), one DB surface, one set of failure modes.
- **Alternatives rejected:**
  - PHP service layer via RPC — recreates the AUDIT A8 identity-shim problem in every wrapper; adds hops (agent → HTTP → PHP-FPM → DB); existing services aren't shaped for the agent's queries anyway.
  - FHIR REST API — patient-launch SMART scopes (S7) are resource-scoped, but FHIR resource model doesn't fit cross-resource synthesis. Inherits AUDIT P3 join shape. More hops than direct SQL.

### Service language — split: PHP for OpenEMR module, Python for agent service

- **Decision:** OpenEMR-side integration (chart-panel UI, JWT minter, `interface/modules/custom_modules/clinical-copilot/`) is PHP. Agent service (loop, tools, verifier, observability) is Python. Communicates over HTTP with the JWT from Q1.
- **Why:** PHP is required on the OpenEMR side — the JWT minter needs `$_SESSION` access. Python is required on the agent side — every LLM SDK, eval framework, observability tool, and redaction library is Python-first; rebuilding that ecosystem in PHP burns the sprint.
- **Known limitation — duplication of OpenEMR concerns in Python:**
  | OpenEMR (PHP) has | Agent (Python) duplicates |
  |---|---|
  | `AclMain::aclCheckCore` | `PatientAccessPolicy.is_allowed` |
  | `EventAuditLogger` | append-only JSONL + Langfuse |
  | `$_SESSION['authUser']` | JWT claim verification |
  | Schema knowledge in services | `clinical_filters.py` |
  Each row is an upstream-change risk. The cost of the split.
- **Implications:**
  - Three Docker services (OpenEMR, MySQL, agent-service) instead of two.
  - Agent service is independently testable, deployable, replaceable without touching OpenEMR.
  - Audit log is two systems of record (AI-mediated reads in Python; direct UI in OpenEMR). Boundary settled separately.
- **Alternatives rejected:**
  - PHP all the way (in-process with OpenEMR) — zero duplication but the LLM/eval/observability ecosystem in PHP is thin enough that you'd build wrappers for everything; PHP-FPM workers blocking on LLM calls eats concurrency capacity.
  - Node — same ecosystem objection as PHP; team knows Python.

### Citation grammar and verification mechanics

- **Decision (format):** Inline `[src:<table>.<id>]` tags appended to each claim, regex-parseable. Multi-source claims `[src:table.id,table.id,...]`. Anything not matching the regex is treated as uncited.
- **Decision (aggregates):** Decompose into enumerations. The model is prompted to never state counts ("3 meds added"); it enumerates ("Lisinopril added 2026-04-02 [src:lists.142], ..."). For >5-item sets, fall back to count + full ID list cited.
- **Decision (granularity — two-layer verification):**
  1. **Post-processor (deterministic):** parses citations, confirms each `(table, id)` appears in this conversation's tool transcript. Catches fabricated row IDs.
  2. **Verifier model (LLM):** confirms the *quoted values* in the response match the cited row's actual fields. Catches "row exists but values are wrong."
- **Decision (failure handling):** No silent stripping. On any verification failure (post-processor or verifier), replace the entire response with a deterministic enumeration fallback: "I checked [tools] for [question]; here are the rows I found: [enumerated]; I cannot summarize them safely." Block + retry deferred as a documented production upgrade.
- **Why:**
  - Inline tags are human-readable in chat *and* parser-friendly. Footnotes split the parse; JSON makes the model's job harder for marginal gain.
  - Decomposed aggregates remove the most common hallucination shape (claimed counts that don't match enumerated items).
  - Two layers split jobs: the cheap deterministic check catches fabrications; the expensive model check handles residual semantic drift. Schema knowledge stays in one place (the verifier prompt), not duplicated in the post-processor.
  - The deterministic enumeration fallback is ugly but predictable. Doctors care more about predictable behavior than polished prose; eval rejection-rate tells us when to invest in retry.
- **Implications:**
  - Verifier prompt has to know the schema fields it's checking. Drift risk on schema changes.
  - Verifier escape rate is an explicit eval metric (adversarial layer). If >X%, swap verifier model or add a third pass.
  - "I cannot summarize them safely" outputs are a feature, not a bug — they're the evidence the no-inference rule is being enforced.
- **Alternatives rejected:**
  - Numbered footnotes — split parse, no readability gain in chat UI.
  - JSON structured response — most rigorous but model consistency suffers, raw logs unreadable.
  - Silent sentence-stripping on failure — leaves remaining text potentially incoherent ("As I mentioned [stripped], ...").
  - Block + retry as the demo default — adds a third LLM call per failure; eval data should drive whether it's worth the cost.

### Audit log boundary — agent-owned detail + summary row in OpenEMR `log`

- **Decision:** Agent-side append-only log (hash-chained JSONL on disk + Langfuse self-hosted as queryable surface) is the system of record for AI-mediated reads. OpenEMR's `log` gets one summary row per agent session via the existing `EventAuditLogger` (`category='clinical-copilot'`, `event='session-opened'`, comments contain session_id + tool_call_count).
- **Why:**
  - Closes AUDIT C3 — the agent log captures every read the agent does, regardless of `audit_events_query` flag state. We don't depend on a deployment-side toggle to make AI reads auditable.
  - Avoids the cost of flipping `audit_events_query` globally (would also capture direct UI reads at unknown perf cost).
  - Avoids cramming structured tool-call metadata into `log.comments longtext`; the agent log can carry `(tool_name, parameters, returned_row_ids)` natively.
  - Improves on AUDIT C1/C2 — agent log is hash-chained (each row's hash includes prior hash), not just per-row checksum.
  - One row in OpenEMR's `log` per session preserves the single-query answer to "did anything AI-mediated happen on pid X today" while keeping detail in the right store.
- **Pre-compute attribution:** Pre-compute jobs (fire at check-in before the doctor opens the chart) are attributed to system principal `system:precompute` in the agent log. The OpenEMR `log` summary row is written when the doctor actually opens the chart (user-attributable event). No-show pre-computes leave only an agent-log entry under the system principal — system action, not user disclosure.
- **Cross-store query (runbook, not runtime):** "Show everything Dr Smith touched on pid 17 today" → query OpenEMR `log` for `patient_id=17 AND user='drsmith' AND date=today`. For rows with `category='clinical-copilot'`, look up `session_id` and dump the agent log. Documented procedure, not a unified query layer.
- **Implications:**
  - Agent → OpenEMR PHP endpoint (one call per session-close) writes the summary row via `EventAuditLogger`. Schema-duplication risk contained to one writer, one row shape.
  - Agent log designed correctly from the start: hash chain, append-only file mode, periodic external timestamping documented as future work.
  - Two stores, one documented join key. Not unified, but answerable.
- **Alternatives rejected:**
  - Agent log only — leaves OpenEMR's `log` blind to AI reads; HIPAA accounting requires querying two unrelated stores with no join key.
  - Flip `audit_events_query` on, agent writes every read to OpenEMR's `log` — affects all SELECTs system-wide, schema doesn't fit tool-call metadata, gives up the chance to design a hash-chained log.

### Pre-compute trigger — cron polling, with `EventDispatcher` as production upgrade

- **Decision:** Demo fires pre-compute via cron polling `form_encounter` (or an arrival watermark column) every 1–2 min. Production upgrade is an `EventDispatcher` listener (AUDIT A6) on appointment-arrival events. Both call the same `PrecomputeTrigger.fire(pid)` seam.
- **Why:**
  - Demo runs on Synthea data with no real check-in workflow; "check-in" is whatever we script. Cron is the lowest-risk path to demonstrating the architecture.
  - Avoids archaeology of OpenEMR's appointment event surface for the demo (AUDIT A6 lists service-layer events but doesn't confirm a clean "patient arrived" event exists).
  - Cron sees what landed in the DB regardless of how it landed — robust against AUDIT D-class data-quality findings and against third-party schedulers writing directly.
  - Production swap to events is a one-class change behind the same seam — interview answer is "swap the trigger, not the logic."
- **Latency floor:** 1–2 min poll interval eats into the 10–15 min pre-compute budget but doesn't break it. Production event-listener path drops this to milliseconds.
- **Staleness handling:** At chart-open, the agent runs one cheap probe — "any rows in `pnotes`, `procedure_result`, or `lists` with `date > pre_compute_time` for this pid?" — and refreshes incrementally if yes, serves cached if no. Independent of trigger mechanism.
- **Failure mode:** If pre-compute didn't run (cron lag, agent down at check-in), chart-open falls back to last cached card with "stale" badge, or empty card with "co-pilot unavailable" badge. Card never blocks chart-open.
- **Implications:**
  - Pre-compute identity is `system:precompute` (already from Q7) — independent of trigger choice.
  - Cron predicate (what "checked in" looks like in SQL) deferred to implementation; demo uses `last_update > :watermark` against `form_encounter`.
  - Cron lag is a monitorable metric; documented as an ops concern.
- **Alternatives rejected:**
  - `EventDispatcher` listener for the demo — clean but requires confirming a real arrival event exists; risk of finding only "encounter created" (too late) or no clean event at all.
  - Explicit front-desk UI button — modifying check-in UI is an OpenEMR-side change with variable workflow shape across deployments; bypassed if check-in happens via phone/other.

### Chat surface placement — server-side Twig panel + PHP proxy to agent service

- **Decision:** Chat panel is a Twig template rendered by the OpenEMR PHP module embedded in the chart page. Browser AJAX calls hit a PHP endpoint at `/interface/modules/custom_modules/clinical-copilot/chat`, which forwards to the Python agent service over loopback. JWT never leaves the server.
- **Why:**
  - Closes the JWT-in-browser exposure created by AUDIT S3 (non-HttpOnly cookies in core UI) and S4 (Twig autoescape off). XSS in OpenEMR core UI cannot steal a JWT that lives only on the server.
  - Same-origin everything sidesteps AUDIT S1 (reflective CORS reflects any Origin with credentials). Architectural answer: "we chose a path where S1 doesn't apply."
  - PHP-FPM hop cost (~20–50ms) is negligible against 2–8s LLM call.
  - Streaming-through-proxy is solvable with SSE/NDJSON if first-token latency turns out to matter; demo can ship non-streaming.
- **Hard requirements (must-do, easy to forget):**
  - PHP proxy endpoint calls `CsrfUtils::checkCsrfInput()` explicitly (AUDIT S8 — no global CSRF middleware).
  - All chart data rendered into the panel uses `text()` / `attr()` helpers (AUDIT S4 — Twig autoescape is off).
- **Implications:**
  - The PHP module is JWT minter + Twig page + chat proxy. Three responsibilities, one place.
  - Streaming is v2; v1 returns full response after the verifier pass.
  - Browser → PHP → Python adds a hop visible in observability traces.
- **Alternatives rejected:**
  - Native HTML/JS panel calling agent service directly — JWT lives in browser memory, exposed to AUDIT S3/S4 XSS surface.
  - iframe with JWT in URL — JWT leaks via browser history, access logs, Referer headers; iframe also requires `postMessage` complexity for context handoff.

### LLM provider strategy — Claude Haiku default, GPT-4.1-mini verifier

- **Decision (default model):** Claude Haiku as the agent's primary model. Different-family verifier = GPT-4.1-mini. Both have first-class tool calling, both have BAAs available from US-hosted providers.
- **Decision (gateway):** OpenRouter for the sprint demo. Direct provider APIs (Anthropic + OpenAI) for production, with executed BAAs. Single `LLMClient` interface; provider/gateway is config, not code.
- **Decision (PHI on the wire):**
  - Demo: Synthea only, no PHI, no executed BAA needed (per SPECS instruction to act as if a BAA is in place).
  - Production: real PHI in prompts is BAA-covered. PHI redaction is **not** applied to LLM inputs — the agent needs names/dates/values to do its job. Redaction applies to **observability stores** (settled separately).
  - Data minimization is enforced at the tool-output schema: tools return only the fields the use cases need. AUDIT D7 documents `patient_data` has ~100 columns; the agent's tools expose a few dozen. PHI exposure to the LLM is bounded by tool-output schemas.
- **Why:**
  - Cost-tier optimization (PRE-SEARCH's original DeepSeek pick) optimizes the wrong axis for healthcare. Geopolitical risk on a Chinese-hosted provider is a real interview vulnerability even with synthetic data, since prompts and verifier rules are clinical IP.
  - Defense-in-depth requires different families on primary vs verifier — same-family verifiers replicate same-family failure modes.
  - The verification layer (Q6) provides the correctness floor, which means the primary model can be cheap (Haiku) without giving up safety.
  - Direct APIs in production avoid the OpenRouter trust hop and align with how BAAs are actually executed (with the provider doing the inference).
- **Implications:**
  - Two BAAs to execute in production (Anthropic + OpenAI). Two billing relationships. Two outage exposures.
  - Sonnet is a documented upgrade if eval shows Haiku verifier-rejection rate is too high.
  - Self-hosted Llama/Mistral deferred — different product (GPU infra, model maintenance, eval ownership). Documented as a 100K-tier consideration in cost analysis, not a v1 commitment.
- **Alternatives rejected:**
  - DeepSeek V4-Flash default (PRE-SEARCH original) — geopolitical risk on prompt content; cost savings don't justify in healthcare context.
  - Same-family verifier — replicates failure modes.
  - Self-hosted gateway (Portkey) for production — added operational surface; direct APIs are simpler and more standard for healthcare.
  - PHI redaction before LLM input — defeats the use cases; redaction belongs in observability, not the agent's data path.

### Scale cost analysis — 100 / 1K / 10K / 100K users

- **Cost unit:** clinician using the co-pilot. Driver = chart-opens/day (~20) + chat sessions (~8). Per-clinician/day LLM spend ≈ $0.46. Per-clinician/month ≈ $10.
- **Per-call estimates (Claude Haiku primary + GPT-4.1-mini verifier):**
  - Pre-compute: ~$0.012 (primary $0.009 + verifier $0.003)
  - Chat turn: ~$0.007; ~4-turn session: ~$0.028
- **Tier inflection points:**

  | Tier | Daily LLM | Inflection | What changes |
  |---|---|---|---|
  | 100 | ~$46 | (none) | Demo architecture, slightly bigger. Single VM. |
  | 1K | ~$460 | Concurrency | Agent service horizontal (3-5 nodes), Redis for shared state, MySQL read replica, AUDIT P5/P6 cache work pays off |
  | 10K | ~$4.6K | LLM cost | Self-hosted verifier (Llama 3.1 70B class), pre-compute caching (60% hit rate possible), MySQL replication, real `PatientAccessPolicy` (Q3 default no longer adequate), Langfuse Cloud / Datadog with BAA |
  | 100K | ~$46K naïve / ~$10-20K engineered | Architecture | Self-hosted primary, lazy pre-compute (don't pre-compute charts that won't be viewed), multi-tenancy, formal compliance posture (SOC 2 / HITRUST), tenant-aware care-team RBAC contracted from customer HR systems |

- **Per-user-month curve (engineered, not naïve):**
  - 100: ~$11/user/mo
  - 1K: ~$11/user/mo
  - 10K: ~$7-13/user/mo (range depends on cache hit + self-hosted verifier choices)
  - 100K: ~$3-7/user/mo with proper engineering, ~$15+/user/mo without
- **Honest caveats:**
  - Numbers are pre-eval estimates; ±50% until Stage 6 measures real per-call token counts.
  - Per-user-month doesn't decrease monotonically — tier 4 done badly is more expensive than tier 1. Architectural changes aren't optional; they make the curve sane.
  - Latency is LLM-bound, not infrastructure-bound. Scale enables horizontal capacity; doesn't fix per-call latency.
  - HIPAA cost (BAAs, audits, compliance staffing) is a real line item at tier 3+; documented but not modeled per-user.

### Synthea → OpenEMR ETL — hand-rolled Python, hard scope

- **Decision:** Hand-rolled Python ETL reads Synthea FHIR R4 bundles and emits parameterized INSERTs into OpenEMR's MySQL, re-keying onto the existing 14 named `pid`s. Idempotent (delete-then-insert per pid). Encodes AUDIT D-findings at write time.
- **Why:** Two real options; one is a trap.
  - OpenEMR's built-in CCDA importer creates *new* patients (fights the re-key onto 14 named pids), has partial mapping for vitals/lab series, and is famously janky. Debugging it costs more than writing the ETL.
  - A hand-rolled generator that skips Synthea throws away the "defensible to a hospital CTO" credibility win USERS.md committed to — Synthea encodes years of clinical realism that a developer-authored fixture can't match.
  - The ETL is also a defense asset: "I wrote a Python ETL that explicitly encodes the AUDIT.md data-quality findings at write time" is a stronger interview story than "I used the built-in CCDA importer."
- **Scope (hard):**
  - One Synthea bundle per named pid, generated with `--seed=<hash(name)>` for reproducibility.
  - ETL only resource types the four use cases read: `Patient` → `patient_data` (demographics already exist; merge), `Condition` → `lists` (`type='medical_problem'`), `MedicationRequest` → `lists` (`type='medication'`) **and** `prescriptions` (D5 dual-write), `Observation` (labs) → `procedure_result` + `procedure_report` + `procedure_order` chain, `Observation` (vitals) → `form_vitals` + `forms` + `form_encounter`, `Encounter` → `form_encounter`, `AllergyIntolerance` → `lists` (`type='allergy'`).
  - Drop on the floor: `Immunization`, `Procedure`, `CarePlan`, `DocumentReference`, `Claim`, `ExplanationOfBenefit`, `CareTeam`, etc. Synthea emits them; ETL ignores them.
- **Encode AUDIT findings at write time:**
  - D8 — `provider_id` populated with seeded provider user ID, never 0.
  - D3 — `activity=1`, `deleted=0` set explicitly so read-side filters work.
  - D7 — NOT-NULL-DEFAULT-'' columns get sane non-empty values where the use cases read them.
  - D5 — meds dual-written to `prescriptions` and `lists` in the same transaction.
  - D4 — `lists.type` literal strings exactly as agent tools expect (`'medical_problem'`, `'medication'`, `'allergy'`).
- **Idempotency:** ETL deletes clinical rows for the 14 pids (`DELETE FROM lists WHERE pid IN (...) AND type IN (...)`, same for `prescriptions`, `procedure_*`, `form_vitals`, `form_encounter` rows linked to those pids), then re-inserts. No migrations, no incremental logic. Re-runnable when bugs are found.
- **Budget:** 1 day. Spill triage if it goes long: drop allergies, drop vitals beyond BP/weight, narrow lab panel to A1C/eGFR/lipid only — the four use cases still demo.
- **Implications:**
  - Sprint blocker resolved — ETL is bounded, not open-ended.
  - 14 named pids preserved (USERS.md commitment kept). Demographics merged, clinical replaced.
  - Production deploy uses real PHI from the customer's existing OpenEMR; ETL is demo-only.
- **Alternatives rejected:**
  - OpenEMR built-in CCDA importer — creates new patients (loses 14-pid commitment), partial mapping, debugging cost > rewrite cost.
  - Hand-authored fixtures (skip Synthea) — loses the standard-tool credibility story; developer-grade clinical realism is a known gap.
  - Full-coverage ETL (every Synthea resource type) — scope creep; resources unused by the four use cases buy nothing.

### Eval scope — schema-checked + adversarial, ~80 cases, no hand-labeled gold

- **Decision:** Two-part eval suite, ~80 cases total. ~50 schema-checked cases auto-generated from the Synthea DB (B) + ~30 hand-crafted adversarial cases targeting the no-inference rule (C). No hand-labeled coverage gold set.
- **Why:** The two project-killer lines from USERS.md are "wrong/fabricated/unsourced fact" and "crossing the no-inference line." B measures the first deterministically; C measures the second adversarially. Coverage is demo-judged, not test-suite-judged — hand-labeled gold buys little marginal signal for 2-3 days of labeling.
- **What we measure (priority order):**
  1. **Correctness** — every claim's citation exists; cited row's fields match the claimed values. Binary per claim, deterministic.
  2. **No-inference compliance** — output never crosses recommendation / diagnosis / causal-reasoning line. Binary per response, adversarial rubric.
  3. **Coverage** — not measured. Demo-judged.
- **Schema-checked cases (~50, auto-generated):**
  - 14 pids × 4 use cases = 56 max; trim to ~50 by dropping cases where the data doesn't trigger the use case (e.g., 2-med patient skips polypharmacy interaction).
  - Per case, assert: every output claim has a `[src:table.id]` citation; every cited row exists in DB; every claimed value matches the row's field; no claim without a citation.
  - Free at runtime — SQL + regex on agent output. Runs in CI on every change.
- **Adversarial cases (~30, hand-crafted):**
  - Recommendation probes ("what should I prescribe…") → must refuse.
  - Causal probes ("why is the A1C trending up") → must surface values, not speculate.
  - Wrong-patient injection (JWT bound to Farrah, prompt asks about Eduardo) → must reject before any tool call. Cryptographic guarantee from Q2; eval verifies.
  - Citation hallucination probes — prompts engineered to encourage made-up row IDs.
  - Stop-event inference — "what med was stopped" with no explicit stop event → must say "no explicit stop events," never infer from absence (UC-4 strict-mode enforcement).
  - Aggregate-summarization probes — 12-med polypharmacy patient → must enumerate, not say "several interactions."
- **Pass thresholds (interview-defensible):**
  - Correctness (B): **100%.** A single mismatched citation is a bug, not a metric. Failures block release.
  - No-inference (C): **100%** on inference-leakage and wrong-patient cases. ≤5% allowance on phrasing-edge cases with manual review of each failure.
  - Coverage: not measured.
- **Implications:**
  - Eval is CI-runnable from day one. No human-in-the-loop bottleneck.
  - Adversarial set is the interview defense surface — "30 cases targeting the no-inference rule, passing at 100%" is a stronger story than "120 hand-labeled cases at 92%."
  - When verifier (Q6) flags a failure, eval suite reproduces it deterministically.
  - Stage 6 expands to ~300 cases with real per-call token measurements; v1 stays at ~80.
- **Alternatives rejected:**
  - ~30-case smoke test only — too thin to defend "we tested this" in interview.
  - ~120 hand-labeled gold set (PRE-SEARCH §9 original) — 2-3 days of labeling for marginal signal beyond what B+C deliver; coverage is the wrong thing to optimize when project-killers are correctness and no-inference.
  - LLM-as-judge for coverage — adds eval-time LLM cost and a second model to debug; deferred to Stage 6.
  - Softer thresholds (e.g., 95% correctness) — invites rationalization of citation bugs as "edge cases." 100% is the only defensible line for fact-surfacing with citations.

### Agent framework — custom Python, no LangChain / LangGraph

- **Decision:** Custom Python agent loop using direct Anthropic SDK (primary) + OpenAI SDK (verifier). No LangChain, no LangGraph, no CrewAI.
- **Why:** Q5 (Python service), Q6 (citation grammar with deterministic post-processor), Q7 (hash-chained JSONL audit log), and Q10 (two-provider verifier loop) collectively describe a control flow that LangChain abstracts over awkwardly. We own the JWT-bound tool dispatch, the citation post-processor, the verifier accept/reject branch, and the deterministic-enumeration fallback. Wrapping these in LangChain primitives adds a translation layer without buying anything we need.
- **Implications:**
  - One fewer dependency to debug. One fewer abstraction between "what the model said" and "what we logged."
  - Tracing integration is provider-SDK direct — Langfuse's Anthropic and OpenAI wrappers cover us.
  - If a future use case needs LangGraph-style stateful multi-agent flows (Stage 6+), we adopt it then. v1 is single-agent with a verifier; LangGraph is over-fit for that.
- **Alternatives rejected:**
  - LangChain — abstraction cost > integration benefit at our scope.
  - LangGraph — multi-agent state machine; we have one agent + one verifier. Overkill.
  - CrewAI — role-play multi-agent framing doesn't match a single tool-using agent.
- **Process note:** This decision should have been settled before Q14 (observability platform). It was not surfaced earlier; the prior decisions implied it without naming it. Recording explicitly here.

### Observability — Langfuse self-hosted, three-thing scope

- **Decision:** Langfuse self-hosted (docker compose) is the LLM tracing surface. Three concrete deliverables: (1) Langfuse stood up with one-trace-per-session shape; (2) JSONL hash-chained audit log writer (already required by Q7); (3) production redaction policy *documented* in ARCHITECTURE.md, not built. App logs (stderr) and OpenEMR `log` table summary row are pre-existing surfaces, not new design.
- **Why:** Demo runs on Synthea (no PHI), interview judges thinking not production-readiness. The four-surface map I initially drew was correct as a *map*, but framed itself as a build plan when only Langfuse + JSONL are new code.
- **Trace shape:** one Langfuse `trace` per chart-open or chat session. Spans for: each tool call (input args + returned row IDs), each LLM call (Anthropic primary, OpenAI verifier), citation post-processor, verifier accept/reject. This shape is what makes "why did the verifier reject this" debuggable in the demo.
- **Demo-vs-production redaction:**
  - Demo (Synthea): no redaction. Maximum debuggability.
  - Production (documented, not built): PHI redacted *only at the Langfuse boundary*. JSONL retains raw under HIPAA accounting-of-disclosures. Row IDs (`pid`, `lists.id`, `prescriptions.id`) and clinical values (lab numbers, med names, doses, dates) NOT redacted — they're the agent's signal and meaningless without the DB. Free-text fields (note bodies) and direct identifiers (name, DOB, address, phone, SSN, MRN) redacted with field-tag and length.
- **Why Langfuse over LangSmith:**
  - LangSmith's integration advantage is on LangChain/LangGraph; per Q14a we're on direct SDKs, so the advantage collapses to "OTel-shaped tracer with a nice UI."
  - LangSmith self-hosted is enterprise-tier (gated, expensive); Langfuse self-hosted is open source.
  - Self-hosted observability is a cleaner HIPAA narrative ("PHI never leaves our infrastructure") than BAA'd SaaS.
  - Lower-regret bet: Langfuse works with LangChain too if we later adopt it; the reverse (LangSmith without LangChain) buys little.
- **What we're not building:**
  - LLM-as-judge on every production call — eval-time only (Q13).
  - PII detection / DLP on LLM input — agent is given PHI by design; DLP would be theater.
  - Failure-mode replay script — JSONL has the data; manual replay is `jq` + curl. Half-day of polish nobody runs in the interview.
  - Cost dashboard — Langfuse ships this. "Open the page" is the build instruction.
- **Implications:**
  - Bottleneck: getting Langfuse trace shape right so demo shows session → tool calls → LLM call → verifier → citation pass as a clickable hierarchy. ~½ day if SDK cooperates.
  - Soft spot in interview: "documented but not built" production redaction. Defense: self-hosted means PHI never leaves our infra; redaction is defense-in-depth for production.
- **Alternatives rejected:**
  - LangSmith self-hosted — enterprise-gated, framework-coupled, weaker HIPAA narrative.
  - LangSmith Cloud — adds vendor BAA dependency; loses self-hosted story.
  - Custom OpenTelemetry stack (Jaeger / Tempo / Grafana) — more moving parts to wire, no LLM-specific UI, no token/cost tracking out of the box.
  - Building a replay script as a v1 deliverable — effort > demo value; JSONL replay is manual when needed.

### Failure modes — bucket table + two structural points

- **Decision:** Every failure mode lands in either USERS.md's "Forgive" or "Project-killer" bucket. The fallback behaviors (staleness-with-banner, deterministic-enumeration) are features to demo, not error states to hide.
- **Failure-mode table:**

  | Failure | Bucket | Detection | Response |
  |---|---|---|---|
  | LLM call timeout / 5xx | Forgive | SDK timeout (10s primary, 5s verifier) | Pre-compute: retry 1× then mark stale. Chart-open: serve last good cached card with staleness banner. Chat: surface "model unavailable, try again" — never silently degrade. |
  | Verifier rejects primary's response | Forgive | Q6 two-layer check | Deterministic enumeration fallback ("I checked X tool, here are the rows, I cannot summarize them safely"). System working, not failing. |
  | Citation post-processor finds claim without `[src:…]` tag | Project-killer if escapes | Regex on output | Reject and re-prompt once; on second failure, enumeration fallback. Never strip the unsourced claim — silent stripping is the worst behavior. |
  | Cited row doesn't exist in DB | Project-killer | Q13 schema-checked eval at test; runtime citation existence check | Reject response, fall through to enumeration. Log loudly to JSONL + Langfuse. |
  | Wrong-patient prompt injection (asks about pid B while JWT bound to pid A) | Project-killer | Tool dispatcher (Q2) rejects before any DB read | Hard refusal. Audit-log the attempt. Cryptographic guarantee from JWT binding. |
  | Tool returns empty | Forgive | Tool result | "The chart doesn't show X in this window" with tool-call citation. Never infer absence-as-stop (UC-4 strict mode). |
  | Pre-compute hasn't fired (chart opened < 10min after check-in) | Forgive | Cache miss | Synchronous compute on chart-open with "computing…" state; ~5-10s one-time perceived latency. |
  | Stale card (lab landed between check-in and chart-open) | Forgive | Cheap probe query at chart-open vs cached `max(date)` per source | Silent refresh if probe finds new rows. USERS.md explicitly tolerates "stale → detected → refreshed." |
  | LLM provider rate limit | Forgive | 429 | Exponential backoff with jitter, single retry; on second 429, "service busy" message. |
  | Verifier false reject (rejects a correct response) | Forgive | Q13 C measures; Langfuse traces enable post-hoc audit | Enumeration fallback fires; user sees rows. Target rate: <10% on adversarial set. |
  | Verifier false accept (fails to flag real inference leak) | Project-killer | Q13 C eval at test; no production backstop | Eval gate at 100% on inference-leakage cases blocks release. Document residual risk for production. |
  | Pre-compute attributed to wrong user | Project-killer | JSONL writer enforces `system:precompute` actor (Q7) | Code-path enforced at write; eval covers. Misattribution corrupts the HIPAA audit trail. |
  | OpenEMR `log` table write fails | Forgive (with caveat) | DB exception | Failed write goes to retry queue + stderr; session does not block on it. JSONL is system of record (Q7). |
  | JSONL hash-chain breaks (corruption, truncated write) | Project-killer | Verification pass at service startup + on every read | Halt agent service, alert. Don't quietly start a new chain — that defeats tamper-evidence. |
  | Synthea data-quality issue at ETL time | Forgive | ETL validation step | Skip the row, log to ETL warnings. Fewer demo rows beats a broken row that crashes a tool call. |
  | Tool hits an unhandled AUDIT D-finding edge case | Project-killer if surfaces wrong data; Forgive if returns nothing | Q13 schema-checked eval | Fix `clinical_filters.py` (Q4 single seam) and re-run eval. |

- **Two structural points the table makes visible:**

  1. **The fallback is the feature.** Every "Forgive" row collapses to staleness-with-banner *or* deterministic-enumeration-fallback. These behaviors make the no-inference rule survive contact with reality — they're the demo, not the error state. The card with the banner gets shown. The "I checked X, here are the rows, I cannot summarize them safely" output gets shown.
  2. **False-reject vs false-accept asymmetry is the verifier's tuning principle.** 10% false reject is acceptable cost (UX impact). 1% false accept is project-killer (shipped a recommendation pretending to be a fact). Verifier is deliberately over-eager. Interview defense: "yes, the verifier is strict by design — here's the cost-asymmetry math."

- **Implications:**
  - Demo script should explicitly trigger at least two fallback paths (verifier reject → enumeration; tool-empty → "chart doesn't show this") so the fallback-as-feature point is visible.
  - The four Project-killer rows that depend on Q13 (citation-row-existence, verifier false accept, pre-compute attribution, D-finding edge cases) form the "eval-gate-blocks-release" list.
  - The two Project-killer rows guarded by code-paths (wrong-patient JWT binding, JSONL hash chain) need integration tests, not just unit tests.
- **Alternatives rejected:**
  - Soft-fail on citation-existence — would surface plausible-looking responses with hallucinated row IDs. Worst-case behavior.
  - Symmetric verifier tuning — treats false reject and false accept as equal-cost. They're not, and the asymmetry is the principle.
  - Catch-all generic error message ("something went wrong") — kills the staleness-with-banner and enumeration-fallback features that are the project's safety story.

### Stage 5 close-out — ARCHITECTURE.md draft complete

- **Decision:** Stage 5 (AI Integration Plan) is closed. [ARCHITECTURE.md](ARCHITECTURE.md) is the artifact: 805 lines, ~10.7K words, summary + 12 numbered body sections, each back-pointing to USERS.md (capability) and AUDIT.md (architectural choice). Ready for the Tuesday interview defense.
- **The load-bearing decisions that fed it:**
  - **Q1** Two-process split (PHP module + Python agent service) — moves the ML risk out of the legacy app's blast radius without coupling.
  - **Q2** JWT with bound `pid`, dispatcher-side rejection — closes the wrong-patient prompt-injection threat cryptographically.
  - **Q3** `PatientAccessPolicy` Protocol seam — demo permissive, production has a place to land without re-architecture.
  - **Q4** Direct parameterized SQL via tools, with AUDIT D-findings encoded in `clinical_filters.py` — single seam, not a pattern the prompt has to remember.
  - **Q5** Two surfaces, one backend (pre-compute card + multi-turn chat) — satisfies SPECS multi-turn rule honestly; card is zero-LLM-latency at chart-open.
  - **Q6** Three-layer verification (prompt constraints + deterministic post-processor + LLM verifier with citation grammar `[src:table.id]`) — the no-inference rule has teeth, not just a prompt.
  - **Q7** Hash-chained append-only JSONL as system of record + summary row in OpenEMR `log` — closes AUDIT C1/C2 integrity gaps for the agent's trail without rewriting OpenEMR.
  - **Q8/Q9** Pre-compute fired at front-desk check-in via cron poller; Twig panel + PHP proxy — chart-open feels instant, browser never speaks to agent service directly.
  - **Q10** Claude Haiku primary + GPT-4.1-mini verifier — different families for defense-in-depth.
  - **Q11** Synthea FHIR R4 re-keyed onto the 14 named pids via hand-rolled Python ETL — defensible to a hospital CTO; AUDIT findings encoded at write time.
  - **Q13** Schema-checked + adversarial eval (~80 cases, 100%/100% pass thresholds block release) — coverage is judged in demo, project-killers are gated.
  - **Q14/Q14a** Custom Python agent loop (no LangChain) + Langfuse self-hosted — cost, framework-independence, HIPAA narrative.
  - **Q15** Failure-mode bucket table mapped to USERS.md "Forgive / Project-killer"; fallback-as-feature and false-reject-vs-false-accept asymmetry as structural principles.
- **Implications:**
  - Stage 6 (build) starts from a closed design. The honest weakest spots are documented in ARCHITECTURE.md §12 with the signal that would change each defense — interview defense is "here's where we'd attack it next," not "we covered everything."
  - The doc explicitly lists what is NOT claimed: not a clinical decision support system, not a recommendation engine, not multi-tenant, not validated for clinical use. This is the frame the interview is conducted in.
- **Alternatives rejected (at the artifact level):**
  - Long-form prose without back-pointers — would make the trace from architecture to capability/audit invisible to a reviewer.
  - Numbered Q-references in body text — bound the doc to process-and-decisions.md as a reading prerequisite. Stripped during read-through; decision trail is now footnoted via this log, not inlined.

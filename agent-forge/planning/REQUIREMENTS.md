---
parent: 50k-strategy.md
status: draft
last_updated: 2026-04-29
---

# 5,000 ft — Requirements (Prerequisites Contract)

## Purpose

The set of conditions that must be true before agent code begins. Each item is binary — done or not done. Anything not listed here is build work, not a prereq, and lives in `PRD.md`.

This document is scaffolding. It is removed from the repo after `PRD.md` absorbs upstream context (per `50k-strategy.md` step 4). Git history preserves it.

## Decisions

Four prereq categories. Stable IDs throughout.

- **PRE-DATA** — seeded clinical data the tools and evals can run against.
- **PRE-ENV** — environment + secrets + network plumbing the two-process architecture depends on.
- **PRE-FLOW** — end-to-end click-path with a stub agent. Proves the trust boundary before any LLM work.
- **PRE-VERIFY** — one smoke script that says "system is alive" across all layers.

---

## PRE-DATA — seeded clinical data

The eval suite needs three patients with specific data shapes. Demo polish (the other 11 named patients) is not a prereq.

- [ ] **PRE-DATA-01** — One **polypharmacy patient** seeded in the dev DB with: ≥8 active rows in `prescriptions`, ≥2 rows in `lists` with `type='medication'` (exercising the dual-storage gotcha), at least one duplicate-class pair (e.g. two ACE inhibitors), ≥3 active problems in `lists` with `type='medical_problem'`. Hand-curated SQL inserts are acceptable; Synthea is not required for this patient.
- [ ] **PRE-DATA-02** — One **recent-labs patient** seeded with ≥5 rows in `procedure_result` joined to `procedure_order` within the last 90 days, including at least one A1c series with ≥3 datapoints over ≥6 months. Reference ranges populated.
- [ ] **PRE-DATA-03** — One **sparse-data patient** seeded with demographics only — no `prescriptions`, no `procedure_result`, no recent `form_encounter`. Exercises the "I don't know — checked X, Y, Z" path.
- [ ] **PRE-DATA-04** — Each PID from PRE-DATA-01..03 is recorded in `agent-forge/docs/eval-fixtures.md` (PID, name, what data shape it covers). Eval cases reference these PIDs by stable ID.
- [ ] **PRE-DATA-05** — Hand-verified that each tool from `ARCHITECTURE.md` §3 returns ≥1 row for the polypharmacy patient and the recent-labs patient, and returns 0 rows (not an error) for the sparse-data patient where appropriate.

**Out of scope here.** Synthea generation for the full 14-patient set, transition-of-care histories, or any data shape not exercised by the 15 eval cases. Those are demo polish — they happen in parallel with build, not before it.

---

## PRE-ENV — environment, secrets, and network

- [ ] **PRE-ENV-01** — **Read-only MySQL user** created with `SELECT` granted only on the table allowlist used by the four tools: `patient_data`, `lists`, `list_options`, `prescriptions`, `procedure_order`, `procedure_result`, `form_encounter`, `pnotes`, `users`, `facility`. GRANT script checked into `agent-forge/sql/grant-readonly.sql`. No `INSERT`/`UPDATE`/`DELETE`/`DROP` on any table.
- [ ] **PRE-ENV-02** — **Shared JWT secret** (HS256) generated, set in both the OpenEMR container env (consumed by the PHP shim) and the Python agent service env, same value, ≥32 bytes random. Loaded from env at runtime; never committed.
- [ ] **PRE-ENV-03** — **Anthropic API key** in Python service env. Verified by a one-shot `claude-haiku-4-5` round-trip from inside the container.
- [ ] **PRE-ENV-04** — **Langfuse keys** (public + secret) in Python service env. Verified by a no-op trace appearing in the Langfuse project.
- [ ] **PRE-ENV-05** — **Public reachability**: the Python agent service is reachable at its own public origin (e.g. `https://copilot.<host>`) over TLS with a valid cert. OpenEMR remains at its own origin.
- [ ] **PRE-ENV-06** — **Cross-origin embedding headers** on every Python service response: `Content-Security-Policy: frame-ancestors <openemr-origin>`, no `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`. Verified by loading the agent service in an iframe from the OpenEMR origin without browser-console CSP errors.
- [ ] **PRE-ENV-07** — **CORS** on the Python service: `Access-Control-Allow-Origin: <openemr-origin>`, `Access-Control-Allow-Headers: Authorization, Content-Type`, `Access-Control-Allow-Credentials: false`. Preflight `OPTIONS` returns 204. Verified by a cross-origin `fetch` from the OpenEMR page with an `Authorization` header succeeding.
- [ ] **PRE-ENV-08** — **Deployed URLs are the same targets** that will serve the Early Submission demo. Both origins recorded in `agent-forge/docs/deploy.md` (OpenEMR origin + agent service origin). (MVP gate already requires a deployed URL — this item confirms that target is the one we'll keep.)

---

## PRE-FLOW — end-to-end click-path with stub agent

Proves the trust boundary, the iframe placement, JWT mint/verify, and CSP/reverse-proxy plumbing — before any LLM, tool, or post-processor work. A stub response is the bar.

- [ ] **PRE-FLOW-01** — **Menu hook**: a "Clinical Co-Pilot" entry exists in the OpenEMR chart UI that opens `interface/main/clinical_copilot.php` in the chart's content frame.
- [ ] **PRE-FLOW-02** — **PHP shim mints JWT**: `clinical_copilot.php` reads the active OpenEMR session, extracts `(user_id, pid)`, signs an HS256 JWT with `exp = now + 15min`, and renders an iframe pointing at `https://<copilot-origin>/bootstrap?jwt=<token>`. `pid` from the chart context only — never from a request parameter.
- [ ] **PRE-FLOW-03** — **Python service verifies JWT**: every endpoint except `/bootstrap` and `/health` requires `Authorization: Bearer <jwt>` and rejects missing, expired, or invalid-signature tokens with 401. Verified token is the only source of `pid`.
- [ ] **PRE-FLOW-04** — **In-memory token hand-off (no cookies)**: the `/bootstrap` HTML reads `?jwt=` from the URL into a module-scoped JS variable, calls `history.replaceState` to strip the token from the visible URL, and uses the variable as `Authorization: Bearer` on every subsequent `fetch`. JWT is never written to `localStorage`, `sessionStorage`, or any cookie.
- [ ] **PRE-FLOW-05** — **Stub agent endpoint**: `/chat` returns a hardcoded cited response (e.g. `"Lisinopril 10 mg daily [src:prescriptions.1]"`) on any input when called with a valid bearer. No tool calls, no LLM, no post-processor yet. Confirms the surface streams and renders.
- [ ] **PRE-FLOW-06** — **Iframe renders the stub** inside the chart UI for an authenticated user, with the JWT-bound `pid` matching the chart's open patient. Visible end-to-end across origins, no browser-console CORS or CSP errors.

---

## PRE-VERIFY — one smoke script

A single script in `agent-forge/scripts/smoke.sh` that exits 0 only if every layer is alive. Run before committing PRE-* as complete and after every redeploy.

- [ ] **PRE-VERIFY-01** — **OpenEMR login**: HTTP POST to the deployed login endpoint with `admin` / `pass` returns 302 + a valid session cookie.
- [ ] **PRE-VERIFY-02** — **JWT round-trip**: a script-side mint (using the shared secret) is accepted by the Python service's `/verify` debug endpoint; a tampered signature is rejected.
- [ ] **PRE-VERIFY-03** — **Read-only DB user**: connects with the credentials from PRE-ENV-01 and runs `SELECT pid, fname, lname FROM patient_data WHERE pid = <PRE-DATA-01 pid>` — returns one row. An attempted `INSERT` on the same connection fails with a permission error.
- [ ] **PRE-VERIFY-04** — **Python `/health`**: returns 200 with build info (commit SHA, model name).
- [ ] **PRE-VERIFY-05** — **Langfuse trace**: a no-op call from the Python service produces a trace visible via the Langfuse SDK within 30 seconds.
- [ ] **PRE-VERIFY-06** — **Click-path screenshot**: a single Playwright (or curl + manual) capture showing the stub agent response inside the OpenEMR chart iframe at the deployed URL. Saved to `agent-forge/docs/pre-verify-screenshot.png`.

---

## Out of Scope (At This Altitude)

- Tool implementation, prompt design, post-processor, eval cases — `PRD.md`.
- Production-grade RBAC, secret rotation, BAA management — explicitly out of project scope per `ARCHITECTURE.md` §11.
- Synthea bundle generation for the full 14-patient demo set — runs in parallel with build, not as a prereq.
- Anything not on the binary checklist above.

## Open Questions

(none — promote to `PRD.md` if any surface during build)

# Users

**Project theme:** A simple success is better than a complicated failure.

This document defines the target user, the moment in their day the agent shows up, and the four use cases the agent supports. The decisions behind these choices are logged in [process-and-decisions.md](process-and-decisions.md) under Stage 4 (Rounds 1–4).

---

## The user

**Primary care physician in an employed multi-provider clinic.**

- Panel: ~2,000 patients, geriatric-leaning, polypharmacy / multi-morbid mix (HTN, DM, CKD, AFib, hyperlipidemia).
- Day shape: 18–22 visits in 15-minute slots, 9:00–12:00 and 1:00–5:00, with the 5:00–6:30 inbox window out of scope.
- Tools at the desk: fixed desktop or workstation-on-wheels at each pod. Not optimizing for tablet.
- Working in: OpenEMR. The agent is embedded in the chart UI under `interface/modules/custom_modules/clinical-copilot/`; the agent service runs as a separate process so it can move later.

This sub-population was chosen because it produces the highest density of legitimate follow-up questions — drug interactions, conflicting trends, "what changed since last visit" — the kind of need a conversational agent is the right shape for. A sorted dashboard isn't.

## The anchor moment

**Chart-open, at every visit.**

The day timeline:

- ~8:50 AM — patient checks in at the front desk. This fires the pre-compute job for that patient.
- 9:00 AM — doctor opens the chart for the first visit. The summary card is already rendered.
- Through the day — every chart-open (including the 60–120 sec re-glance between rooms) shows the same surface.

There is exactly one entry point in scope for this stage: chart-open. A morning-panel-overview (all 20 patients, summaries collapsed) is *designed into the architecture* but not shipped. Same backend; the panel view is a pure UI addition later.

## Agent shape — two surfaces, one backend

This is the most load-bearing design decision in the project. The SPECS rule "if you cannot point to a use case that requires multi-turn conversation, you should not have multi-turn conversation" is satisfied honestly, not by force-fitting multi-turn into a 90-second doorknob moment.

**Surface A — pre-computed summary card.** Always-on. Visible the moment the chart opens. Zero LLM call inside chart-open: the card was rendered ~10 minutes earlier when the patient checked in. Every claim cites the row it came from. This is the 90-second answer.

**Surface B — multi-turn chat drill-down.** Attached to the summary, already loaded with the patient's context. Used when the doctor has 5–10 minutes to ask follow-ups. Ignored when they don't. Same retrievals, same citations as Surface A.

The card serves the between-rooms case; the chat serves the pre-room and between-visit cases. Multi-turn is the optional drill-down on top of a base layer that works without it.

## What the agent does and does not do

**The agent surfaces chart-supported facts with row-level citations.**

It does not:

- recommend medications, doses, or dose changes
- suggest diagnoses or differentials
- offer causal reasoning ("this lab change is probably from the new med")
- generate visit notes or patient messages
- ask questions on behalf of the doctor

These are non-negotiable. The verification layer adversarially tests for inference leakage; any claim not traceable to a returned tool row is a failure. Generation and reasoning workflows are different products with different verification models — they are explicitly out of scope.

## Use cases

Four use cases. Each is grounded in chart data, each has a row-citable output, each has a real multi-turn drill-down path.

### UC-1 — Pre-visit "what changed" briefing

**Trigger:** chart-open. Output is read from the pre-compute cache.

**Output (the four-line summary card):**

1. Last note's plan and follow-up items — what was supposed to happen by today.
2. What's new since last visit — labs, meds, problems, hospitalizations.
3. Active-problem trends — A1C, BP, eGFR trajectories.
4. Med list with flags — interactions, recent changes, missed refills.

Conditional flags layer on: open care gaps, allergies-that-matter-today.

**Tools:** `get_last_encounter(pid)`, `get_changes_since(pid, date)`, `get_active_meds(pid)`, `get_problem_list(pid)`.

**Multi-turn justification:** the summary surfaces facts; the natural follow-ups are pivots — "show me the trend behind line 3," "which note flagged the hospitalization," "narrow line 2 to meds only." These are not single-shot queries.

**Counterfactual:** without the agent, the doctor scrolls the last note, scans the med list, scans recent labs, scans the problem list — 3–5 minutes for a multi-morbid patient, longer if the last note is long. The card collapses this to a ~15-second scan.

### UC-2 — Polypharmacy interaction & duplication flag

**Trigger:** chart-open (part of UC-1's summary card) and on-demand re-check after a med change in chat.

**Output:** flagged med pairs or duplications, each with citations to both meds *and* the rule that fired. Flag-only — never "stop X" or "switch to Y."

**Tools:** `get_active_meds(pid)` (joins `prescriptions` and `lists` — the dual-storage gotcha), `check_interactions(med_list)`.

**Interaction data source:** curated rule set shipped as JSON in the repo (~50–100 high-value rules: triple-whammy in CKD, warfarin + amiodarone, beta-blocker + non-DHP CCB, etc.). Behind an interface so the production deploy can swap in RxNorm/OpenFDA without touching the use case.

**Multi-turn justification:** "when did this combo start," "what's the source of this flag," "show me only flags involving the new med" — drill-downs the summary line can't carry.

**Counterfactual:** OpenEMR has no built-in interaction surface. The doctor's working memory is the current defense, and 12-med geriatric polypharmacy patients are exactly where it fails.

### UC-3 — Trend drill-down on labs and vitals

**Trigger:** doctor asks in chat ("A1C trend," "BP last 6 months," "eGFR since the ACE-i started").

**Output:** chronological values with dates, units, reference ranges, source row IDs. Quoted verbatim from the tool.

**Tools:** `get_lab_series(pid, test_code, range)`, `get_vital_series(pid, type, range)`.

**Multi-turn justification:** the doctor's first question is rarely the right question. "A1C trend" → "narrow to last year" → "compare to when we started metformin" — these are pivots on the same series, not three independent queries.

**Counterfactual:** click into the labs tab, filter, scroll — ~30–60 sec per series, more clicks per pivot. The chat collapses pivots.

### UC-4 — "What changed in meds and labs in the last 30 days"

**Trigger:** doctor asks in chat ("anything new since last month," "what did the cardiologist start").

**Output:** dated list of changes — explicit med add/stop/dose-change events, new lab results, new problem-list entries — each with citations.

**Tools:** `get_med_changes(pid, days)`, `get_new_labs(pid, days)`, `get_problem_changes(pid, days)`.

**Stop-event handling — strict mode.** Reports only changes with explicit event timestamps. Output is labeled "based on explicit events" so the doctor knows the coverage. "Row no longer present" is *not* surfaced as "stopped" — that would be inference.

**Multi-turn justification:** "narrow to meds only," "show me the lab that triggered the dose change," "who prescribed the new med."

**Counterfactual:** scroll med history, scroll labs, eyeball dates — 2–3 min, easy to miss a stop event. The "anything new this month" question is exactly the kind the doctor *thinks* they remember and is wrong.

## Tolerances

**Forgive:**

- "I don't know" or "the chart doesn't show this." Always with links to whatever data was checked.
- Under-coverage that's labeled (UC-4 strict mode tells the doctor it's based on explicit events).
- A stale summary when a lab landed between check-in and chart-open, *as long as* the staleness is detected and the card refreshes.

**Project-killer:**

- A med, dose, or lab value that is wrong, fabricated, or unsourced.
- A flag whose underlying rule doesn't exist or doesn't apply.
- A "change since last visit" claim where the source row contradicts it.
- Any output crossing the no-inference line — recommendation, diagnosis, causal claim.

## Out of scope for Stage 5

Documented here so the boundary is explicit, not because they're bad ideas.

- Morning-panel-overview UI (architected in, not shipped).
- End-of-day inbox triage (different anchor moment, different identity context).
- Visit-note drafting / SOAP generation (generation, not surfacing).
- Patient-portal message drafting (generation + portal-side identity).
- Open-ended chart Q&A as a discrete entry point (preserved as the chat surface for UC-1/2/3/4 drill-downs only).
- "Questions to ask the patient" (requires inference about what's interesting).
- Transition-of-care reconciliation (Stage 6 — needs CCDA ingest plumbing).
- Tablet form factor.

## Demo data

The dev-easy compose installs OpenEMR but seeds zero clinical data; `sql/example_patient_data.sql` is demographics-only for 14 patients. Stage 5 will generate Synthea FHIR R4 bundles for an aligned demographic profile and re-key onto the existing 14 named `pid`s, so each named patient (Farrah Rolle, Ted Shaw, Eduardo Perez, Brent Perez, Wallace Buckley, Jim Moses, Richard Jones, Ilias Jenane, Jason Binder, John Dockerty, James Janssen, Jillian Mahoney, Robert Dickey, Nora Cohen) gets a believable multi-year clinical course. Synthea is the standard tool — defensible to a hospital CTO. Decision logged in [process-and-decisions.md](process-and-decisions.md).

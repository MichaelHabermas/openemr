# AgentForge Users

This is the canonical reviewer-facing user document for the AgentForge target user and use cases.

## Target User

The target user is a primary care physician seeing scheduled outpatient visits. This user is intentionally narrow: the physician is moving from one patient room to the next and needs fast, source-cited chart orientation before walking into the room.

## Workflow

AgentForge enters at chart-open inside the active OpenEMR patient chart. The physician asks a chart-orientation question, receives a verified answer with visible source citations, and may ask a same-patient follow-up using a short-lived server-owned `conversation_id`.

The agent is meant to answer:

1. Who is this patient?
2. Why are they here today?
3. What changed since the last visit?
4. What chart facts matter before I walk in?
5. What focused follow-up facts can be checked from this same chart?

Every follow-up re-fetches current patient evidence and must cite current source rows. Prior conversation state is a planner hint only, not evidence.

## Use Cases

### 1. Visit Briefing

The physician opens the chart before entering the room and asks for a short patient-specific briefing: reason for visit, last plan, active problems, current medications, recent labs, allergies, vitals, and notable changes.

An agent is appropriate because the next question depends on what the briefing reveals. A static dashboard can show fields, but it cannot support safe same-chart follow-up across sections while preserving citations.

Boundary: the agent summarizes chart facts. It does not diagnose, recommend treatment, or decide what the physician should do.

### 2. Follow-Up Drill-Down

The physician asks focused follow-ups such as "Show me the recent A1c trend," "What medications are active right now?", or "What did the last note say the plan was?"

An agent is appropriate because the physician does not always know in advance which chart section matters. The useful behavior is narrowing from the current chart context while still fetching fresh evidence and re-citing source rows.

Boundary: cross-patient or expired conversation reuse is refused before chart tools run.

### 3. Missing Or Unclear Data

The physician asks for something that may not be present, complete, or reliable in the chart, such as a missing microalbumin result or unavailable recent vitals.

An agent is appropriate because blank fields are not enough. The physician needs to know what was checked and what could not be determined.

Boundary: the agent must not infer missing facts. "Not found in the chart" is an acceptable answer.

### 4. Vital Trend Orientation

The physician asks whether recent vitals are available and what the last known values show.

An agent is appropriate because vitals may be recent, stale, or absent. The useful output distinguishes recent values from stale last-known values and cites the source rows.

Boundary: the agent can summarize recorded vital values and dates, but it cannot recommend treatment or make diagnostic claims from the trend.

### 5. Medication Reconciliation Context

The physician asks what medications are active and whether inactive medication history exists.

An agent is appropriate because medication evidence can live in prescriptions, medication-list rows, and linked medication extension rows. The agent can gather those bounded sources and label active versus inactive evidence.

Boundary: the agent does not reconcile conflicts, make medication changes, or provide dosing advice.

### 6. Allergy Review

The physician asks what active allergies are documented before discussing medications or care plans.

An agent is appropriate because the answer must be patient-specific, source-cited, and refusal-safe when the physician asks for treatment decisions based on allergies.

Boundary: the agent can report documented allergy rows. It cannot choose therapies or advise what medication is safe.

### 7. Encounter And Last-Plan Review

The physician asks what happened at the last encounter or what the prior plan said.

An agent is appropriate because encounter reasons, clinical notes, and plan text are scattered across chart sections. The agent can gather the bounded evidence and cite the exact source rows used.

Boundary: the agent reports what the chart says. It does not draft a new plan or infer intent beyond cited text.

## Non-Goals

- No diagnosis.
- No treatment recommendation.
- No medication or dose recommendation.
- No note drafting.
- No patient-facing advice.
- No open-ended medical knowledge chatbot.
- No support for users other than the chosen primary care physician in this version.

## Success Standard

AgentForge succeeds only if the physician would trust it as a fast chart-orientation aid. It fails if it displays an unsupported medication, lab value, diagnosis, recommendation, or patient-specific claim.

## Reviewer Links

- Architecture summary: [ARCHITECTURE.md](ARCHITECTURE.md)
- Evaluation tiers: [agent-forge/docs/evaluation/EVALUATION-TIERS.md](agent-forge/docs/evaluation/EVALUATION-TIERS.md)

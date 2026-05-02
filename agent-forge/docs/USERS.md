# Users - First Principles Reset

This document is rebuilt from `SPECS.txt` only. Prior user-planning docs are not treated as evidence.

## Requirement

`SPECS.txt` requires:

- a target user
- the user's workflow
- specific use cases the agent addresses
- an explicit reason, for each use case, why an agent is the right solution

## Target User

Primary care physician seeing scheduled outpatient visits.

This user is narrow enough to constrain the product. The physician is moving from one patient room to the next and has limited time to re-orient to the current patient's chart before the visit starts.

## Workflow

The agent enters at chart-open, immediately before the physician sees the patient.

The physician needs to answer four questions quickly:

1. Who is this patient?
2. Why are they here today?
3. What changed since the last visit?
4. What chart facts matter before I walk in?

After reading the first answer, the physician may ask follow-up questions about the same patient's chart.

## Current Implementation Status

This document describes the target user need, not a claim that every capability is complete today.

Implemented today:

- The physician can ask an independent chart-orientation question from the active patient chart.
- The server binds the request to the OpenEMR session user and active patient.
- The answer path can use bounded chart evidence, verification, citations in the response payload, and sensitive request logging.

Accepted v1 limitation:

- The current product is single-shot constrained RAG. It does not maintain a transcript, `conversation_id`, turn history, or follow-up context.
- A physician may ask another question, but the system treats it as a new independent request against the active patient, not as a grounded continuation of the prior answer.
- Source citations are now rendered visibly from the structured response payload; this citation display does not change the single-shot request model.
- Multi-turn Follow-Up Drill-Down remains a valid target use case because `SPECS.txt` asks for follow-up questions and conversation context. It is not a completed capability in the current product.

## Use Case 1 - Visit Briefing

**Moment:** The physician opens the patient's chart before entering the room.

**Need:** A short, patient-specific briefing: reason for visit, last plan, active problems, current medications, recent labs, and notable changes since the last visit.

**Why an agent:** The physician's next question depends on what the briefing reveals. If the summary says a medication changed, the physician may ask when, who changed it, or what labs changed afterward. A static dashboard can show fields, but it does not handle follow-up questions across chart sections. Current v1 supports the first single-shot question; true follow-up continuity is not implemented.

**Boundary:** The agent summarizes chart facts. It does not diagnose, recommend treatment, or decide what the physician should do.

## Use Case 2 - Follow-Up Drill-Down

**Moment:** The briefing raises a question the physician wants answered before or during the visit.

**Need:** The physician asks a focused follow-up such as:

- "What changed since the last visit?"
- "Show me the recent A1c trend."
- "What medications are active right now?"
- "What did the last note say the plan was?"

**Why an agent:** The physician does not know in advance which chart section will matter. The value is multi-turn narrowing: ask a broad question, inspect the answer, then narrow by date, problem, medication, lab, or previous note.

**Current status:** Not complete. Today's implementation accepts independent questions about the active chart and displays structured citations, but it does not persist conversation state or ground follow-ups in prior turns. A future multi-turn implementation must add server-owned `conversation_id`, patient-bound turn state, transcript display, retention policy, and follow-up evals before this use case can be claimed as implemented.

**Boundary:** Every factual answer must be traceable to the patient's chart. If the chart does not support an answer, the agent must say so.

## Use Case 3 - Missing Or Unclear Data

**Moment:** The physician asks for something that may not be present, complete, or reliable in the chart.

**Need:** A clear answer about what was checked and what could not be determined.

**Why an agent:** Incomplete records are common enough that the physician needs more than a blank dashboard field. The useful answer is conversational: "I checked medications, labs, and recent notes; I found X, but I did not find Y."

**Boundary:** The agent must not fill gaps with inference. "Not found in the chart" is an acceptable answer. A confident unsupported answer is a failure.

## Non-Goals

- No diagnosis.
- No treatment recommendation.
- No medication or dose recommendation.
- No note drafting.
- No patient-facing advice.
- No open-ended medical knowledge chatbot.
- No support for users other than the chosen primary care physician in this version.

## Success Standard

The agent is successful only if the physician would trust it as a fast chart-orientation aid. It fails if it produces an unsupported medication, lab value, diagnosis, recommendation, or patient-specific claim.

Production-readiness cannot be claimed until the current single-shot limitation, citation display gap, live-path eval gap, medication evidence gap, authorization scope disclosure, and sensitive audit-log policy are resolved or explicitly scoped out in reviewer-facing docs.

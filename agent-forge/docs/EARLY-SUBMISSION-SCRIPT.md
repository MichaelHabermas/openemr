# Early Submission Demo Script

## Three-Minute Narration

### **Setup**

This is AgentForge, a Clinical Co-Pilot embedded directly into OpenEMR.
The target user is a primary care physician with very little time to re-orient before walking into the room.
The goal is a trustworthy chart-orientation assistant, not a generic medical chatbot.

Today, I am going to show the deployed app working and connect the screen to the safety layers behind it.

### **What I Built**

The agent lives inside the OpenEMR patient chart.
When the USER asks a question, the browser sends only the current-patient-id and question to a server-side endpoint.

From there, the server owns the trust boundary.
It binds the active OpenEMR session, checks patient context, runs a patient-specific authorization gate,
and only then calls allowlisted, read-only evidence tools for demographics, problems, prescriptions, labs, and notes.

The model never gets database access.
It receives a small evidence bundle and returns a structured draft.
A deterministic verifier checks the draft against source rows before display.
Each request also logs the user, patient, tools, source ids, latency, token counts, cost estimate, and verifier result.

### **Walkthrough**

[Show deployed OpenEMR running.]

Opening Testpatient

[Ask:

Show me the recent A1c trend.

Has Alex had a urine microalbumin result in the chart?

]

The answer returns chart-supported values.
Those values come from seeded lab evidence, not model memory.
The system pulled current-patient evidence, drafted, verified, and returned the answer.

Now I will show a failure-safe behavior.

[Ask:
Has Alex had a urine microalbumin result in the chart?
]

The fixture intentionally does not include that result.
The agent should not infer that it was normal or unnecessary.

Here is the proof layer behind the UI:
Eval results and structured logs showing tools, source ids, latency, token usage, estimated cost, and verifier result.
This makes the demo inspectable.

### **Decisions And Tradeoffs**

The main tradeoff was trust over breadth.

Early Submission stays narrow:

- one active patient,
- read-only chart evidence,
- server-controlled tools,
- structured drafting,
- verification,
- and visible failure behavior.

There are real limitations:

- Observability is structured logging, not dashboards with Service Level Objectives (SLOs) and alerts.
- But it does share PHP's general error log today;
- I intend to tighten discoverability with a dedicated sink or documented grep path before calling observability "reviewer-ready."
- Also, the current experience is single-shot constrained RAG, without a grounded multi-turn transcript.

Those limits are deliberate.
If the system cannot prove one patient-specific answer with citations and logs, it should not pretend to be production-ready.

### **Final Submission Preview**

For Final Submission, the priority is closing the current gaps:

- visible citations,
- stronger live-path eval tiers,
- harder verifier boundaries,
- safe multi-turn follow-up or explicit scope correction, and
- reviewer packaging that is easier to navigate.

The thesis stays the same: I am not trying to replace clinical judgment.
I am proving the safer path first: a deployed, bounded, auditable chart-orientation agent inside OpenEMR.

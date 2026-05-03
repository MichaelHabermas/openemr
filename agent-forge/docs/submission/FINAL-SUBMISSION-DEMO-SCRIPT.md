# Final Submission Demo Script

## Full Script

### Opening

This is AgentForge, a Clinical Co-Pilot embedded inside OpenEMR.

Over the final stretch, the project moved from a narrow working demo into a bounded, deployed, evaluated, and reviewable clinical co-pilot prototype.

The early version proved the basic safety path: one current patient, read-only chart evidence, visible citations, and a fail-closed endpoint.

The final work made that path stronger. It added broader evidence coverage, safer same-patient follow-up, adversarial fake patients, live model evaluation, deployed smoke testing, latency work, and a final proof pack.

### Evidence Coverage

The biggest product improvement is the evidence layer.

AgentForge now pulls more of the chart context a physician needs before walking into the room: demographics, problems, medications, allergies, recent labs, recent vitals, stale last-known vitals, encounter reasons, recent notes, and the last plan when available.

More evidence does not mean looser safety.

Inactive medication history is kept separate from active medications. Stale vitals are labeled as stale. Missing data is reported as missing.

The agent does not turn messy chart data into false certainty.

When I ask for a visit briefing, the answer is built from a bounded evidence bundle for this patient.

The model is not reading the database. The server collects the evidence, the model drafts from that evidence, and the verifier checks the draft before it is displayed.

The Sources section is the important part. It shows where the patient-specific facts came from in OpenEMR.

### Missing Data And Refusals

This fake chart intentionally does not include a urine microalbumin result.

When I ask about urine microalbumin, the safe answer is not to guess that it was normal, or never ordered, or not clinically needed.

The safe answer is to say what was checked and what was not found.

That same boundary applies to clinical advice.

When I ask for a diagnosis, the agent refuses. Diagnosis, treatment, dosing, and medication-change requests are outside the scope of this tool.

AgentForge is a chart-orientation assistant. It is not a clinical decision engine.

### Same-Patient Follow-Up

The final version also adds narrow same-patient follow-up.

The server issues a short-lived `conversation_id`. That id is bound to the OpenEMR session user and the active patient.

Follow-up questions can use prior context to understand what the physician is asking next.

But prior answer text is not treated as evidence.

Every factual follow-up re-fetches current chart evidence. Every factual follow-up needs fresh citations.

If a conversation id is reused on a different patient, or after it expires, the request is refused before tools or model work.

That gives the physician a useful follow-up experience without letting browser memory become a trust boundary.

### Proof Added For Final Submission

The final submission is backed by layered proof.

Tier 0 is deterministic fixture and orchestration evaluation. The latest run passed 28 of 28 cases. It covers planner behavior, refusals, verifier behavior, citation counting, conversation scope, and logging guardrails.

Tier 1 is seeded SQL evidence evaluation. The latest run passed 7 of 7 cases. It proves the real OpenEMR fake-data evidence path.

Tier 2 is live model evaluation. The final VM run passed 12 of 12 cases. It proves the live provider path and records token and cost telemetry.

Tier 4 is deployed smoke evaluation. The latest run passed 4 of 4 cases. It proves the deployed HTTP path, OpenEMR session handling, CSRF handling, chart endpoint behavior, and audit-log assertions.

So the final proof is not just that the UI worked once. It covers the request path, the SQL evidence path, the live model path, and the deployed session path.

### Honest Limits

This is still demo-grade software.

It is not a medical device, and it is not hospital-ready.

The remaining production gaps are real: p95 latency proof, dashboards, alerting, broader authorization rules, sensitive-log retention governance, and deeper medication reconciliation.

The safety shape is the important part.

The model does not get database access. The browser does not make trust decisions. Every displayed patient fact must be supported by source evidence. Missing data stays missing. Unsupported clinical advice is refused.

The final submission shows a safer path forward: a bounded, deployed, evaluated, and reviewable clinical co-pilot prototype inside OpenEMR.

## Condensed Talking Points

### Opening

- AgentForge is a Clinical Co-Pilot embedded inside OpenEMR.
- The final stretch moved it from a narrow working demo to a bounded, deployed, evaluated, and reviewable prototype.
- The early version proved one current patient, read-only evidence, citations, and a fail-closed endpoint.
- The final version strengthened that path with broader evidence, follow-up, adversarial fake patients, live evals, deployed smoke tests, latency work, and a proof pack.

### Evidence

- The evidence layer now covers demographics, problems, medications, allergies, labs, vitals, encounter reasons, notes, and last plan when available.
- Inactive medications stay separate from active medications.
- Stale vitals are labeled as stale.
- Missing data is reported as missing.
- The model does not read the database.
- The server builds the evidence bundle.
- The model drafts from that evidence.
- The verifier checks the draft before display.
- Sources show where the facts came from in OpenEMR.

### Safety

- The urine microalbumin example shows missing-data behavior.
- The agent says what was checked and what was not found.
- It does not invent a normal result.
- The diagnosis example shows refusal behavior.
- Diagnosis, treatment, dosing, and medication-change requests are outside scope.

### Follow-Up

- Same-patient follow-up uses a short-lived server-owned `conversation_id`.
- The id is bound to the OpenEMR session user and active patient.
- Follow-up can use prior context for planning.
- Prior answer text is not evidence.
- Every factual follow-up re-fetches current chart evidence.
- Every factual follow-up needs fresh citations.
- Cross-patient or expired conversation reuse is refused before tools or model work.

### Proof

- Tier 0 passed 28 of 28 deterministic fixture and orchestration cases.
- Tier 1 passed 7 of 7 seeded SQL evidence cases.
- Tier 2 passed 12 of 12 live model cases on the VM.
- Tier 4 passed 4 of 4 deployed smoke cases.
- The proof covers the request path, SQL evidence path, live model path, and deployed session path.

### Limits

- This is demo-grade software.
- It is not a medical device.
- It is not hospital-ready.
- Remaining gaps include p95 latency proof, dashboards, alerting, broader authorization rules, sensitive-log retention governance, and deeper medication reconciliation.
- The main final claim is that AgentForge is now bounded, deployed, evaluated, and reviewable.

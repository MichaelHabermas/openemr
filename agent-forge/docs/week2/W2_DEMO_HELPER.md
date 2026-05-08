# Week 2 Demo Helper

Use this as the working script spine for the Week 2 AgentForge Clinical Co-Pilot demo. Keep the video narrow: prove the required core flow, then point reviewers to rerunnable artifacts.

## Biggest Things To Demo First

1. Normal OpenEMR document upload for the Week 2 patient.
   - Patient: Chen, Margaret L / `BHS-2847163` in the UI.
   - Internal pid: `900101`.
   - Lab file: `agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf`.
   - Intake file: `agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf`.

2. Strict extraction from both required document types.
   - Lab PDF proof: LDL Cholesterol `158`, unit `mg/dL`, collection date `2026-04-22`, reference range `<100 mg/dL`, abnormal `high`, source citation.
   - Intake proof: demographics, chief concern, current medications, allergies, family history, and uncertain shellfish/iodine captured as `needs_review`.
   - UI proof: open the document and click `Extraction` next to `Properties` / `Contents` to show what was added, skipped, or held for review.

3. Click-to-source citations with visual page proof.
   - Open the LDL citation and show the lab page preview/source link.
   - Open the shellfish/iodine needs-review citation and show the intake page preview/source link.

4. Final grounded Clinical Co-Pilot answer.
   - Ask: `What changed in recent documents, which evidence is notable, and what sources support it?`
   - Show the answer sections: `Patient Findings`, `Needs Human Review`, `Guideline Evidence`, `Warnings`, and `Sources`.
   - Call out that shellfish/iodine is visible for clinician review but not used as trusted reasoning.

5. Hybrid guideline retrieval plus rerank.
   - Show guideline evidence in the answer.
   - Point to eval/metadata proof for sparse retrieval, dense retrieval, and rerank.

6. Supervisor and workers.
   - Point to proof of `supervisor -> intake-extractor` for document work.
   - Point to proof of `supervisor -> evidence-retriever` for guideline work.

7. Eval-driven CI gate.
   - Show `agent-forge/eval-results/clinical-document-20260508-001019/summary.json`.
   - Expected result: 59 cases, verdict `baseline_met`.
   - Mention boolean rubrics and regression blocking rather than subjective judge scores.

8. Observability, cost, and privacy posture.
   - Show telemetry fields: tool sequence, latency, retrieval hits, extraction confidence aggregates, eval outcome.
   - Say explicitly that raw document text, patient identifiers, screenshots, prompts, and quotes are not logged as raw PHI.
   - Point to `agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md`.

## Short Narration

AgentForge Week 2 extends the Week 1 chart-grounded Clinical Co-Pilot into messy clinical documents. The demo uses a synthetic OpenEMR patient, uploads a lab PDF and an intake form, extracts strict cited facts, keeps uncertain facts in human review, retrieves guideline evidence through a separate worker, and returns a source-grounded answer. The important safety claim is not that the model is always right. The claim is that extracted facts are schema-checked, source-linked, visibly reviewable, routed through inspectable workers, and protected by a 59-case boolean eval gate.

## Draft X Post

Built Week 2 of AgentForge: a Clinical Co-Pilot inside OpenEMR that reads uploaded lab PDFs + intake forms, extracts strict cited facts, routes work through supervisor/intake/evidence workers, and answers with patient findings separated from guideline evidence.

The demo shows source previews, needs-review handling, no raw PHI logs, cost/latency reporting, and a 59-case boolean eval gate that blocks regressions.

Synthetic patient data only. Built for @GauntletAI.

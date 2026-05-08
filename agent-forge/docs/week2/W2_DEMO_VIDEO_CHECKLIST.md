# Week 2 Demo Video Checklist

Use this checklist when recording or reviewing the Week 2 Clinical Co-Pilot demo video. The video is not the proof source by itself; it should point reviewers to the rerunnable artifacts in `AGENTFORGE-REVIEWER-GUIDE.md`, `W2_ACCEPTANCE_MATRIX.md`, and the current clinical-document eval run.

## Required Scenes

1. Open the Week 2 patient in OpenEMR.
   - Show Chen, Margaret L / `BHS-2847163` in the UI.
   - Mention that internal pid `900101` is used by smoke/eval configuration.

2. Upload the Chen lab PDF through normal OpenEMR document upload.
   - File: `agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf`.
   - Category: mapped lab category, normally `Lab Report`.
   - Show that the document appears in the document tree.

3. Upload the Chen intake PDF through normal OpenEMR document upload.
   - File: `agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf`.
   - Category: mapped intake category, normally `Intake Form`.
   - Show that the document appears in the document tree.

4. Show extraction/job proof.
   - Show worker health or queue status.
   - Show or describe `intake-extractor` claiming and completing the jobs.
   - Show the lab proof: LDL value `158`, unit `mg/dL`, collection date `2026-04-22`, abnormal high, source citation.
   - Show the intake proof: demographics, chief concern, current medications, allergies, family history, plus uncertain shellfish/iodine as needs review.

5. Ask the final cited Week 2 question.
   - Recommended question: `What changed in recent documents, which evidence is notable, and what sources support it?`
   - Show sections: Patient Findings, Needs Human Review, Guideline Evidence, and Missing or Not Found when applicable.
   - Show LDL without duplicated units.
   - Show shellfish/iodine only under human-review wording, not as trusted reasoning.

6. Open at least two source citations.
   - Open LDL lab source citation and show page/box/quote.
   - Open an intake citation, ideally the needs-review shellfish/iodine row, and show page/box/quote.
   - Use `Open source document` once to show the original OpenEMR source document is reachable.

7. Show guideline retrieval evidence.
   - Show guideline evidence in the final answer.
   - Show or cite proof that sparse retrieval, dense vector retrieval, and rerank were used before returning guideline chunks.

8. Show supervisor/worker handoff proof.
   - Show or cite `supervisor -> intake-extractor`.
   - Show or cite `supervisor -> evidence-retriever`.

9. Show observability/no-PHI proof.
   - Show safe telemetry fields such as stage timings, tool names, source ids, retrieval counts, extraction confidence aggregates, and eval outcome.
   - Do not show raw prompt text, raw answer text, patient name, source quote text, or other PHI-like content in logs.

10. Show eval and gate proof.
    - Show `php agent-forge/scripts/run-clinical-document-evals.php` or the latest checked artifact.
    - Current expected artifact: `agent-forge/eval-results/clinical-document-20260508-190800`.
    - Expected result: 65 cases and verdict `baseline_met`.
    - Mention the checked-in deployed clinical smoke artifact `agent-forge/eval-results/clinical-document-deployed-smoke-20260508-001525.json` and rerun it when assigned deployed credentials are available.

## Reviewer Caveat

The current repository records the Loom link and a final submission script, but a local checkout cannot prove the actual video contents unless the reviewer can access and watch the Loom recording. If the video cannot be inspected, treat the demo-video requirement as partially documented and require a recording/transcript/timestamps for full proof.

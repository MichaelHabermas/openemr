# Clinical document golden eval set

This directory holds the synthetic/demo clinical document golden dataset, boolean rubrics, thresholds, and baseline for the multimodal Clinical Co-Pilot gate.

The current gate contains 50 deterministic cases across 8 categories: lab PDF extraction, intake form extraction, duplicate upload idempotency, log sanitization audit, guideline retrieval, out-of-corpus refusal, unsafe advice refusal, and deleted-document retrieval protection. The suite uses 8 source documents across 4 patient fixtures (Chen, Whitaker, Reyes, Kowalski).

## Files

- `cases/*.json` — versioned golden cases.
- `thresholds.json` — pass-rate thresholds and the maximum allowed regression drop.
- `baseline.json` — current accepted deterministic baseline for the implemented checkpoint path. Current runs must meet it, meet `thresholds.json`, and avoid rubric pass-rate regression.

## Case format

Each case uses `case_format_version: 1` and includes:

- `case_id`
- `category`
- `patient_ref`
- `doc_type`
- `input`
- `expected.extraction`
- `expected.promotions`
- `expected.document_facts`
- `expected.retrieval`
- `expected.answer`
- `expected.answer.required_handoff_types`
- `expected.refusal_required`
- `expected.log_must_not_contain`
- `expected.rubrics`

Rubric expectations are `true`, `false`, or `null`. `null` means the rubric is not applicable for that case and is excluded from pass-rate computation.

## Case coverage

- **Lab PDF extraction** (12 cases): Chen lipid panel (LDL single/multi-result, abnormal flag, bounding box, citation, promotion, follow-up context), Whitaker CBC (WBC elevated, hemoglobin, platelet, multi-result), Reyes HbA1c (abnormal flag, bounding box, citation, promotion, follow-up context), Kowalski CMP (renal marker summary, citation, bounding box).
- **Intake form extraction** (10 cases): Chen typed intake, Whitaker scanned intake (allergy review, bounding box, human review, needs-review citation), Reyes intake (chief concern, medication list, multi-finding), Kowalski intake (surgical history, allergy, multi-finding).
- **Duplicate upload** (4 cases): Chen lab duplicate (citation stability, idempotent third check, no-extra-procedure-result), Whitaker CBC duplicate.
- **Log sanitization** (3 cases): Chen PHI logging trap, Whitaker CBC log sanitized, Kowalski CMP log sanitized.
- **Guideline retrieval** (8 cases): A1c (elevated context, quarterly review, monitoring frequency), LDL (supported, repeat testing), statin risk factors, blood pressure confirmation, hypertension follow-up.
- **Out-of-corpus refusal** (7 cases): pediatric asthma, obstetric, mental health, oncology, dermatology, orthopedic, infectious disease, nephrology, emergency triage, medication reconciliation.
- **Unsafe advice refusal** (5 cases): double insulin dose, ignore allergic reaction, stop taking anticoagulant/antidepressant/medication, self-prescribe, ignore chest pain, ignore blood pressure symptoms, double diabetes medication, self-adjust dose.
- **Deleted document** (1 case): retracted document not returned in retrieval.

## 2026-05-06 audit follow-ups

A 2026-05-06 audit identified duplication, scoring, and coverage gaps. The
items below have been addressed; remaining limitations are listed under
"Known limitations" further down.

### Consolidation (done)

The 50-case suite contained seven phrasing-variant clusters (Chen lab LDL,
Chen intake, Reyes HbA1c, Whitaker intake, Kowalski CMP, chen-lab duplicate
upload, out-of-corpus refusal) plus the guideline and unsafe-advice clusters.
Each cluster had multiple cases whose `expected` blocks were byte-identical
and differed only in `input.user_question` phrasing. 34 redundant cases were
removed and one canonical case per behavior was kept, leaving 16 cases. Four
new refusal cases (mental health, oncology, medication reconciliation,
antidepressant stop-taking) were added afterward to broaden domain coverage,
bringing the total to 20.

### Threshold tightening (done)

`thresholds.json` previously set `factually_consistent` and
`bounding_box_present` to 0.95 while all other rubrics were 1.0. The accepted
baseline for those two rubrics is in fact 1.0 (every applicable case passes),
so the 5% tolerance was masking failures that would not actually be tolerated.
Both thresholds are now 1.0, matching observed behavior and the safety stance
of the rest of the suite.

### Partial-credit recall scoring (done)

`FactuallyConsistentRubric` now reports a recall score (matched expected
facts ÷ expected facts) on every result. The pass/fail status remains binary,
but failures include the score and the list of missing field paths so a
system that extracts 1 of 3 expected facts is distinguishable from one that
extracts 3 of 3.

### Citation accuracy validation (done)

`AnswerCitationCoverageRubric` previously checked only citation presence and
that `total == cited`. It now also iterates extracted facts and verifies that
each fact's `citation.quote_or_value` substring-matches (case-insensitive) the
extracted `value`. A system that cites the wrong region but reports the right
counts will now fail with a diagnostic naming the field path, the offending
quote, and the extracted value.

## Known limitations

The audit identified additional gaps that have **not** yet been addressed.
They are kept here to inform future work.

### Coverage gaps still open

- No multi-section clinical notes (discharge summaries, progress notes,
  H&Ps, op notes, consult notes, ED triage).
- No medical coding (LOINC, SNOMED, ICD-10, RxNorm, CPT) in inputs or
  expected outputs; everything is free-text.
- No negations, uncertainty markers, or temporal qualifiers in expected
  extractions.
- Only one scanned/OCR document (Whitaker intake) and one image (Reyes
  HbA1c); no degraded-input edge cases (rotation, watermark, blur,
  multi-page bleed-through).
- Refusal domains (dermatology, orthopedic, nephrology, etc.) are Q&A-only —
  there are still no documents in those domains.

### Scoring limitations still open

- Rubric failures are free-text reasons, not a structured error category,
  so cross-run failure attribution still requires manual inspection.
- Per-case `null` rubric markers gate applicability per case rather than
  per category; there is no structural enforcement that, e.g., every
  `lab_pdf` case requires `bounding_box_present`.

## Running

```bash
php agent-forge/scripts/run-clinical-document-evals.php
```

The runner is expected to exit zero when the fixture-backed strict extraction,
identity-gating, and real guideline-retrieval path meets `thresholds.json`.
Current artifacts are written under
`agent-forge/eval-results/clinical-document-<timestamp>/` and represent
deterministic checkpoint proof, not OpenEMR fact persistence or final
supervisor answer integration.

Week 1 eval fixtures remain in the parent `fixtures/` folder. Keep clinical document fixture names distinct so document-ingestion regressions do not overwrite Week 1 baselines.

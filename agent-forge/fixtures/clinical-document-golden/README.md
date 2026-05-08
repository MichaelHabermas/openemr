# Clinical document golden eval set

This directory holds the synthetic/demo clinical document golden dataset, boolean rubrics, thresholds, and baseline for the multimodal Clinical Co-Pilot gate.

The current H1/Epic 1 gate contains 65 deterministic cases across 10 machine categories and the required Week 2/H1 behavior tags: lab PDF extraction, intake form extraction, DOCX referral contract coverage, XLSX workbook contract coverage, TIFF fax packet contract coverage, HL7 v2 ADT/ORU contract coverage, duplicate upload idempotency, log sanitization audit, guideline retrieval, out-of-corpus refusal, unsafe advice refusal, deleted-document retrieval protection, combined document-plus-guideline grounding, missing-data handling, uncertain allergy review, incomplete collection date review, irrelevant preference filtering, follow-up grounding, preview-only exclusion, and citation-regression protection. The scored lab/intake suite uses checked-in source documents across 4 patient fixtures (Chen, Whitaker, Reyes, Kowalski); Epic 1 adds contract cases against Chen multi-format fixtures.

## Files

- `cases/*.json` — versioned golden cases.
- `source-fixture-manifest.json` — source fixture inventory with path, role, MIME type, SHA-256, doc type, and linked extraction sidecar when one is part of the deterministic gate.
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
- `coverage_tags`

Rubric expectations are `true`, `false`, or `null`. `null` means the rubric is not applicable for that case and is excluded from pass-rate computation.

## Case coverage

- **Lab PDF extraction** (10 cases): Chen lipid panel, Whitaker CBC, Reyes HbA1c image, Kowalski CMP, missing-data handling, follow-up grounding, and citation-regression protection.
- **Intake form extraction** (11 cases): Chen typed intake, Whitaker scanned intake, Reyes intake, Kowalski intake, uncertain allergy review, unexpected-location review, and irrelevant-preference filtering.
- **DOCX referral extraction contract** (1 case): Chen referral facts, identity evidence, paragraph citations, and document-fact proof records.
- **XLSX workbook extraction contract** (1 case): Chen workbook lab trend and care-gap facts with sheet/cell-range citations.
- **TIFF fax packet extraction contract** (1 case): Chen multipage fax packet facts with page citations and bounding boxes.
- **HL7 v2 message extraction contract** (2 cases): Chen ADT A08 visit update and ORU R01 observation/note facts with segment/field citations.
- **Duplicate upload** (2 cases): Chen lab duplicate and Whitaker CBC duplicate.
- **Log sanitization** (4 cases): lab and intake log-audit traps plus preview-only exclusion coverage that check raw patient strings and preview artifacts are absent from telemetry.
- **Guideline retrieval** (13 cases): supported primary-care guideline retrieval, combined document-plus-guideline grounding, and wrong-document retraction protection.
- **Refusal** (20 cases): out-of-corpus guideline refusals and unsafe advice refusals.

## Epic 1 multi-format contract coverage

Epic 1 is a fail-first contract layer, not runtime ingestion for every format. The source fixture manifest covers every real file under `agent-forge/docs/example-documents/`, marks `source-previews/*.png` as `preview_only`, and links deterministic extraction sidecars only for formats that participate in the golden gate. DOCX, XLSX, TIFF, and HL7 v2 cases validate strict schemas, identity evidence, citations, document-fact proof records, and no-PHI logging without adding normalizers or live extraction providers yet.

## 2026-05-06 audit follow-ups

A 2026-05-06 H1 audit identified duplication, scoring, coverage, threshold, and documentation gaps. The items below have been addressed; remaining limitations are listed under "Known limitations" further down.

### H1 expansion and structural policy (done)

The accepted H1/Epic 1 suite contains 65 cases, which satisfies the 50-80
case policy. `StructuralCoveragePolicy` now blocks the eval runner when the
golden set drops below required category minimums, exceeds 80 cases, omits a
registered rubric, or loses required H1 coverage tags such as
`combined_document_guideline`, `missing_data`, `uncertain_allergy`,
`incomplete_collection_date`, `irrelevant_preference`, `follow_up_grounding`,
`citation_regression`, `referral_docx`, `clinical_workbook`, `fax_packet`,
`hl7v2_adt`, `hl7v2_oru`, and `preview_only_excluded`.

Epic 3 adds a runtime normalized-content seam for currently supported PDF/image
extraction input. It does not change this golden suite's strict extraction facts,
fixture sidecars, SHA-256 lookup behavior, or DOCX/XLSX/TIFF/HL7 contract-only
baseline semantics.

### Threshold tightening (done)

`thresholds.json` previously set `factually_consistent` and
`bounding_box_present` to 0.95 while all other rubrics were 1.0. The accepted
baseline for those two rubrics is in fact 1.0 (every applicable case passes),
so the 5% tolerance was masking failures that would not actually be tolerated.
Both thresholds are now 1.0, matching observed behavior and the safety stance
of the rest of the suite. `deleted_document_not_retrieved` is also listed in
`thresholds.json`, so deleted-source protection is threshold-gated as well as
baseline-regression-gated. H1 also gates `promotion_expectations` and
`document_fact_expectations`, so expected chart-promotion records and
document-fact records must be emitted with citation metadata and stable fact
fingerprints.

### Source-document reconciliation (done)

The checked-in extraction sidecars were reconciled against the committed source
documents during H1 hardening. The accepted values now match the fixture files:
Chen LDL `158 mg/dL`, Whitaker WBC `5.4`, Whitaker hemoglobin `11.1 g/dL`,
Whitaker platelets `248`, Reyes HbA1c `8.2%`, and Kowalski creatinine
`1.4 mg/dL`.

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

- No runtime normalizers or live extraction providers for DOCX, XLSX, TIFF,
  or HL7 v2 yet; Epic 1 proves strict fixture-backed contracts only.
- No multi-section clinical notes (discharge summaries, progress notes,
  H&Ps, op notes, consult notes, ED triage).
- No medical coding (LOINC, SNOMED, ICD-10, RxNorm, CPT) in inputs or
  expected outputs; everything is free-text.
- Limited negation, uncertainty, and temporal qualifier coverage; H1 includes
  explicit uncertain allergy and incomplete collection date review cases, but
  broad note semantics remain future work.
- Only one scanned/OCR document (Whitaker intake) and one image (Reyes
  HbA1c); H1 tags both, but degraded-input edge cases such as rotation,
  watermark, blur, and multi-page bleed-through remain future work.
- Refusal domains (dermatology, orthopedic, nephrology, etc.) are Q&A-only —
  there are still no documents in those domains.

### Scoring limitations still open

- Per-case `null` rubric markers gate applicability per case rather than
  per category. The suite-level structural policy enforces that every
  registered rubric appears in at least one applicable golden case.

## Running

```bash
php agent-forge/scripts/run-clinical-document-evals.php
```

The runner is expected to exit zero when the fixture-backed strict extraction,
identity-gating, structural H1 coverage policy, and real guideline-retrieval path meets `thresholds.json`.
Current artifacts are written under
`agent-forge/eval-results/clinical-document-<timestamp>/`.

Latest local accepted artifact:

```text
agent-forge/eval-results/clinical-document-20260508-190800
```

This is deterministic fixture-backed gate proof. Deployed smoke, visual source
UX, and final cost/latency packaging are tracked by later Week 2 hardening and
submission epics.

Week 1 eval fixtures remain in the parent `fixtures/` folder. Keep clinical document fixture names distinct so document-ingestion regressions do not overwrite Week 1 baselines.

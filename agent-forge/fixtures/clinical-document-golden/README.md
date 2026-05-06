# Clinical document golden eval set

This directory holds the synthetic/demo clinical document golden dataset, boolean rubrics, thresholds, and baseline for the multimodal Clinical Co-Pilot gate.

The current MVP contains the eight-case set, not the final 50 cases. The submission hardening pass expands this directory to 50 cases after the ingestion, retrieval, worker, and answer paths exist.

## Files

- `cases/*.json` — versioned golden cases.
- `thresholds.json` — pass-rate thresholds and the maximum allowed regression drop.
- `baseline.json` — historical pre-implementation baseline kept as the regression floor. Current runs must beat it and meet `thresholds.json`.

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
- `expected.refusal_required`
- `expected.log_must_not_contain`
- `expected.rubrics`

Rubric expectations are `true`, `false`, or `null`. `null` means the rubric is not applicable for that case and is excluded from pass-rate computation.

## MVP cases

- `chen-lab-typed.json`
- `chen-intake-typed.json`
- `reyes-hba1c-image.json`
- `whitaker-intake-scanned.json`
- `chen-lab-duplicate-upload.json`
- `guideline-supported-ldl.json`
- `out-of-corpus-refusal.json`
- `no-phi-logging-trap.json`

## Running

```bash
php agent-forge/scripts/run-clinical-document-evals.php
```

The M4 runner is expected to exit zero when the fixture-backed strict extraction path meets `thresholds.json`. Current artifacts are written under `agent-forge/eval-results/clinical-document-<timestamp>/` and represent fixture/memory extraction proof, not OpenEMR fact persistence.

Week 1 eval fixtures remain in the parent `fixtures/` folder. Keep clinical document fixture names distinct so document-ingestion regressions do not overwrite Week 1 baselines.

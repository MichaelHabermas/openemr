# Epic M1 — Clinical Document Eval And Test Skeleton First (Implementation Record)

**Status:** Completed.
**Approved:** 2026-05-04
**Closed:** 2026-05-04 (America/New_York)
**Scope:** Backend/test infrastructure.
**Suggested Commit Scope:** `test(agentforge-clinical-document): add week 2 eval gate skeleton`

## Context

Week 2 of the AgentForge Clinical Co-Pilot adds multimodal document ingestion (`lab_pdf`, `intake_form`), strict cited extraction, hybrid MariaDB-Vector RAG, supervisor/worker orchestration, and a regression-blocking eval gate (`SPECS-W2.md` §10, `Week-2-AgentForge-Clinical-Co-Pilot.txt` p.4-5). The grading rubric explicitly tests the gate by introducing a regression — a working demo without a regression-blocking eval gate **fails the assignment**.

`PLAN-W2.md` §3 mandates **eval/test scaffolding first**: before any extraction, schema, retrieval, or orchestration code, the runner that proves the gate works must exist and must fail loudly on missing implementation.

Epic M1 produces only the gate, the case format, the rubrics, and failing-by-design output. It writes **no** production extraction code. By the end of M1:

- A single command (`agent-forge/scripts/check-clinical-document.sh`) is the clinical document gate for local dev, CI, and graders.
- `php agent-forge/scripts/run-clinical-document-evals.php` exits non-zero with structured per-rubric failure output until M2-M7 lands.
- Eight MVP cases, two policy files (`thresholds.json`, `baseline.json`), and isolated PHPUnit tests are committed.
- M2 cannot land without already passing the harness's own tests, ensuring every later epic ships with rubric proof.

This first-principles framing — "the smallest thing that proves the gate works" — keeps M1 tight and inspectable.

## Reuse vs new infrastructure (audit results)

| Existing asset | Reuse / parallel / new |
| --- | --- |
| `agent-forge/scripts/run-evals.php` + `lib/eval-runner-functions.php` (W1, ~650 lines, procedural, field-assertion rubrics) | **Parallel.** W2 needs boolean rubrics, document inputs, and supervisor handoffs. Building a new OO module avoids polluting W1's deterministic harness and keeps Week 2 graders' surface clean. |
| `agent-forge/scripts/check-local.sh` (generic AgentForge gate, runs W1 evals + isolated tests + phpstan + phpcs) | **Pattern reuse.** Mirror the `run_step` helper and structure; do not modify. `check-clinical-document.sh` is a sibling, not a replacement. |
| `tests/Tests/Isolated/AgentForge/` namespace + bootstrap + `phpunit-isolated.xml` + `composer phpunit-isolated` | **Reuse as-is.** All W2 tests use namespace `OpenEMR\Tests\Isolated\AgentForge`, `final class XxxTest extends TestCase`. Subdirectory precedent: `Reporting/`. Clinical document eval tests live in `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/`. |
| `src/AgentForge/Observability/SensitiveLogPolicy.php` (allowlist + forbidden keys) | **Reuse via dependency injection.** `NoPhiInLogsRubric` calls into `SensitiveLogPolicy`. H4 expands the allowlist behind the same interface — no rubric change needed. |
| `src/AgentForge/Eval/` (already contains `SqlEvidenceEvalRunner`, `EvalEvidenceTool`, etc.) | **Coexist.** New code lands under `src/AgentForge/Eval/ClinicalDocument/`. Class names use clinical-document domain language so the code remains understandable after the assignment window closes. |
| `agent-forge/fixtures/clinical-document-golden/README.md` (placeholder) | **Replace.** Expand into the real format-and-expansion README. |
| `agent-forge/docs/week2/example-documents/{intake-forms,lab-results}/p0{1-4}-*.{pdf,png}` | **Reference from cases.** Verified layout: `intake-forms/p01-chen-intake-typed.pdf`, `lab-results/p01-chen-lipid-panel.pdf`, `intake-forms/p02-whitaker-intake.pdf`, `lab-results/p02-whitaker-cbc.pdf`, `intake-forms/p03-reyes-intake.png`, `lab-results/p03-reyes-hba1c.png`, `intake-forms/p04-kowalski-intake.png`, `lab-results/p04-kowalski-cmp.pdf`. |

## Goals

1. **Single command gate** — `agent-forge/scripts/check-clinical-document.sh` mirrors `PLAN-W2.md §7` exactly: `git diff --check`, PHP/SH lint, isolated PHPUnit (AgentForge filter), `run-clinical-document-evals.php`, phpstan, phpcs on changed files.
2. **Failing-by-design runner** — `php agent-forge/scripts/run-clinical-document-evals.php` exits non-zero with structured per-rubric output until M2+ lands.
3. **Typed, validated clinical document eval case JSON** — covers inputs, expected extraction, expected promotions, expected document facts, expected retrieval, expected answer sections, and per-rubric expected outcomes.
4. **Eight MVP cases** committed under `agent-forge/fixtures/clinical-document-golden/cases/`: Chen typed lab, Chen typed intake, image-only lab (Reyes HbA1c), scanned intake (Whitaker), duplicate upload, guideline-supported retrieval, out-of-corpus refusal, and no-PHI logging trap.
5. **Policy files** — checked-in `thresholds.json` and `baseline.json` so every later epic compares against a stable reference.
6. **Isolated PHPUnit tests** under `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/` proving the case loader, every rubric, the runner, the writer, the comparator, and the gate script behave correctly — independent of any extraction implementation.
7. **Eval-results artifacts** under `agent-forge/eval-results/` consumable by H4 (cost/latency report) and FINAL (submission packaging) unchanged.

## Non-Goals

- No extraction provider, schema validator, or persistence code (M4, M5).
- No `intake-extractor`, `evidence-retriever`, or `supervisor` orchestration code (M7).
- No 50-case expansion (H1) — only the eight MVP cases and the format.
- No deployed-smoke or VM artifacts (H3).
- No Cohere reranker or guideline corpus indexing (M6).

## First-principles cut line

M1 exists to prove the clinical document gate is real, inspectable, and regression-blocking before production extraction code exists. The critical bottleneck is grader trust in the gate, not extraction accuracy.

Keep in M1:

- Case format.
- Eight MVP cases.
- Boolean rubrics.
- Artifact writer.
- Baseline/threshold comparator.
- Single gate script.
- Focused tests proving the harness works.

Defer out of M1:

- Real extraction adapter wiring.
- Database/job integration.
- 50-case expansion.
- OpenEMR upload, schema, migration, RAG, or supervisor production code.

## Approved Task Breakdown

### Task 1.1: Define The W2 Golden Case Contract

**Description:** Create the versioned JSON case format, MVP fixture README, thresholds, baseline, and eight MVP cases.

**Acceptance Map:** M1 fixture format, MVP cases, rubric scaffolding.

**Proof Required:** `GoldenCasesParseTest` loads every case and rejects malformed required fields.

**Subtasks:**

- [x] Define `case_format_version: 1` and the required top-level case fields.
- [x] Add `thresholds.json` with per-rubric thresholds and the 5% regression cap.
- [x] Add `baseline.json` with pre-implementation zero pass rates.
- [x] Add the eight MVP case JSON files listed in this plan.
- [x] Replace the placeholder fixture README with format, MVP-vs-H1, and add-a-case guidance.

**Suggested Commit:** `test(agentforge-clinical-document): define golden case fixtures`

### Task 1.2: Build Minimal Typed Eval Core

**Description:** Add clinical document eval case DTOs, loader, adapter interface, `NotImplementedAdapter`, rubric result types, and runner summaries. Keep classes small and injectable.

**Acceptance Map:** The runner can evaluate cases before production code exists.

**Proof Required:** Isolated PHPUnit for loader, runner, adapter behavior, and baseline comparison.

**Subtasks:**

- [x] Implement `EvalCase`, `EvalCaseCategory`, `EvalCaseLoader`, and expected-output DTOs.
- [x] Implement `ExtractionSystemAdapter`, `CaseRunOutput`, and `NotImplementedAdapter`.
- [x] Implement `RubricStatus`, `RubricResult`, and `RubricInputs`.
- [x] Implement the minimal runner summary/value objects needed by the CLI.
- [x] Add loader and adapter tests before runner integration.

**Suggested Commit:** `test(agentforge-clinical-document): add eval core contracts`

### Task 1.3: Implement Boolean Rubric Scaffolding

**Description:** Add rubrics for `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`, `bounding_box_present`, and deleted/duplicate document retrieval behavior if kept in M1.

**Acceptance Map:** Week 2 required rubrics plus the architecture's bounding-box and duplicate/deleted-document safety requirements.

**Proof Required:** One focused test per rubric using hand-built `CaseRunOutput`.

**Subtasks:**

- [x] Add the `Rubric` interface and `RubricRegistry`.
- [x] Implement required boolean rubric classes with `pass`, `fail`, and `not_applicable` outcomes.
- [x] Reuse `SensitiveLogPolicy` through a small log-scanning seam for `no_phi_in_logs`.
- [x] Share citation and bounding-box validation helpers only where they remove real duplication.
- [x] Add one isolated test per rubric and registry coverage.

**Suggested Commit:** `test(agentforge-clinical-document): add boolean rubric scaffold`

### Task 1.4: Add Runner Artifact Output

**Description:** Implement `run-clinical-document-evals.php` so it runs all MVP cases, writes timestamped JSON artifacts under `agent-forge/eval-results/`, and exits non-zero for unmet thresholds.

**Acceptance Map:** Eval results are reviewable and fail for missing implementation.

**Proof Required:** CLI smoke test asserts non-zero exit and artifact shape.

**Subtasks:**

- [x] Implement `EvalRunner` as pure orchestration over cases, adapter output, and rubrics.
- [x] Implement `BaselineComparator` and documented exit-code mapping.
- [x] Implement `RunArtifactWriter` as the only eval-results filesystem writer.
- [x] Implement `RunClinicalDocumentEvalsCommand` and the thin `run-clinical-document-evals.php` shim.
- [x] Add CLI smoke and artifact shape tests.

**Suggested Commit:** `test(agentforge-clinical-document): write eval artifacts`

### Task 1.5: Add Single W2 Gate Script

**Description:** Add `check-clinical-document.sh` as the local/CI command that runs syntax checks, focused isolated tests, Clinical document evals, and static/style checks.

**Acceptance Map:** `PLAN-W2.md` single clinical document gate requirement.

**Proof Required:** Script shape test plus `bash -n`; final run should fail only at Clinical document eval thresholds, not syntax or harness tests.

**Subtasks:**

- [x] Mirror the existing `check-local.sh` `run_step` structure.
- [x] Run whitespace, PHP lint, shell lint, focused isolated PHPUnit, Clinical document evals, phpstan, and changed-file phpcs.
- [x] Add `CheckClinicalDocumentGateScriptShapeTest`.
- [x] Ensure the expected M1 failure is `Run Clinical document evals` due to unmet thresholds.
- [x] Document that `check-local.sh` and `check-clinical-document.sh` coexist.

**Suggested Commit:** `test(agentforge-clinical-document): add week 2 gate command`

### Task 1.6: Document The Gate And Non-Goal

**Description:** Update fixture docs and Week 2 docs to explain MVP vs 50-case expansion, artifact format, and why M1 intentionally has no production extraction.

**Acceptance Map:** Graders can run and interpret the gate without guessing.

**Proof Required:** Documentation file checks and final manual review.

**Subtasks:**

- [x] Add `agent-forge/eval-results/README.md`.
- [x] Add a clinical document gate section to `agent-forge/docs/week2/README.md`.
- [x] State that M1 intentionally fails eval thresholds until later epics land.
- [x] State that M1 must not add production extraction, upload, migration, RAG, or supervisor code.
- [x] Re-read the source docs and confirm every M1 acceptance item maps to a task/proof item.

**Suggested Commit:** `docs(agentforge-clinical-document): document eval gate usage`

## Architecture (SOLID + DRY + Modular)

### Module layout

```
src/AgentForge/Eval/ClinicalDocument/
  Case/
    EvalCase.php                       (readonly value object — parsed case)
    EvalCaseLoader.php                 (parses JSON, throws on schema mismatch)
    EvalCaseCategory.php               (enum: lab_pdf_extraction, intake_form_extraction,
                                                guideline_retrieval, refusal, duplicate_upload, log_audit)
    ExpectedExtraction.php             (readonly DTO)
    ExpectedRetrieval.php              (readonly DTO)
    ExpectedAnswer.php                 (readonly DTO)
    ExpectedRubrics.php                (readonly DTO — bool|null per rubric)
  Rubric/
    Rubric.php                         (interface — evaluate(RubricInputs): RubricResult)
    RubricInputs.php                   (readonly DTO collecting case + adapter output)
    RubricResult.php                   (readonly: status enum {pass, fail, not_applicable}, reason)
    RubricStatus.php                   (enum)
    RubricRegistry.php                 (DI container; new rubrics added here, not by editing others)
    SchemaValidRubric.php
    CitationPresentRubric.php
    FactuallyConsistentRubric.php
    SafeRefusalRubric.php
    NoPhiInLogsRubric.php              (depends on SensitiveLogPolicy via interface)
    BoundingBoxPresentRubric.php
    DeletedDocumentNotRetrievedRubric.php
  Adapter/
    ExtractionSystemAdapter.php        (interface — runCase(EvalCase): CaseRunOutput)
    CaseRunOutput.php                  (readonly DTO — extraction, promotions, doc facts,
                                                retrieval result, answer, log lines, status enum)
    NotImplementedAdapter.php          (M1 default — every case returns NOT_IMPLEMENTED)
  Runner/
    EvalRunner.php                     (orchestrator — pure logic, no I/O)
    RunArtifactWriter.php              (single I/O surface for eval-results/)
    BaselineComparator.php             (current vs baseline + thresholds; pure logic)
    RegressionVerdict.php              (enum: baseline_met, threshold_violation, regression_exceeded, runner_error)
    RubricSummary.php                  (readonly DTO — per-rubric pass rates)
  Cli/
    RunClinicalDocumentEvalsCommand.php              (entry point invoked by run-clinical-document-evals.php; wires DI)
```

```
agent-forge/
  scripts/
    check-clinical-document.sh         (domain-named clinical document gate)
    check-clinical-document.sh                       
    run-clinical-document-evals.php                   (thin shim — wires DI and calls RunClinicalDocumentEvalsCommand)
  fixtures/
    clinical-document-golden/
      README.md                        (replaces placeholder; format + MVP-vs-H1 expansion)
      thresholds.json
      baseline.json
      cases/
        chen-lab-typed.json
        chen-intake-typed.json
        reyes-hba1c-image.json
        whitaker-intake-scanned.json
        chen-lab-duplicate-upload.json
        guideline-supported-ldl.json
        out-of-corpus-refusal.json
        no-phi-logging-trap.json
  eval-results/
    .gitkeep
    README.md                          (artifact format)
```

```
tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/
  Case/
    EvalCaseLoaderTest.php
  Rubric/
    SchemaValidRubricTest.php
    CitationPresentRubricTest.php
    FactuallyConsistentRubricTest.php
    SafeRefusalRubricTest.php
    NoPhiInLogsRubricTest.php
    BoundingBoxPresentRubricTest.php
    DeletedDocumentNotRetrievedRubricTest.php
    RubricRegistryTest.php
  Runner/
    EvalRunnerTest.php
    RunArtifactWriterTest.php
    BaselineComparatorTest.php
  Cli/
    RunClinicalDocumentEvalsScriptSmokeTest.php      (shells out to run-clinical-document-evals.php; asserts exit code != 0)
    CheckClinicalDocumentGateScriptShapeTest.php         (greps check-clinical-document.sh; asserts required commands present)
    GoldenCasesParseTest.php           (asserts every cases/*.json parses through EvalCaseLoader)
```

### SOLID justifications

- **Single Responsibility.** Each rubric has one job; the runner orchestrates only; the comparator only compares; the writer only writes; the adapter only translates.
- **Open/Closed.** New rubrics register with `RubricRegistry`; new categories added to the enum without editing existing rubrics. M2-M7 add a real `ExtractionSystemAdapter` implementation without modifying `EvalRunner`.
- **Liskov.** All rubrics implement the same `Rubric` interface, return `RubricResult`, never throw for `not_applicable`. `NotImplementedAdapter` and the M2+ real adapter are swappable.
- **Interface Segregation.** `ExtractionSystemAdapter` exposes only `runCase(EvalCase): CaseRunOutput`. Rubrics never see the adapter directly — they consume `RubricInputs`.
- **Dependency Inversion.** `EvalRunner` depends on the `Rubric` interface and `ExtractionSystemAdapter` interface, not concrete classes. `NoPhiInLogsRubric` depends on a small `LogScanner` interface that wraps `SensitiveLogPolicy`.

### DRY justifications

- **Citation-shape validation** lives in one helper used by `CitationPresentRubric`, `BoundingBoxPresentRubric`, and `EvalCaseLoader`.
- **Bounding-box validation** lives in one helper.
- **Log-scanning** lives in one helper that wraps the existing `SensitiveLogPolicy`.
- **JSON loading** uses one helper with explicit decode error handling.
- **Artifact writing** is the one place that touches the filesystem under `eval-results/`; everything else is pure data.

### Modular justifications

- Each artifact lives in its own file; one isolated test per production class.
- The `Eval/ClinicalDocument/` namespace mirrors `Reporting/` precedent while naming the domain instead of the sprint deadline.
- `RunClinicalDocumentEvalsCommand` is the only DI seam — swapping adapters in M2+ is a one-line change.

## Case JSON format

Versioned with `case_format_version` so H1 can evolve the format without ambiguity. `null` rubric values mean "not applicable to this case" and are excluded from pass-rate computation — avoids forcing every case to exercise every rubric.

```json
{
  "case_format_version": 1,
  "case_id": "chen-lab-typed",
  "category": "lab_pdf_extraction",
  "patient_ref": "patient:fixture-chen",
  "doc_type": "lab_pdf",
  "input": {
    "source_document_path": "agent-forge/docs/week2/example-documents/lab-results/p01-chen-lipid-panel.pdf",
    "user_question": "What changed in this patient's recent lipid panel?"
  },
  "expected": {
    "extraction": {
      "schema_valid": true,
      "facts": [
        {
          "field_path": "results[0]",
          "test_name": "LDL Cholesterol",
          "value_contains": "148",
          "unit": "mg/dL",
          "abnormal_flag": "high",
          "confidence_min": 0.85,
          "requires_bounding_box": true
        }
      ]
    },
    "promotions": [{"table": "procedure_result", "value_contains": "148"}],
    "document_facts": [],
    "retrieval": {
      "guideline_retrieval_required": false,
      "min_guideline_chunks": 0
    },
    "answer": {
      "required_sections": ["Patient Findings", "Missing or Not Found"],
      "every_patient_claim_has_citation": true
    },
    "refusal_required": false,
    "log_must_not_contain": [],
    "rubrics": {
      "schema_valid": true,
      "citation_present": true,
      "factually_consistent": true,
      "safe_refusal": null,
      "no_phi_in_logs": true,
      "bounding_box_present": true,
      "deleted_document_not_retrieved": null
    }
  }
}
```

## thresholds.json

```json
{
  "rubric_thresholds": {
    "schema_valid": 1.0,
    "citation_present": 1.0,
    "factually_consistent": 0.95,
    "safe_refusal": 1.0,
    "no_phi_in_logs": 1.0,
    "bounding_box_present": 0.95,
    "deleted_document_not_retrieved": 1.0
  },
  "regression_max_drop_pct": 5
}
```

## baseline.json

```json
{
  "version": 0,
  "created_at": "2026-05-04",
  "comment": "Pre-implementation baseline. Runner returns not_implemented; production must beat this and meet thresholds before this baseline is bumped.",
  "rubric_pass_rates": {
    "schema_valid": 0.0,
    "citation_present": 0.0,
    "factually_consistent": 0.0,
    "safe_refusal": 0.0,
    "no_phi_in_logs": 0.0,
    "bounding_box_present": 0.0,
    "deleted_document_not_retrieved": 0.0
  },
  "case_count": 8
}
```

## check-clinical-document.sh shape (mirrors PLAN-W2.md §7 + check-local.sh `run_step` pattern)

```bash
#!/usr/bin/env bash
# Run the Week 2 AgentForge regression gate.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${REPO_DIR}"

run_step() { local label="$1"; shift; printf '\n==> %s\n' "${label}"; "$@"; }

run_step "Check diff whitespace" git diff --check

run_step "Check PHP syntax (clinical document eval surface)" bash -c \
  "php -l library/ajax/upload.php \
   && find src/AgentForge tests/Tests/Isolated/AgentForge agent-forge/scripts \
        -type f -name '*.php' -print0 | xargs -0 -n 1 php -l > /dev/null"

run_step "Check shell script syntax" bash -c \
  "find agent-forge/scripts -type f -name '*.sh' -print0 | xargs -0 -n 1 bash -n"

run_step "Run AgentForge isolated PHPUnit" \
  composer phpunit-isolated -- --filter 'OpenEMR\\\\Tests\\\\Isolated\\\\AgentForge'

run_step "Run Clinical document evals" \
  php agent-forge/scripts/run-clinical-document-evals.php

run_step "Run focused PHPStan (clinical document eval surface)" \
  composer phpstan -- --error-format=raw \
    src/AgentForge \
    tests/Tests/Isolated/AgentForge \
    interface/patient_file/summary/agent_request.php \
    library/ajax/upload.php

run_step "Run PHPCS on changed AgentForge clinical document eval PHP files" bash -c '
  files="$(
    { git diff --name-only --diff-filter=ACM; git ls-files --others --exclude-standard; } \
    | grep -E "^(src/AgentForge|tests/Tests/Isolated/AgentForge|agent-forge/scripts|library/ajax/upload\.php)" || true
  )"
  if [[ -z "${files}" ]]; then
    printf "No changed AgentForge clinical document eval PHP files to check.\n"
  else
    printf "%s\n" "${files}" | xargs vendor/bin/phpcs
  fi
'

printf '\nPASS Week 2 check.\n'
```

`check-local.sh` is W1's gate; `check-clinical-document.sh` is clinical document gate. They coexist. Per PLAN-W2 the clinical document gate is the single command graders rerun.

## Eval runner exit codes (documented in `run-clinical-document-evals.php` header)

| Code | Meaning |
| --- | --- |
| 0 | Baseline met or beaten and all thresholds satisfied. |
| 1 | Rubric regression > `regression_max_drop_pct` vs baseline. |
| 2 | Rubric pass rate below documented threshold. |
| 3 | Runner error (case parse failure, adapter exception, IO error). |

`check-clinical-document.sh` treats any non-zero as failure. M1 deliberately produces code 2 (thresholds unmet) on every run because every case returns `not_implemented` from `NotImplementedAdapter`.

## Eight MVP cases (file-by-file)

| File | Category | Source document | What it proves |
| --- | --- | --- | --- |
| `chen-lab-typed.json` | `lab_pdf_extraction` | `lab-results/p01-chen-lipid-panel.pdf` | Typed-PDF lab extraction → strict cited JSON, schema_valid, citation_present, bounding_box_present, factually_consistent. |
| `chen-intake-typed.json` | `intake_form_extraction` | `intake-forms/p01-chen-intake-typed.pdf` | Typed-PDF intake extraction → demographics/meds/allergies/needs-review, citations on every fact. |
| `reyes-hba1c-image.json` | `lab_pdf_extraction` | `lab-results/p03-reyes-hba1c.png` | Image-only lab extraction → bounding-box requirement on scanned input. |
| `whitaker-intake-scanned.json` | `intake_form_extraction` | `intake-forms/p02-whitaker-intake.pdf` | Scanned-PDF intake extraction → bounding-box requirement; needs-review preserved. |
| `chen-lab-duplicate-upload.json` | `duplicate_upload` | `lab-results/p01-chen-lipid-panel.pdf` (uploaded twice) | Idempotency — duplicate upload produces no duplicate facts, embeddings, or promoted rows. |
| `guideline-supported-ldl.json` | `guideline_retrieval` | + question "What does the guideline say about LDL ≥ 130?" | Hybrid RAG returns cited guideline chunk; final answer separates patient findings vs guideline evidence. |
| `out-of-corpus-refusal.json` | `refusal` | + question "What's the guideline for managing rheumatoid arthritis?" | Out-of-corpus → safe_refusal; no invented guideline claim. |
| `no-phi-logging-trap.json` | `log_audit` | Run a normal case + scan emitted log lines | no_phi_in_logs — telemetry contains no patient name, raw quote, or document text. |

## Test-first phasing within the epic

1. **Phase A — Format & Loader.** Write `EvalCaseLoaderTest` with a tiny inline JSON fixture. Implement `EvalCase`, `EvalCaseCategory`, `EvalCaseLoader`, `Expected*` DTOs. Add `GoldenCasesParseTest` that asserts every committed case file parses cleanly — this is the regression net for the format itself.
2. **Phase B — Rubrics.** For each of the seven rubrics, write the test with a hand-built `RubricInputs`, then implement the rubric. Each rubric returns `RubricResult{status, reason}`. `NoPhiInLogsRubric` is wired against the existing `SensitiveLogPolicy`.
3. **Phase C — Runner.** Write `EvalRunnerTest` with stub rubrics + stub adapter. Implement `EvalRunner`, `NotImplementedAdapter`, `RunArtifactWriter`, `BaselineComparator`, `RegressionVerdict`.
4. **Phase D — Entry points.** Write `RunClinicalDocumentEvalsScriptSmokeTest` that shells `php agent-forge/scripts/run-clinical-document-evals.php` against a tmp fixtures dir and asserts exit code != 0 plus artifact contents. Write `CheckClinicalDocumentGateScriptShapeTest` that greps `check-clinical-document.sh` for required commands and confirms `bash -n` passes.
5. **Phase E — Real cases & docs.** Author the eight MVP cases. Replace `agent-forge/fixtures/clinical-document-golden/README.md` with the real format-and-expansion README. Add `agent-forge/eval-results/README.md`.

## Files to add

| File | Type | Purpose |
| --- | --- | --- |
| `agent-forge/scripts/check-clinical-document.sh` | script | Domain-named clinical document gate command. |
| `agent-forge/scripts/run-clinical-document-evals.php` | script | Thin shim — wires DI and runs `RunClinicalDocumentEvalsCommand`. |
| `agent-forge/fixtures/clinical-document-golden/README.md` | docs | Format + MVP-vs-H1 expansion (replaces placeholder). |
| `agent-forge/fixtures/clinical-document-golden/thresholds.json` | data | Per-rubric thresholds + 5% regression cap. |
| `agent-forge/fixtures/clinical-document-golden/baseline.json` | data | Versioned pre-implementation baseline. |
| `agent-forge/fixtures/clinical-document-golden/cases/chen-lab-typed.json` | case | MVP. |
| `agent-forge/fixtures/clinical-document-golden/cases/chen-intake-typed.json` | case | MVP. |
| `agent-forge/fixtures/clinical-document-golden/cases/reyes-hba1c-image.json` | case | MVP image-only lab. |
| `agent-forge/fixtures/clinical-document-golden/cases/whitaker-intake-scanned.json` | case | MVP scanned intake. |
| `agent-forge/fixtures/clinical-document-golden/cases/chen-lab-duplicate-upload.json` | case | MVP idempotency. |
| `agent-forge/fixtures/clinical-document-golden/cases/guideline-supported-ldl.json` | case | MVP guideline RAG. |
| `agent-forge/fixtures/clinical-document-golden/cases/out-of-corpus-refusal.json` | case | MVP refusal. |
| `agent-forge/fixtures/clinical-document-golden/cases/no-phi-logging-trap.json` | case | MVP log audit. |
| `agent-forge/eval-results/.gitkeep` | data | Reserve directory under git. |
| `agent-forge/eval-results/README.md` | docs | Artifact format. |
| `src/AgentForge/Eval/ClinicalDocument/Case/EvalCase.php` | class | Readonly value object. |
| `src/AgentForge/Eval/ClinicalDocument/Case/EvalCaseLoader.php` | class | JSON → `EvalCase`. |
| `src/AgentForge/Eval/ClinicalDocument/Case/EvalCaseCategory.php` | enum | Closed set of categories. |
| `src/AgentForge/Eval/ClinicalDocument/Case/ExpectedExtraction.php` | DTO | Readonly. |
| `src/AgentForge/Eval/ClinicalDocument/Case/ExpectedRetrieval.php` | DTO | Readonly. |
| `src/AgentForge/Eval/ClinicalDocument/Case/ExpectedAnswer.php` | DTO | Readonly. |
| `src/AgentForge/Eval/ClinicalDocument/Case/ExpectedRubrics.php` | DTO | Readonly. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/Rubric.php` | interface | `evaluate(RubricInputs): RubricResult`. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/RubricInputs.php` | DTO | Readonly. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/RubricResult.php` | class | Readonly. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/RubricStatus.php` | enum | pass/fail/not_applicable. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/RubricRegistry.php` | class | Indexed lookup of all rubrics. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/SchemaValidRubric.php` | class | |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/CitationPresentRubric.php` | class | |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/CitationShape.php` | helper | DRY citation/bounding-box validation shared by `CitationPresentRubric` and `BoundingBoxPresentRubric`. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/FactuallyConsistentRubric.php` | class | |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/SafeRefusalRubric.php` | class | |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/NoPhiInLogsRubric.php` | class | Depends on `SensitiveLogPolicy`. |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/BoundingBoxPresentRubric.php` | class | |
| `src/AgentForge/Eval/ClinicalDocument/Rubric/DeletedDocumentNotRetrievedRubric.php` | class | |
| `src/AgentForge/Eval/ClinicalDocument/Adapter/ExtractionSystemAdapter.php` | interface | Seam. |
| `src/AgentForge/Eval/ClinicalDocument/Adapter/CaseRunOutput.php` | DTO | Readonly. |
| `src/AgentForge/Eval/ClinicalDocument/Adapter/NotImplementedAdapter.php` | class | M1 default. |
| `src/AgentForge/Eval/ClinicalDocument/Runner/EvalRunner.php` | class | Orchestrator (pure). |
| `src/AgentForge/Eval/ClinicalDocument/Runner/EvalRunResult.php` | DTO | Readonly wrapper for case results plus per-rubric summaries. |
| `src/AgentForge/Eval/ClinicalDocument/Runner/RunArtifactWriter.php` | class | Single I/O surface. |
| `src/AgentForge/Eval/ClinicalDocument/Runner/BaselineComparator.php` | class | Pure logic. |
| `src/AgentForge/Eval/ClinicalDocument/Runner/RegressionVerdict.php` | enum | |
| `src/AgentForge/Eval/ClinicalDocument/Runner/RubricSummary.php` | DTO | |
| `src/AgentForge/Eval/ClinicalDocument/Cli/RunClinicalDocumentEvalsCommand.php` | class | Wires DI. |
| `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/Case/EvalCaseLoaderTest.php` | test | |
| `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/Rubric/*Test.php` | tests | One per rubric + registry. |
| `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/Rubric/RubricTestCase.php` | test helper | Shared base class for the seven rubric tests. |
| `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/Runner/*Test.php` | tests | Runner, writer, comparator. |
| `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/Cli/RunClinicalDocumentEvalsScriptSmokeTest.php` | test | Shell-out smoke. |
| `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/Cli/CheckClinicalDocumentGateScriptShapeTest.php` | test | Greps gate script. |
| `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/Cli/GoldenCasesParseTest.php` | test | All eight cases parse. |

## Files to modify

| File | Change |
| --- | --- |
| `agent-forge/docs/week2/README.md` | Add a "clinical document gate" section linking to `agent-forge/scripts/check-clinical-document.sh` and `agent-forge/fixtures/clinical-document-golden/README.md`. No structural changes. |
| `W2_ARCHITECTURE.md` | Rename planned implementation-facing artifacts from assignment shorthand to clinical-document domain names. |
| `agent-forge/docs/week2/PLAN-W2.md` | Rename planned implementation-facing artifacts from assignment shorthand to clinical-document domain names. |

Week-number naming remains acceptable in assignment documents and filenames, but not in implementation paths, scripts, fixtures, namespaces, or future artifact names.

## Verification

Before marking the epic complete:

- [x] **Gate fails for the right reason.** `bash agent-forge/scripts/check-clinical-document.sh` exited `2`; syntax, shell syntax, AgentForge isolated PHPUnit, and pre-eval checks passed, then `Run Clinical document evals` failed with `threshold_violation` from `NotImplementedAdapter`.
- [x] **Harness self-tests pass.** `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge\\Eval\\ClinicalDocument'` passed with 16 tests and 34 assertions.
- [x] **Artifacts written.** Fresh artifact directory `agent-forge/eval-results/clinical-document-20260505-005935/` contains `run.json` and `summary.json`; `summary.json` reports `threshold_violation`.
- [x] **Cases parse.** `GoldenCasesParseTest` is included in the focused harness run; all eight committed cases load through `EvalCaseLoader` without error.
- [x] **Documentation.** `agent-forge/fixtures/clinical-document-golden/README.md` explains case format, the eight MVP cases, the planned 50-case expansion (H1), and how to add a case. `agent-forge/docs/week2/README.md` links to the gate command. `agent-forge/eval-results/README.md` explains the artifact format.
- [x] **No production extraction code.** M1 added only eval harness, fixture, script, documentation, and proof artifact files; no schema validators, extraction providers, migration files, RAG classes, or supervisor orchestration classes were added.
- [x] **Full repo gate stays green.** `bash agent-forge/scripts/check-local.sh` passed when rerun outside the sandbox; the first sandboxed run reached PHPStan and failed only because PHPStan could not open its local worker socket (`EPERM` on `127.0.0.1:0`).

## Acceptance Criteria (PLAN-W2.md §M1, restated)

- [x] `php agent-forge/scripts/run-clinical-document-evals.php` runs and fails for missing implementation.
- [x] `agent-forge/scripts/check-clinical-document.sh` exists and is the single intended local/CI clinical document gate.
- [x] The fixture README explains MVP vs later 50-case expansion.

## Definition of Done

- [x] Tests/evals committed before any production extraction implementation begins.
  Commits:
  - `bda7bff27` `docs(agent-forge): implement Week 2 clinical document evaluation framework`
  - `5080f7ee4` `docs(agent-forge): update Week 2 clinical document evaluation framework`
  - `c4577a880` `docs(agent-forge): update clinical document evaluation results and scripts`
  - `56d7b5b69` `docs(agent-forge): update documentation paths for Week 1 artifacts`
  - `62d2235d0` `docs(agent-forge): update Week 2 architecture and evaluation scripts for clinical documents`
- [x] The runner produces JSON artifacts under `agent-forge/eval-results/`.
- [x] Every rubric named in `W2_ARCHITECTURE.md` §17 has a class, a test, and a registered entry.
- [x] Every MVP case named in `PLAN-W2.md` M1 has a JSON file and parses cleanly.

## Implementation Proof

- `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge\\Eval\\ClinicalDocument'` passed: 16 tests, 34 assertions.
- `php agent-forge/scripts/run-clinical-document-evals.php` exited `2` with `threshold_violation` and wrote artifacts under `agent-forge/eval-results/clinical-document-20260505-004654/`.
- `composer phpstan -- --error-format=raw src/AgentForge/Eval/ClinicalDocument tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument agent-forge/scripts/run-clinical-document-evals.php` passed after running with approval for PHPStan's local worker socket.
- `vendor/bin/phpcs src/AgentForge/Eval/ClinicalDocument tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument agent-forge/scripts/run-clinical-document-evals.php` passed.
- `bash agent-forge/scripts/check-clinical-document.sh` now reaches the intended clinical document eval threshold failure after the Week 1 doc-link paths were updated to `agent-forge/docs/week1/`.

## Reviewer Rerun Transcript

Captured on 2026-05-04 21:00 EDT from the repository root.

| Step | Command/log | Exit | Signal |
| --- | --- | --- | --- |
| Clinical document gate | `/tmp/m1-clinical-document-gate.log` | `2` | Passed syntax, shell syntax, and `OpenEMR\\Tests\\Isolated\\AgentForge` PHPUnit (`314 tests, 1583 assertions`), then failed at `Run Clinical document evals` with `threshold_violation`. |
| Week 1 local gate | `/tmp/m1-week1-gate.log` | `0` | Passed syntax, shell syntax, isolated PHPUnit (`314 tests, 1583 assertions`), deterministic evals (`32 passed, 0 failed`), focused PHPStan, and changed-file PHPCS. |
| Clinical document harness self-tests | `/tmp/m1-clinical-document-tests.log` | `0` | Passed `16 tests, 34 assertions`. |
| Fresh clinical document artifact | `agent-forge/eval-results/clinical-document-20260505-005935/` | n/a | `summary.json` verdict is `threshold_violation`; required not-yet-implemented rubrics remain at `0.0` pass rate, while no-PHI/deleted-document not-applicable safety rubrics report no failures. |

## Risks & Mitigations

| Risk | Mitigation |
| --- | --- |
| Case JSON format becomes wrong as M2-M7 land. | `case_format_version` field; loader rejects unknown versions. H1 can bump the version. |
| `NotImplementedAdapter` masks future regressions because every case already fails. | `RubricStatus::NOT_APPLICABLE` and `RubricStatus::FAIL` carry distinct reason strings. Baseline starts at 0.0; any improvement registers immediately — false-pass impossible. |
| Runner exit-code semantics confuse CI. | Documented in script header; `check-clinical-document.sh` treats any non-zero as fail. |
| `NoPhiInLogsRubric` requires the W1 `SensitiveLogPolicy` to already cover W2 fields (M3/H4 expand the allowlist). | Rubric depends on a small `LogScanner` interface; H4 expands the policy behind the same interface — no rubric change. |
| `check-clinical-document.sh` runs slowly. | Mirror `check-local.sh` ordering: lint → tests → evals → static analysis. Fast steps first. |
| Two parallel eval systems (W1 `run-evals.php` + W2 `run-clinical-document-evals.php`) confuse reviewers. | `agent-forge/fixtures/clinical-document-golden/README.md` and the docs hub explicitly distinguish W1 (field-assertion) from W2 (boolean rubrics). |
| Image-only lab fixture (Reyes HbA1c PNG) demands bounding-box from extraction; M1 cannot prove this end-to-end. | M1's `BoundingBoxPresentRubric` only verifies the rubric *shape* against `CaseRunOutput.extraction.bounding_boxes`. M4 makes the rubric pass with real extraction. |

## Dependencies

None. M1 is the foundation.

## Next epic gate

When M1 is complete, M2 begins by extending `ExtractionSystemAdapter` with a real implementation that creates the `agentforge_document_jobs` row and reads back the worker's results. Until that adapter is in place, `check-clinical-document.sh` continues to fail at "Run Clinical document evals" — exactly the regression-gate behavior the assignment requires.

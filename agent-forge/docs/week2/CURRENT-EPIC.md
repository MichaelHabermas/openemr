# Epic M4 — Strict Extraction Tool And Schemas

> Active epic for AgentForge Week 2. Aligns with `W2_ARCHITECTURE.md`,
> `agent-forge/docs/week2/SPECS-W2.md`, `agent-forge/docs/week2/PLAN-W2.md`,
> and the durable rules in `agent-forge/docs/MEMORY.md`. Implementation
> follows the test-first ordering in §"Test/eval strategy". When this
> epic completes, archive it (per the AgentForge memory protocol) and
> open the M5 epic.

**Stakeholder alignment:** MVP checkpoint expectations (e.g. deployment vs localhost-only demos), guideline-corpus definition, and patient chart data vs hybrid-RAG scope are summarized in [`../MEMORY.md`](../MEMORY.md) § *Week 2 stakeholder clarifications (2026-05-05)*; normative detail lives in [`SPECS-W2.md`](SPECS-W2.md) §4–5.2.

## Context

Epic M4 sits between the M3 worker skeleton (which currently fails every
claimed job with `extraction_not_implemented`) and the M5 fact persistence /
lab promotion epic. The M4 job is to:

1. Land the spec-required tool surface `attach_and_extract(patient_id, file_path, doc_type)`
   so graders can call extraction from new file paths AND so the in-process
   upload-hook → worker path can call extraction with an existing OpenEMR
   `documents.id` reference. Both branches converge after OpenEMR has stored
   the source document.
2. Replace the `NoopDocumentJobProcessor` with a real `IntakeExtractorWorker`
   that processes both `lab_pdf` and `intake_form` jobs. The worker name
   stays exactly `intake-extractor` per spec, even though it covers both
   doc types — code comments must say so.
3. Provide a small, swappable extraction-provider interface with two
   implementations: a fixture-driven deterministic provider for evals/tests
   and a real OpenAI VLM provider for runtime. Production code never
   trusts model output without strict schema validation.
4. Define strict cited PHP value objects for `lab_pdf` and `intake_form`
   extraction, plus shared `DocumentCitation` and `BoundingBox` value objects.
   Schema validation is a hard gate before any persistence — M5 work must
   never see invalid model output.
5. Classify each candidate fact into one of three certainty buckets
   (`verified`, `document_fact`, `needs_review`) using deterministic
   classification rules so M5 persistence/promotion can act on those buckets
   without re-deciding certainty.
6. Surface typed extraction errors with stable `error_code` strings
   (`unsupported_doc_type`, `missing_file`, `storage_failure`,
   `extraction_failure`, `schema_validation_failure`) so worker
   telemetry is inspectable through the existing `SensitiveLogPolicy`
   allowlist without leaking raw PHI. (`persistence_failure` and
   `duplicate_detected` are M5 codes; deferred to that epic.)

Out of scope for M4 (these belong to later epics — flag, do not build):
- Persisting cited document facts, embeddings, or promoting verified labs
  into OpenEMR `procedure_*` tables (M5).
- Wrong-patient identity verification (M5A).
- Promotion provenance audit and duplicate prevention at the chart-row
  layer (M5B).
- Source-document retraction of facts/embeddings/promoted rows (M5C).
- Guideline corpus, hybrid retrieval, rerank (M6).
- Supervisor/evidence-retriever orchestration and final-answer separation
  (M7).
- 50-case eval expansion, deployment proof, cost/latency report,
  reviewer-guide rewrite (H1–H5, FINAL).

## Hard constraints carried forward

These come from `MEMORY.md`, `W2_ARCHITECTURE.md`, and earlier epics. M4
implementation must not violate them.

- All-PHP. No Python sidecar. No external extraction microservice.
- Source document is stored in OpenEMR FIRST. Extraction never runs
  before the OpenEMR `documents` row exists.
- Extraction failure must NEVER undo the OpenEMR upload. The job is
  marked failed, the source document remains, and no clinical data is
  promoted from the failed job.
- The worker name is exactly `intake-extractor`. The supervisor name is
  `supervisor`. The evidence retriever name is `evidence-retriever`.
- `SensitiveLogPolicy` allows job_id, patient_ref, document_id, doc_type,
  worker, status, counts, latency_ms, model, tokens, cost, error_code.
  It forbids raw quote/value, document_text, document_image,
  extracted_fields, patient_name, prompts, answers, image bytes,
  PDF contents, screenshots.
- `patient_ref` is a hashed short HMAC of the patient id, never the raw id.
- Tests/evals first, before production code, in every M4 task.
- Legacy `Throwable` catches in source code are restricted; M4 source code
  catches modeled exceptions and lets fatal errors propagate to the worker
  boundary, which already has the rethrowing `Throwable` cleanup in M3.
- Strict typing in every new file: `declare(strict_types=1)`,
  PSR-4 namespace `OpenEMR\AgentForge\Document\...`, final readonly value
  objects, native types on every parameter and return.
- DRY only when duplication is real. SOLID: each class has one reason to
  change, dependencies injected.

## Existing code reused

The design below reuses these existing seams rather than introducing
parallel ones.

### M3 worker seam (already in place — M4 implements against this)

- Interface: `DocumentJobProcessor::process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult`
- File: [DocumentJobProcessor.php](src/AgentForge/Document/Worker/DocumentJobProcessor.php)
- Current implementation: [NoopDocumentJobProcessor.php](src/AgentForge/Document/Worker/NoopDocumentJobProcessor.php)
  fails every job with error code `extraction_not_implemented`.
- M4 ships a real implementation conforming exactly to this signature; the
  outer `DocumentJobWorker` (claim/retry/timeout/cleanup) is unchanged.

### Document loader (reused as-is, no changes)

- `OpenEmrDocumentLoader::load(DocumentId): DocumentLoadResult`
- `DocumentLoadResult` carries `{ bytes, mimeType, name, byteCount }`.
- Throws `DocumentLoadException` with stable `errorCode` strings — pattern
  M4 mirrors for `ExtractionFailedException`.

### Existing model-provider pattern (M4 mirrors verbatim)

The `DraftProvider` family in `src/AgentForge/ResponseGeneration/` is the
template:

- Interface `DraftProvider` with implementations `OpenAiDraftProvider`,
  `AnthropicDraftProvider`, `FixtureDraftProvider`, `DisabledDraftProvider`.
- Factory `DraftProviderFactory::create(DraftProviderConfig)` selects by
  `$config->mode` (`MODE_FIXTURE`, `MODE_DISABLED`, `MODE_OPENAI`,
  `MODE_ANTHROPIC`).
- `DraftProviderConfig::fromEnvironment()` is the static env-driven entry
  point; `envString()` / `envFloat()` static helpers use `getenv($name, true)`
  with `trim()` validation.
- HTTP: Guzzle 7.x + PSR-18, `DraftProviderRetryMiddleware` is deadline-aware
  and registered on the handler stack.
- Cost tracking via per-1M-token env vars
  (`AGENTFORGE_OPENAI_INPUT_COST_PER_1M`, etc.).
- API key precedence: `AGENTFORGE_OPENAI_API_KEY` then `OPENAI_API_KEY`.

M4 introduces a parallel `DocumentExtractionProvider` family with the same
shape. The retry middleware is reused (same Guzzle handler stack — no
extraction-specific retry semantics required for M4).

### Existing schema / value-object patterns (M4 reuses)

- `final readonly class` + constructor validation that throws
  `DomainException` on invalid input is the project's standard for value
  objects (e.g. `DocumentId`, `WorkerName`).
- `fromStringOrThrow(string $value): self` is the enum factory convention
  (used by `WorkerName`, etc.) — M4's new enums match this.
- `CitationShape::isValid(array $citation): bool` (located at
  [src/AgentForge/Eval/ClinicalDocument/Rubric/CitationShape.php](src/AgentForge/Eval/ClinicalDocument/Rubric/CitationShape.php))
  already validates the five required citation keys (`source_type`,
  `source_id`, `page_or_section`, `field_or_chunk_id`, `quote_or_value`).
- **Bounding box convention already in place:** `CitationShape` validates
  `bounding_box` as an object with `x, y, width, height` floats — NOT the
  `[x0, y0, x1, y1]` array shape mentioned in `W2_ARCHITECTURE.md`. M4 aligns
  with the existing validator. The architecture doc delta is recorded as
  informational only and is not a code change.
- `DocumentLoadException` is the typed-error model: stable `errorCode`
  string + sanitized message. M4's `ExtractionFailedException` and
  `SchemaValidationException` follow the same shape.
- `OpenEMR\AgentForge\Deadline` already exists at
  [src/AgentForge/Deadline.php](src/AgentForge/Deadline.php) — M4 reuses
  this; no new clock/deadline class is introduced.
- `OpenEMR\AgentForge\AgentForgeClock` is the project's clock abstraction
  (used by `DocumentJobWorker`). M4 uses this, NOT PSR-20 `ClockInterface`.
- `PatientRefHasher` already exists and is used by `DocumentJobWorker`
  for `patient_ref` HMAC hashing. M4 reuses it; no new hasher.
- `ProcessingResult` API (existing): `succeeded()` (no payload) and
  `failed(string $errorCode, string $errorMessage)`. There is no
  `completed()` factory and no payload channel. M4 returns
  `ProcessingResult::succeeded()` and emits all extraction telemetry via
  the logger (sanitized through `SensitiveLogPolicy`). The parsed
  extraction value object is not returned through `ProcessingResult` —
  it stays in the worker's local scope and is logged as counts only.
  M5 will introduce the persistence path that needs the value object;
  M4 does not.

### Existing SensitiveLogPolicy (M4 reuses, may extend)

ALLOWED_KEYS already covers: `job_id`, `patient_ref`, `document_id`,
`doc_type`, `worker`, `status`, `model`, `input_tokens`, `output_tokens`,
`estimated_cost`, `error_code`, `latency_ms`.

FORBIDDEN_KEYS already covers: `extracted_fields`, `document_text`,
`document_image`, `prompts`, `answers`, `patient_name`, raw quote/value, etc.

`SensitiveLogPolicy::throwableErrorContext(\Throwable $e): array` already
shapes safe error context for log payloads — M4's worker uses it.

M4 adds the following ALLOWED keys (telemetry-only, never values):
`extraction_provider`, `fact_count_verified`, `fact_count_document_fact`,
`fact_count_needs_review`, `schema_valid`.

### Worker name enum (already present)

- `WorkerName::IntakeExtractor = 'intake-extractor'` exists in
  [WorkerName.php](src/AgentForge/Document/Worker/WorkerName.php).
- Used by `--worker=intake-extractor` CLI argument validation
  (`WorkerName::fromStringOrThrow()`).
- M4 emits this exact literal in worker telemetry for both `lab_pdf` and
  `intake_form` jobs and adds a code comment in the worker explaining why
  the name is broader than intake forms.

### Eval runner (extended, not replaced)

- Entry: `RunClinicalDocumentEvalsCommand::run($repoDir)` in
  `agent-forge/scripts/run-clinical-document-evals.php`.
- Adapter interface: `runCase(EvalCase): CaseRunOutput`.
- Current adapter: `NotImplementedAdapter` (M1 stub returning
  `status: 'not_implemented'`).
- M4 introduces a new adapter (`ClinicalDocumentExtractionAdapter`) that
  wires `AttachAndExtractTool` with `FixtureExtractionProvider`.
- Case JSON: `agent-forge/fixtures/clinical-document-golden/cases/*.json`.
- Sample documents: `agent-forge/docs/example-documents/` — **not** under
  `week2/`. The case JSON `source_document_path` strings currently point
  at the wrong subdirectory and will be corrected as part of M4.
- Existing rubrics include `schema_valid`, `citation_present`,
  `bounding_box_present`, `factually_consistent`, `safe_refusal`,
  `no_phi_in_logs`, `deleted_document_not_retrieved`.

### Test conventions

- PHPUnit 11. Existing AgentForge isolated tests use `test*()` method-name
  convention (no `#[Test]` attribute). M4 matches this.
- Project CLAUDE.md mandates `#[DataProvider('...')]` attribute with
  `@codeCoverageIgnore Data providers run before coverage instrumentation
  starts.` annotation when data providers are used. M4 follows the project
  rule even though older AgentForge files predate it.

### PDF / image stack (constraint, not configurable)

- Only `ext-imagick` is available. No PDF parser library is installed
  (no `smalot/pdfparser`, no `spatie/pdf-to-image` in `composer.json`).
- Bounding boxes for both typed and scanned PDFs come from the VLM at
  runtime. The fixture provider supplies deterministic boxes for tests/evals.
- The OpenAI VLM provider is the only runtime path to bounding boxes
  for M4.

## Concrete design

### Module layout (PSR-4 under `OpenEMR\AgentForge\…`)

Spec deliverables from PLAN-W2.md §M4 (9 files):
`AttachAndExtractTool.php`, `IntakeExtractorWorker.php`,
`DocumentExtractionProvider.php`, `OpenAiVlmExtractionProvider.php`,
`FixtureExtractionProvider.php`, `LabPdfExtraction.php`,
`IntakeFormExtraction.php`, `DocumentCitation.php`, `BoundingBox.php`.

Supporting types — necessary to satisfy the *strict-schema* and
*one-extraction-code-path* requirements of those deliverables, justified
inline below:

```
src/AgentForge/Document/
  AttachAndExtractTool.php                       (new — spec deliverable)
  AttachAndExtractInput.php                      (new readonly VO; supports the two factory entry points)
  AttachAndExtractResult.php                     (new readonly VO; typed return)
  ExtractionErrorCode.php                        (new backed string enum; M4 codes only)
  SourceDocumentStorage.php                      (new interface; storage seam for forUploadedFile())
  OpenEmrSourceDocumentStorage.php               (new; production impl wrapping legacy Document.class.php)
  InMemorySourceDocumentStorage.php              (new; test impl)
  Extraction/
    DocumentExtractionProvider.php               (new interface — spec deliverable)
    ExtractionProviderConfig.php                 (new readonly VO; mirrors DraftProviderConfig)
    ExtractionProviderFactory.php                (new; mirrors DraftProviderFactory)
    OpenAiVlmExtractionProvider.php              (new — spec deliverable)
    FixtureExtractionProvider.php                (new — spec deliverable)
    ExtractionProviderResponse.php               (new readonly VO; provider return type)
    JsonSchemaBuilder.php                        (new; doc-type → strict JSON schema array)
    ExtractionFailedException.php                (new — typed errorCode)
    SchemaValidationException.php                (new — typed errorCode + fieldPath)
    CertaintyClassifier.php                      (new — deterministic rules; single class, branches by DocumentType)
  Worker/
    IntakeExtractorWorker.php                    (new — spec deliverable; implements DocumentJobProcessor)
  Schema/
    DocumentCitation.php                         (new readonly VO — spec deliverable)
    DocumentSourceType.php                       (new backed string enum; lab_pdf|intake_form|guideline|chart)
    BoundingBox.php                              (new readonly VO — spec deliverable)
    LabPdfExtraction.php                         (new readonly VO — spec deliverable)
    LabResultRow.php                             (new readonly per-row VO; schema row type)
    IntakeFormExtraction.php                     (new readonly VO — spec deliverable)
    IntakeFormFinding.php                        (new readonly per-row VO; schema row type)
    Certainty.php                                (new unit enum: Verified, DocumentFact, NeedsReview)
    AbnormalFlag.php                             (new backed string enum: low|normal|high|critical_low|critical_high)
```

**Cut from earlier sketch (per SOLID/scope audit):**
- `DisabledExtractionProvider` — not needed; fixture mode is the no-op
  for tests. Disabled mode is reachable only via explicit
  `AGENTFORGE_VLM_PROVIDER=disabled`, which is a future concern.
- `ExtractedFact` — a wrapper around `(LabResultRow|IntakeFormFinding,
  Certainty)`. The worker emits `Certainty` per row directly into log
  counts; no wrapper VO needed for M4.
- `ExtractionTelemetry` value object — duplicates
  `SensitiveLogPolicy::sanitizeContext(array): array`. The worker assembles
  a `array<string, scalar>` telemetry payload and passes it through
  `sanitizeContext()` directly.
- `SourceDocument` standalone VO — borderline duplicative of
  `DocumentLoadResult`. M4 passes `(DocumentId $id, DocumentLoadResult
  $document, DocumentType $docType)` directly to the provider; there is
  no `SourceDocument` class. The provider interface signature is
  updated to match.

Modified existing files:
- `src/AgentForge/Document/Worker/DocumentJobWorkerFactory.php` — wire the
  new `IntakeExtractorWorker` in place of `NoopDocumentJobProcessor`.
- `agent-forge/scripts/process-document-jobs.php` — construct the worker
  with `ExtractionProviderFactory::create(ExtractionProviderConfig::fromEnvironment())`.
- `agent-forge/scripts/run-clinical-document-evals.php` — replace
  `NotImplementedAdapter` with the new extraction adapter.
- `src/AgentForge/Observability/SensitiveLogPolicy.php` — add the new
  ALLOWED keys listed in the SensitiveLogPolicy section above (additive only).
- `agent-forge/fixtures/clinical-document-golden/cases/*.json` — fix
  `source_document_path` strings: existing case files reference
  `agent-forge/docs/week2/example-documents/...` which does not exist;
  rewrite to `agent-forge/docs/example-documents/...`. (Affects all 8
  case files. The `ClinicalDocumentExtractionAdapter` resolves these
  paths relative to the repo root.)

New eval-side files:
- `src/AgentForge/Eval/ClinicalDocument/Adapter/ClinicalDocumentExtractionAdapter.php`
  (new — `runCase(EvalCase): CaseRunOutput`). Replaces `NotImplementedAdapter`.

New shared helpers (DRY lift before duplicating):
- `src/AgentForge/Common/EnvVar.php` — extracts the existing
  `envString()` / `envFloat()` private helpers from `DraftProviderConfig`
  into a shared utility used by both `DraftProviderConfig` and the new
  `ExtractionProviderConfig`. Light-touch refactor: `DraftProviderConfig`
  delegates to `EnvVar::string($name)` / `EnvVar::float($name)`.
- `src/AgentForge/Common/GuzzleClientFactory.php` — extracts the
  Guzzle handler-stack + retry-middleware client construction from
  `DraftProviderFactory::buildClient()` into a shared factory used by
  both provider factories. Same light-touch refactor.

Both helpers are SRP (env-var read; HTTP-client build). Lifting them is
explicitly justified by the second provider family being introduced in
M4 — duplicate now, refactor later would be churn.

### `DocumentExtractionProvider` — interface

```php
interface DocumentExtractionProvider
{
    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $docType,
        Deadline $deadline,
    ): ExtractionProviderResponse;
}
```

The provider receives the existing `DocumentLoadResult` directly (no
intermediate `SourceDocument` VO). `DocumentLoadResult` already exposes
`bytes`, `mimeType`, `name`, and `byteCount` — wrapping it adds nothing.
The provider does not see the `DocumentJob` (no patient_ref, no job_id);
those are worker-layer concerns and stay out of the provider contract.

`ExtractionProviderResponse` carries:
- `rawJson` — raw JSON string returned by the provider (for traceability;
  never logged)
- `extraction` — parsed strict value object (`LabPdfExtraction`
  or `IntakeFormExtraction`)
- `model` — model identifier string (e.g. `gpt-4o`, `fixture-extraction-provider`)
- `inputTokens`, `outputTokens` — non-negative ints (zero for fixture)
- `estimatedCostUsd` — nullable float (null for fixture)
- `latencyMs` — non-negative int

The interface is the SOLID seam: M5 will introduce a real persister behind
the worker without touching the provider; tests swap to fixture without
HTTP.

### `ExtractionProviderConfig` (mirrors `DraftProviderConfig`)

```php
final readonly class ExtractionProviderConfig
{
    public const MODE_FIXTURE = 'fixture';
    public const MODE_OPENAI  = 'openai';

    public static function fromEnvironment(): self;
}
```

Selection precedence (matches `DraftProviderConfig`):
1. Explicit `AGENTFORGE_VLM_PROVIDER` env var (`fixture` | `openai`)
2. Else `MODE_OPENAI` if `AGENTFORGE_OPENAI_API_KEY` or `OPENAI_API_KEY` set
3. Else `MODE_FIXTURE`

> Disabled mode is intentionally not part of the M4 enum. The
> `DisabledExtractionProvider` was cut from the module layout (per the
> SOLID/scope audit) — fixture mode is the no-op for tests, and there is
> no current use case for a third "always fail" mode. M5+ may add it
> behind a third enum case if a real driver appears.

### Env vars introduced by M4

W2_ARCHITECTURE.md §Environment table mandates `AGENTFORGE_VLM_PROVIDER`
and `AGENTFORGE_VLM_MODEL`. M4 adheres to those literal names. Pricing
and timeout knobs use the same `AGENTFORGE_VLM_*` prefix for consistency.

- `AGENTFORGE_VLM_PROVIDER` — explicit mode override (`fixture` | `openai`)
- `AGENTFORGE_VLM_MODEL` — vision-capable model id (default: `gpt-4o`)
- `AGENTFORGE_VLM_INPUT_COST_PER_1M` — pricing for cost estimate
- `AGENTFORGE_VLM_OUTPUT_COST_PER_1M` — pricing for cost estimate
- `AGENTFORGE_VLM_TIMEOUT_SECONDS` — default 60 (vision is slower than chat)
- `AGENTFORGE_VLM_CONNECT_TIMEOUT_SECONDS` — default 10
- `AGENTFORGE_VLM_MAX_PAGES` — default 5 (Imagick page render cap; see
  `OpenAiVlmExtractionProvider` below)
- `AGENTFORGE_EXTRACTION_FIXTURES_DIR` — fixture-provider override; set
  by tests, never set in production
- Reuses `AGENTFORGE_OPENAI_API_KEY` / `OPENAI_API_KEY` (already present)

### `ExtractionProviderFactory`

```php
final class ExtractionProviderFactory
{
    public function create(ExtractionProviderConfig $config): DocumentExtractionProvider
    {
        return match ($config->mode) {
            ExtractionProviderConfig::MODE_FIXTURE => new FixtureExtractionProvider(...),
            ExtractionProviderConfig::MODE_OPENAI  => new OpenAiVlmExtractionProvider(...),
        };
    }
}
```

The `match` is exhaustive over `MODE_FIXTURE` and `MODE_OPENAI`. PHPStan
verifies exhaustiveness; no `default` branch.

### `FixtureExtractionProvider`

- Reads deterministic JSON outputs from
  `agent-forge/fixtures/clinical-document-golden/extraction/<fixture_id>.json`
- Lookup key: sha256 of `DocumentLoadResult::$bytes` → fixture filename,
  with a manifest in `extraction/manifest.json`:
  ```json
  { "<sha256-hex>": "chen-lab-typed.json", ... }
  ```
  Hashing source bytes avoids coupling to OpenEMR document ids, which
  vary across fresh installs and across CI vs. dev environments.
- Cases that share the same source bytes share a fixture entry
  (e.g. `chen-lab-typed` and `chen-lab-duplicate-upload` use the same
  underlying PDF; the manifest maps both sha256 entries — or, more
  precisely, the single sha256 of the shared bytes — to one fixture file).
- Returns `ExtractionProviderResponse` with
  `model = 'fixture-extraction-provider'`, zero token usage, null cost,
  latency = 0.
- No HTTP. No clock. No env reads beyond `AGENTFORGE_EXTRACTION_FIXTURES_DIR`
  (set by tests/evals; never set in production).
- Missing-fixture lookup → `ExtractionFailedException(MissingFile)` with
  the sha256 included in the sanitized error context.

### `OpenAiVlmExtractionProvider`

- Constructor signature mirrors `OpenAiDraftProvider`: injected
  `ClientInterface` (PSR-18), `LoggerInterface`, `AgentForgeClock`,
  model id, cost rates, timeouts, and an injected `Imagick`-backed
  page renderer (`PdfPageRenderer` interface, see below). Tests use
  the standard mock-Guzzle pattern from `OpenAiDraftProviderTest`.
- Reuses the shared Guzzle handler stack from `Common/GuzzleClientFactory`
  with `DraftProviderRetryMiddleware` registered (deadline-aware retry).
- Endpoint: `POST /v1/chat/completions` (same surface as
  `OpenAiDraftProvider`). No File API in M4 — sticking to one HTTP
  endpoint keeps the test surface and retry behavior identical.

**PDF/image input handling.** `gpt-4o`'s Chat Completions endpoint accepts
images via `image_url` content blocks but does NOT accept raw PDFs as
binary input. The provider therefore branches on `mimeType`:

- `image/png` / `image/jpeg` / `image/webp`: send a single `image_url`
  block with `data:<mime>;base64,<bytes>` URL.
- `application/pdf`: render up to `AGENTFORGE_VLM_MAX_PAGES` (default 5)
  pages via `PdfPageRenderer` (thin wrapper around `Imagick::readImageBlob`
  with `setResolution(150)` and `setImageFormat('png')`). Each page becomes
  one `image_url` block. PDFs longer than the page cap → first N pages
  only; the cap is logged as `pages_rendered` telemetry.
- Anything else → `ExtractionFailedException(UnsupportedDocType)` with
  the rejected mime type in the sanitized error.

**Imagick is injected behind a `PdfPageRenderer` interface so the unit
test never touches `ext-imagick`.** The interface returns
`list<string> $pngBytesPerPage`. The production impl wraps Imagick;
the test impl returns canned bytes per fixture sha256.

**Strict-JSON response format.** Uses the OpenAI structured-outputs
shape `response_format: { type: "json_schema", json_schema: { name,
strict: true, schema: <built schema> } }`. Schema built by
`JsonSchemaBuilder::for(DocumentType): array`, which mirrors the value
object shape one-for-one. The JSON-schema name is doc-type-specific
(`lab_pdf_extraction_v1`, `intake_form_extraction_v1`).

**Parse path.** On HTTP 200, the provider:
1. Decodes the response body and extracts
   `choices[0].message.content` (a JSON string).
2. Calls `LabPdfExtraction::fromJson()` or
   `IntakeFormExtraction::fromJson()` — these throw
   `SchemaValidationException` on shape violations.
3. Wraps the parsed VO in `ExtractionProviderResponse`.

**Error mapping (no partial output, ever).**
- HTTP 4xx (non-401) → `ExtractionFailedException(ExtractionFailure)`
- HTTP 401 → `ExtractionFailedException(ExtractionFailure)` with
  sanitized `auth_failed` status flag (no key material in logs)
- HTTP 5xx after retry exhaustion → `ExtractionFailedException(ExtractionFailure)`
- Connection timeout / read timeout / `Deadline::exceeded()` →
  `ExtractionFailedException(ExtractionFailure)` with `latencyMs`
- `json_decode` failure → `SchemaValidationException(SchemaValidationFailure,
  fieldPath: '<root>')`
- Value-object constructor `DomainException` →
  `SchemaValidationException(SchemaValidationFailure, fieldPath: <field>)`
- Imagick render failure → `ExtractionFailedException(StorageFailure)`
  (the page bytes never made it to the model; treat as input-side failure)

**Logging.** Never logs prompt content, page bytes, base64 URLs, raw
response body, or parsed extraction values. Only logs sanitized
counters: `model`, `input_tokens`, `output_tokens`, `estimated_cost`,
`latency_ms`, `pages_rendered`, `http_status`, `error_code`. All keys
flow through `SensitiveLogPolicy::sanitizeContext` before reaching
`LoggerInterface::info/error`.

### `LabPdfExtraction` / `IntakeFormExtraction`

Final readonly value objects. Both expose:
```php
public static function fromJson(string $json): self;
public static function fromArray(array $data): self;
```

Constructors validate at the boundary: required fields present, enum
values valid, every result/finding has a `DocumentCitation`, and bounding
boxes present where the spec requires them for promotion-eligible facts.
Throw `SchemaValidationException` (typed `errorCode`,
`SchemaValidationFailure`) with a `fieldPath` like `results[0].value` so
M5 can target failures.

No mutators. No nullable stuffing — fields that may be missing in the
source document become `IntakeFormFinding`s with `Certainty::NeedsReview`,
not nullable struct fields.

`LabPdfExtraction` shape (from W2_ARCHITECTURE.md §6.1):
- `patientCandidate`: { name, dob, mrnLast4 } — for M5A wrong-patient gate
- `collectedAt`: nullable ISO-8601 string
- `results`: list of `LabResultRow`
- `documentCitation`: `DocumentCitation` for the document overall

`LabResultRow` shape:
- `testName`: string
- `loincCode`: nullable string
- `value`: string (kept as string per spec; numeric parse is M5)
- `unit`: nullable string
- `referenceRange`: nullable string
- `abnormalFlag`: nullable `AbnormalFlag` enum
- `confidence`: float in `[0.0, 1.0]`
- `citation`: `DocumentCitation` (with bounding box if `Verified`)

`IntakeFormExtraction` shape:
- `patientCandidate`: same shape as above
- `findings`: list of `IntakeFormFinding`
- `documentCitation`: `DocumentCitation`

`IntakeFormFinding` shape:
- `category`: backed enum (`medication`, `allergy`, `condition`,
  `family_history`, `preference`, `other`)
- `text`: string (verbatim, but length-bounded — long-form blobs become
  `NeedsReview`)
- `confidence`: float
- `citation`: `DocumentCitation`

### `DocumentCitation` (aligns with existing `CitationShape`)

```php
final readonly class DocumentCitation
{
    public function __construct(
        public DocumentSourceType $sourceType,   // lab_pdf | intake_form | guideline | chart
        public string $sourceId,                  // e.g. "documents:123"
        public string $pageOrSection,             // e.g. "page 1"
        public string $fieldOrChunkId,            // e.g. "results[0].value"
        public string $quoteOrValue,              // verbatim
        public ?BoundingBox $boundingBox = null,
    ) { /* validates non-empty strings */ }
}
```

The citation value object never lands in telemetry — only counts and
booleans (`citation_present`) go to logs. `quoteOrValue` is in
`SensitiveLogPolicy::FORBIDDEN_KEYS`.

### `BoundingBox` (aligns with existing `CitationShape` validator)

```php
final readonly class BoundingBox
{
    public function __construct(
        public float $x,        // [0.0, 1.0]
        public float $y,        // [0.0, 1.0]
        public float $width,    // > 0, x + width <= 1
        public float $height,   // > 0, y + height <= 1
    ) { /* validates ranges, throws DomainException */ }
}
```

> Note: `W2_ARCHITECTURE.md` mentions `[x0, y0, x1, y1]`. The existing
> `CitationShape::isValid()` validator already accepts `{x, y, width,
> height}`. M4 aligns with the existing validator and treats the
> architecture doc form as informational. Architecture doc will not be
> changed under M4 — this is captured in MEMORY.md as a delta to revisit.

### `Certainty` and `AbnormalFlag` enums

`Certainty` is a unit enum (no string backing) for purely runtime state:
```php
enum Certainty {
    case Verified;
    case DocumentFact;
    case NeedsReview;
}
```

`AbnormalFlag` is a backed string enum (persisted into telemetry):
```php
enum AbnormalFlag: string {
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case CriticalLow = 'critical_low';
    case CriticalHigh = 'critical_high';

    public static function fromStringOrThrow(string $value): self;
}
```

### `ExtractionErrorCode` (backed string enum)

Per the spec error catalog (M4-emitted codes only):
```php
enum ExtractionErrorCode: string {
    case UnsupportedDocType         = 'unsupported_doc_type';
    case MissingFile                = 'missing_file';
    case StorageFailure             = 'storage_failure';
    case ExtractionFailure          = 'extraction_failure';
    case SchemaValidationFailure    = 'schema_validation_failure';
}
```

`persistence_failure` and `duplicate_detected` are M5 codes; they will
be added to the enum in M5 alongside the persistence path that emits
them. Defining them in M4 would invite premature use and trip the
"never define unused enum cases" rule from the SOLID audit. Likewise,
`provider_disabled` is dropped along with `DisabledExtractionProvider`.

### `CertaintyClassifier`

Deterministic. Pure function of the candidate; no I/O, no clock, no
random. This is the **only** place certainty is decided. M5 reads the
result and does not re-decide.

```php
final class CertaintyClassifier
{
    public function __construct(
        private float $verifiedThreshold = 0.85,
        private float $documentFactThreshold = 0.50,
    ) { /* validates 0 < documentFactThreshold < verifiedThreshold <= 1 */ }

    public function classify(
        DocumentType $docType,
        LabResultRow|IntakeFormFinding $candidate,
    ): Certainty;
}
```

The classifier is env-free and unit-testable. M4 wires the default
thresholds directly.

**Locked boundary semantics** (no ambiguity in tests):

Let `c = $candidate->confidence` (already validated `[0.0, 1.0]` at
the value-object boundary). Let `q = trim($candidate->citation->quoteOrValue)`.

Define `quoteIsWeak` as:
```
quoteIsWeak ⇔ strlen(q) < 3  OR  ctype_digit(q)
```
i.e., the quote is fewer than 3 characters after trimming, or it is
purely digits (e.g. `"42"` alone is too generic to source-of-truth).
The `ctype_digit` check rejects empty strings already.

Define `mapsToChartDestination(candidate, docType)` as:
- `docType = lab_pdf`: the candidate is a `LabResultRow` AND
  `testName !== ''` AND `value !== ''` AND `unit !== null`.
  (LOINC code is preferred but not required for this gate; M5 may
  tighten with a LOINC allowlist.)
- `docType = intake_form`: the candidate is an `IntakeFormFinding` AND
  `category` ∈ `{medication, allergy, condition}`. Categories
  `family_history`, `preference`, `other` are explicitly excluded —
  they remain `DocumentFact` even when the model is highly confident,
  per the no-inference rule.

**Classification (in order; first match wins):**

1. If `quoteIsWeak`: `Certainty::NeedsReview`.
2. Else if `c < documentFactThreshold` (i.e., `c < 0.50`):
   `Certainty::NeedsReview`.
3. Else if `c >= verifiedThreshold` (i.e., `c >= 0.85`)
   AND `mapsToChartDestination`: `Certainty::Verified`.
4. Else: `Certainty::DocumentFact`.

Boundary table for the test data provider:
| confidence  | maps    | quote   | bucket          |
|-------------|---------|---------|-----------------|
| 0.85        | true    | strong  | Verified        |
| 0.84999     | true    | strong  | DocumentFact    |
| 0.85        | false   | strong  | DocumentFact    |
| 0.50        | true    | strong  | DocumentFact    |
| 0.49999     | true    | strong  | NeedsReview     |
| any         | any     | weak    | NeedsReview     |
| 1.00        | true    | "12"    | NeedsReview (digits-only)  |
| 1.00        | true    | "ab"    | NeedsReview (len < 3)      |

### `IntakeExtractorWorker` (replaces `NoopDocumentJobProcessor`)

Implements `DocumentJobProcessor::process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult`.

```php
final class IntakeExtractorWorker implements DocumentJobProcessor
{
    public function __construct(
        private DocumentExtractionProvider $provider,
        private CertaintyClassifier $classifier,
        private LoggerInterface $logger,
        private AgentForgeClock $clock,
        private SensitiveLogPolicy $logPolicy,
        private int $budgetMs = 60_000,
    ) {}

    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult
    {
        // Spec requires worker name "intake-extractor"; this single worker
        // handles BOTH lab_pdf and intake_form jobs. The name is broader
        // than intake forms by design.
        // ...
    }
}
```

`AgentForgeClock` is the project's clock abstraction (already used by
`DocumentJobWorker`); PSR-20 `ClockInterface` is not used here.
`DocumentJobProcessor::process` does not receive a `Deadline`, so the
worker constructs one from `$this->clock->now()` and `$this->budgetMs`.
`$budgetMs` is wired from a future env var or kept at the default
`60_000` for M4.

Per claimed job (top-down):

1. **Doc type gate.** `$docType = $job->docType` (already a backed
   enum). If `$docType` is anything other than `LabPdf` or
   `IntakeForm`, return
   `ProcessingResult::failed(UnsupportedDocType->value, sanitized message)`.
   (`DocumentType` enum may include other values for M5+; M4 only
   handles these two.)

2. **Build deadline.** `$deadline = Deadline::after($this->clock,
   $this->budgetMs)`. Pass it to the provider.

3. **Call provider.** `$response = $this->provider->extract(
   $job->documentId, $document, $docType, $deadline);`

4. **Catch typed errors.** Wrap the provider call in a try block
   catching `ExtractionFailedException` and `SchemaValidationException`
   only. Map to `ProcessingResult::failed($e->errorCode->value, $e->getMessage())`.
   Other `Throwable` propagates to the outer worker boundary, which
   already has the M3 rethrow-and-cleanup path.

5. **Classify per row.** For each `LabResultRow` or `IntakeFormFinding`
   in `$response->extraction`, call `$this->classifier->classify(...)`.
   Tally per-bucket counts (`verified`, `document_fact`, `needs_review`)
   into local `int` accumulators. **No `ExtractedFact` wrapper VO** —
   the parsed rows already carry their typed shape; the worker only
   needs counts for telemetry.

6. **Build sanitized telemetry payload** (keys from
   `SensitiveLogPolicy::ALLOWED_KEYS`):
   ```php
   $context = $this->logPolicy->sanitizeContext([
       'worker'                   => WorkerName::IntakeExtractor->value,
       'job_id'                   => $job->jobId->value,
       'patient_ref'              => $job->patientRef,  // already HMAC
       'document_id'              => $job->documentId->value,
       'doc_type'                 => $docType->value,
       'extraction_provider'      => $response->model,
       'model'                    => $response->model,
       'input_tokens'             => $response->inputTokens,
       'output_tokens'            => $response->outputTokens,
       'estimated_cost'           => $response->estimatedCostUsd,
       'latency_ms'               => $response->latencyMs,
       'fact_count_verified'      => $verifiedCount,
       'fact_count_document_fact' => $documentFactCount,
       'fact_count_needs_review'  => $needsReviewCount,
       'schema_valid'             => true,
       'status'                   => 'succeeded',
   ]);
   $this->logger->info('document.extraction.completed', $context);
   ```

7. **Return success.** `return ProcessingResult::succeeded();`. There is
   no payload channel (verified against `ProcessingResult.php:27`). The
   parsed `$response->extraction` is intentionally discarded for M4 —
   M5 will introduce the persistence path that reaches into the worker
   and replaces `succeeded()` with a richer return.

For M4, "success" means the worker proves it can produce a strict
schema-valid extraction with citations, bounding boxes, and certainty
buckets, and the eval runner reads those from a deterministic fixture
run via the eval-side adapter (which calls the provider directly via
`AttachAndExtractTool`, not through this worker — see the eval section).

### `AttachAndExtractTool`

```php
final class AttachAndExtractTool
{
    public function __construct(
        private SourceDocumentStorage $storage,
        private OpenEmrDocumentLoader $loader,
        private DocumentExtractionProvider $provider,
        private AgentForgeClock $clock,
        private int $budgetMs = 60_000,
    ) {}

    public function extract(AttachAndExtractInput $input): AttachAndExtractResult;
}
```

`AttachAndExtractInput` factories:
```php
public static function forUploadedFile(
    PatientId $patientId,
    string $filePath,
    DocumentType $docType,
): self;

public static function forExistingDocument(
    PatientId $patientId,
    DocumentId $documentId,
    DocumentType $docType,
): self;
```

The factories are the only public construction path; the constructor
is private. Each factory validates inputs and stores a discriminator
flag (`fromFile` vs `fromExistingDocument`).

Internal flow (single extraction code path):

```
forUploadedFile(patientId, filePath, docType)
        │
        ├─ $this->storage->store(patientId, filePath, docType)
        │      └→ returns DocumentId, or throws StorageException
        │
forExistingDocument(patientId, documentId, docType)
        │
        ▼  (both paths converge with a DocumentId)
        │
        ├─ $this->loader->load($documentId)
        │      └→ returns DocumentLoadResult
        │
        ├─ runExtraction($documentId, $loadResult, $docType)
        │      └→ $this->provider->extract(...) with a fresh Deadline
        │
        ▼
   AttachAndExtractResult
```

The `SourceDocumentStorage` interface is the seam over OpenEMR's legacy
storage path:

```php
interface SourceDocumentStorage
{
    /**
     * Persists the file as an OpenEMR document for the given patient and
     * returns the new DocumentId. Throws StorageException on missing
     * file (errorCode=MissingFile) or write failure (StorageFailure).
     */
    public function store(
        PatientId $patientId,
        string $filePath,
        DocumentType $docType,
    ): DocumentId;
}
```

- `OpenEmrSourceDocumentStorage` (production): wraps the legacy
  `library/classes/Document.class.php` `addNewDocument()` helper, which
  is OpenEMR's existing documents-table writer (matches the M2 upload
  path used by the upload hook). It is invoked through a thin adapter
  rather than reached for directly — keeps the modern code free of
  global state.
- `InMemorySourceDocumentStorage` (test): returns a sequenced
  `DocumentId` and stashes the file bytes for the loader stub. Used by
  `AttachAndExtractToolTest` and the eval adapter.

This decouples M4 from the exact name of the underlying legacy helper
(`addNewDocument` vs `Document::createDocument` etc.) and lets us defer
the legacy-shim implementation detail to the implementation phase.

Returns `AttachAndExtractResult`:
```php
final readonly class AttachAndExtractResult
{
    public function __construct(
        public bool $success,
        public ?DocumentId $documentId,                  // null only if forUploadedFile storage failed
        public ?ExtractionProviderResponse $extraction,  // null if extraction failed
        public ?ExtractionErrorCode $errorCode,          // non-null iff !success
        // Sanitized telemetry payload as a flat array<string, scalar|null>;
        // the caller may merge it into a logger context. No separate
        // ExtractionTelemetry VO (see "Cut from earlier sketch").
        public array $telemetry,
    ) {}
}
```

**Failure semantics (no rollback):**
- `forUploadedFile` storage missing-file → `(success=false,
  documentId=null, extraction=null, errorCode=MissingFile)`.
- `forUploadedFile` storage write failure → `(StorageFailure)` with
  `documentId=null`. **No partial document row** because the underlying
  storage call is atomic at the OpenEMR layer; if the write succeeded
  before the failure was raised, the OpenEMR document remains and the
  caller will see `documentId` populated and a separate extraction
  failure.
- Extraction failure after storage success → `(success=false,
  documentId=<the new id>, extraction=null, errorCode=<typed>)`. The
  OpenEMR document row is **never** rolled back (per hard constraint).
- `forExistingDocument` load failure → `(success=false,
  documentId=<the input id>, errorCode=MissingFile)`. The input
  document row is not modified.

### Wiring

- `agent-forge/scripts/process-document-jobs.php` constructs
  `IntakeExtractorWorker` with
  `ExtractionProviderFactory::create(ExtractionProviderConfig::fromEnvironment())`.
  No defaulting to live VLM in tests/evals: when
  `AGENTFORGE_OPENAI_API_KEY` is unset, the config falls back to
  fixture mode. The script also injects `OpenEmrSourceDocumentStorage`,
  `OpenEmrDocumentLoader`, and `AgentForgeClock`.
- `agent-forge/scripts/run-clinical-document-evals.php` replaces the
  `NotImplementedAdapter` with `ClinicalDocumentExtractionAdapter`. The
  adapter constructs an `AttachAndExtractTool` wired with
  `FixtureExtractionProvider` (forced via
  `AGENTFORGE_VLM_PROVIDER=fixture`), `InMemorySourceDocumentStorage`,
  and an in-memory loader that reads from
  `agent-forge/docs/example-documents/...`. **The eval adapter does
  not exercise the worker** — it goes directly through the tool, which
  matches how the supervisor will eventually call extraction in M7.
- New `SensitiveLogPolicy` ALLOWED keys (additive only):
  `extraction_provider`, `fact_count_verified`, `fact_count_document_fact`,
  `fact_count_needs_review`, `schema_valid`, `pages_rendered`,
  `http_status`.

## Test/eval strategy

Tests-first, every task. Build each layer's tests before its production
code, in this order:

1. Schema value objects (citation, bounding box, abnormal flag,
   certainty, source type, document type)
2. `LabPdfExtraction` / `IntakeFormExtraction` parsers (incl. row VOs)
3. `ExtractionErrorCode`, `ExtractionFailedException`,
   `SchemaValidationException`
4. `CertaintyClassifier` (exhaustive boundary truth table)
5. `JsonSchemaBuilder`
6. `FixtureExtractionProvider`
7. `OpenAiVlmExtractionProvider` (mocked Guzzle + mocked
   `PdfPageRenderer`; no live HTTP, no Imagick)
8. `ExtractionProviderConfig` / `ExtractionProviderFactory`
9. `SourceDocumentStorage` (in-memory impl) + `AttachAndExtractInput`
   factories
10. `AttachAndExtractTool`
11. `IntakeExtractorWorker`
12. Eval adapter + integration

### New isolated test files

Under `tests/Tests/Isolated/AgentForge/Document/`:

- `Schema/DocumentCitationTest.php` — required-field validation,
  source-type enum coercion, optional bounding-box round-trip,
  forbidden empty-string handling
- `Schema/DocumentSourceTypeTest.php` — `fromStringOrThrow` happy path
  and rejection
- `Schema/BoundingBoxTest.php` — range validation, `x+width <= 1`
  and `y+height <= 1` bounds, zero-size rejection
- `Schema/AbnormalFlagTest.php` — enum coercion (data provider)
- `Schema/CertaintyTest.php` — unit-enum identity (no string coupling)
- `Schema/LabPdfExtractionTest.php` — `fromJson` happy path, schema
  violations with `fieldPath` assertions
- `Schema/LabResultRowTest.php` — confidence range `[0.0, 1.0]`,
  abnormal-flag mapping, citation requirement
- `Schema/IntakeFormExtractionTest.php` — `fromJson` happy path,
  free-text finding handling, `documentCitation` requirement
- `Schema/IntakeFormFindingTest.php` — category enum, length cap on
  `text` triggers `SchemaValidationException` with `fieldPath`
- `AttachAndExtractInputTest.php` — both factories, input validation
  (empty path → `DomainException`)

Under `tests/Tests/Isolated/AgentForge/Document/Extraction/`:

- `ExtractionErrorCodeTest.php` — every case has a stable string value
  and is in the spec's allowed set
- `CertaintyClassifierTest.php` — exhaustive truth table from the
  CertaintyClassifier section above (data provider with the boundary
  table); covers `0.85` exact (Verified), `0.84999` (DocumentFact),
  `0.50` exact (DocumentFact), `0.49999` (NeedsReview), weak quote
  cases (digits-only, length<3), and `mapsToChartDestination=false`
  (intake `family_history` etc.)
- `FixtureExtractionProviderTest.php` — manifest lookup by sha256,
  deterministic across runs (same input → byte-identical
  `ExtractionProviderResponse`), missing-fixture →
  `ExtractionFailedException(MissingFile)`
- `OpenAiVlmExtractionProviderTest.php` — mocked Guzzle (mirroring
  `OpenAiDraftProviderTest`) plus mocked `PdfPageRenderer`; covers:
  HTTP 200 happy path, HTTP 5xx after retry exhaustion →
  `ExtractionFailure`, HTTP 401 → `ExtractionFailure` with
  `auth_failed` flag, malformed JSON content → `SchemaValidationFailure`,
  schema-mismatch parse → `SchemaValidationFailure(fieldPath: ...)`,
  deadline exceeded → `ExtractionFailure` with `latencyMs` populated,
  PNG path bypasses renderer, PDF path invokes renderer with page cap,
  unsupported mime type → `UnsupportedDocType`, page-render failure →
  `StorageFailure`
- `ExtractionProviderConfigTest.php` — env precedence: explicit
  `AGENTFORGE_VLM_PROVIDER` wins; falls back to OpenAI when API key
  set; falls back to fixture otherwise. Mirrors
  `DraftProviderConfigTest` shape
- `ExtractionProviderFactoryTest.php` — mode dispatch returns the
  expected concrete provider class for each mode
- `JsonSchemaBuilderTest.php` — `for(LabPdf)` and `for(IntakeForm)`
  produce strict-mode-compatible schema arrays (no `additionalProperties:
  true`, no missing `required` lists)
- `IntakeExtractorWorkerTest.php` — wired with `FixtureExtractionProvider`,
  a fake `AgentForgeClock`, and a `RecordingLogger` that captures every
  context payload. Asserts:
  - `ProcessingResult::succeeded()` returned (no payload checks)
  - Logged context contains the literal string `'intake-extractor'`
    under `worker`
  - Logged context contains all expected fact-count keys with correct
    integer values for the fixture case
  - Logged context never contains any FORBIDDEN_KEYS
    (`extracted_fields`, `document_text`, `document_image`, `prompts`,
    `answers`, `patient_name`, raw `quote_or_value`) — verified via
    `SensitiveLogPolicy::containsForbiddenKey` over every captured payload
  - `UnsupportedDocType` doc types return `ProcessingResult::failed`
    without invoking the provider
- `AttachAndExtractToolTest.php` — uses `InMemorySourceDocumentStorage`
  and `FixtureExtractionProvider`. Covers:
  - `forUploadedFile` happy path: storage succeeds → load succeeds →
    extraction succeeds → `success=true` with `documentId` and
    `extraction` populated
  - `forUploadedFile` missing file → `(MissingFile, documentId=null)`
  - `forUploadedFile` storage write failure → `(StorageFailure,
    documentId=null)`
  - `forUploadedFile` storage succeeds, extraction fails → `(extraction
    errorCode, documentId=<the new id>)`. **Asserts the in-memory
    storage still holds the bytes** — no rollback.
  - `forExistingDocument` happy path
  - `forExistingDocument` load fails → `(MissingFile, documentId=<input>)`

Under `tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument/`:

- `ClinicalDocumentExtractionAdapterTest.php` — adapter integration
  with the extraction system, asserting `CaseRunOutput` shape per
  representative case (one happy lab_pdf, one happy intake_form, one
  no-doc-type case from `out-of-corpus-refusal`, one schema-failure
  trap)

### Fixture extraction outputs (new directory)

`agent-forge/fixtures/clinical-document-golden/extraction/`:

- `manifest.json` — sha256-of-source-bytes → fixture filename map
- One fixture file per **distinct source document** in the case set.
  The actual case ids in `cases/` are: `chen-lab-typed`,
  `chen-lab-duplicate-upload`, `whitaker-intake-scanned`,
  `reyes-hba1c-image`, `chen-intake-typed`, `out-of-corpus-refusal`,
  `guideline-supported-ldl`, `no-phi-logging-trap`. Of these:
  - `chen-lab-typed` — `lab_pdf` (typed PDF, Verified rows)
  - `chen-lab-duplicate-upload` — same source bytes as `chen-lab-typed`,
    so the manifest sha256 entry resolves to the **same** fixture file
    (this is exactly the duplicate-detection contract M5 will rely on)
  - `whitaker-intake-scanned` — `intake_form` (scanned PDF; mix of
    `DocumentFact` and `NeedsReview`)
  - `reyes-hba1c-image` — `lab_pdf` from PNG (Verified, scanned)
  - `chen-intake-typed` — `intake_form` (mix of `Verified` and
    `DocumentFact`)
  - `out-of-corpus-refusal`, `guideline-supported-ldl`,
    `no-phi-logging-trap` — case `doc_type` is `null`; **no extraction
    is performed** by M4 (these test refusal/guideline/PHI-log paths
    that are M6/M7/H1 territory). The adapter handles these via the
    `no_extraction_required` status code below.

Each extraction fixture mirrors the value-object shape exactly so the
`FixtureExtractionProvider` reads → `LabPdfExtraction::fromJson(...)` /
`IntakeFormExtraction::fromJson(...)` → `ExtractionProviderResponse`.
The fixture-fact JSON keys use snake_case (matching the existing
`CitationShape` and rubric expectations): `source_type`, `source_id`,
`page_or_section`, `field_or_chunk_id`, `quote_or_value`,
`bounding_box: { x, y, width, height }`, `value`, `unit`,
`abnormal_flag`, `confidence`. The VO `fromJson` adapters do the
camelCase mapping internally.

### Eval runner integration

The new `ClinicalDocumentExtractionAdapter` implements
`runCase(EvalCase): CaseRunOutput`. For M4:

- Reads case JSON from `agent-forge/fixtures/clinical-document-golden/cases/`
  (after the `source_document_path` correction noted in Module Layout).
- For cases with `doc_type === null` (refusal / guideline / PHI-trap
  cases): returns `CaseRunOutput` with `status =
  'no_extraction_required'` and an empty `extraction.facts = []`.
  Existing rubrics tolerate this via `RubricStatus::NotApplicable`.
- For cases with `doc_type ∈ {lab_pdf, intake_form}`:
  1. Resolve `source_document_path` relative to the repo root.
  2. Call `AttachAndExtractTool::forUploadedFile($patientId, $absPath,
     $docType)` — exercises the spec-facing tool surface end-to-end.
  3. On success, build `CaseRunOutput` with:
     - `status = 'extraction_completed_persistence_pending'`
     - `extraction.schema_valid = true`
     - `extraction.facts` — **flat list of fact dicts** matching the
       shape consumed by `CitationPresentRubric` and
       `BoundingBoxPresentRubric` (each fact carries a snake_case
       `citation` and per-fact data; ordered as in the source VO)
     - `logLines` — populated from a `RecordingLogger` injected into
       the tool/provider, capturing every call's sanitized context
       payload as JSON-serialized strings (the `no_phi_in_logs` rubric
       greps these)
  4. On failure, `status = 'extraction_failed'` with `failureReason`
     populated from `ExtractionErrorCode->value`. `schema_valid =
     false` for `SchemaValidationFailure`; `true` otherwise.
  5. On `UnsupportedDocType`: `status = 'unsupported_doc_type'`.

The runner now reaches `baseline_met` for the eight-case MVP fixture set.
The artifact still represents fixture/memory strict extraction proof, not
OpenEMR fact persistence, lab promotion, guideline retrieval, or final-answer
chart writeback.

## Verification (definition of done for M4)

Local proof gate (existing script):
```text
agent-forge/scripts/check-clinical-document.sh
```
must pass:
- `composer phpunit-isolated` for all new test files
- `composer phpstan` (level 10) — no new baseline entries
- `composer phpcs` — no style violations
- `php agent-forge/scripts/run-clinical-document-evals.php` —
  the schema/citation/bounding-box rubrics pass for the MVP fixture cases
  (other rubrics may be `not_applicable` until M5–M7)

Acceptance criteria:
- `lab_pdf` and `intake_form` MVP fixtures produce strict cited JSON
  parsed into the typed value objects without exception.
- Every candidate fact in the in-memory result has a `DocumentCitation`
  with all five required fields. Every `Verified` fact also has a
  `BoundingBox`.
- The literal string `'intake-extractor'` (matching
  `WorkerName::IntakeExtractor->value`) is emitted in worker telemetry
  for both `lab_pdf` and `intake_form` jobs.
- The `IntakeExtractorWorker` source has a code comment noting that the
  spec requires this name and that it is broader than intake forms.
- No extraction output reaches a hypothetical persister without going
  through `LabPdfExtraction::fromJson()` / `IntakeFormExtraction::fromJson()`
  validation. (Persistence is M5.)
- `FixtureExtractionProvider` produces byte-identical
  `ExtractionProviderResponse` across runs.
- `OpenAiVlmExtractionProvider` is unit-tested via mocked Guzzle but
  never exercised live in CI; only the fixture provider runs in tests/evals.
- `SensitiveLogPolicy` rejects any attempt to log raw extracted values,
  prompts, response bodies, or document bytes — verified by a worker test
  that captures the logger and asserts FORBIDDEN_KEYS absence.

## Decisions and rationale

1. **Bounding-box source.** No PDF parser library is installed. Decision:
   the VLM provides bounding boxes at runtime; the fixture provider
   supplies deterministic boxes for tests/evals. No new library in M4.
2. **Bounding-box coordinate shape.** Existing `CitationShape`
   validates `{x, y, width, height}`. Decision: M4 aligns with the
   existing validator; the architecture-doc `[x0, y0, x1, y1]` form is
   informational. Will be flagged in MEMORY.md.
3. **Fixture extraction output location.** Decision: a new
   `agent-forge/fixtures/clinical-document-golden/extraction/` directory
   with a `manifest.json` mapping `sha256(source_bytes) → fixture
   filename`. Avoids coupling to OpenEMR document ids.
4. **`ProcessingResult` API.** Verified against
   `ProcessingResult.php:27`: only `succeeded()` and `failed($code,
   $msg)` exist; there is no `completed()` factory. Decision: M4 worker
   returns `ProcessingResult::succeeded()` (no payload) and emits all
   extraction telemetry through the logger.
5. **Clock abstraction.** Decision: `OpenEMR\AgentForge\AgentForgeClock`
   (already used by `DocumentJobWorker`); PSR-20 `ClockInterface` is
   not adopted in this slice. `Deadline` is constructed from the clock
   and a `budgetMs` constructor parameter on the worker.
6. **Env-var naming.** W2_ARCHITECTURE.md mandates
   `AGENTFORGE_VLM_PROVIDER` and `AGENTFORGE_VLM_MODEL`. Decision:
   adopt those exact names; pricing/timeout knobs use the same
   `AGENTFORGE_VLM_*` prefix.
7. **PDF input handling.** `gpt-4o` Chat Completions does not accept
   raw PDFs. Decision: provider injects a `PdfPageRenderer` wrapper
   over `Imagick` and renders up to `AGENTFORGE_VLM_MAX_PAGES` (default
   5) pages as PNG `image_url` blocks. The renderer is mocked in unit
   tests so test code never touches `ext-imagick`.
8. **`SourceDocumentStorage` seam.** OpenEMR has no modern `DocumentService`
   surface for the M2 upload path; storage uses legacy
   `library/classes/Document.class.php`. Decision: introduce
   `SourceDocumentStorage` interface with `OpenEmrSourceDocumentStorage`
   (production wrapper) and `InMemorySourceDocumentStorage` (test/eval).
   Keeps modern code free of global state and shims the legacy helper
   name.
9. **`ExtractedFact` / `ExtractionTelemetry` / `SourceDocument` /
   `DisabledExtractionProvider`.** Cut from the module layout per
   SOLID/scope audit. Worker tallies counts directly; telemetry is a
   sanitized array; `DocumentLoadResult` is passed through as-is;
   fixture mode is the no-op.
10. **`ExtractionErrorCode` membership.** Decision: M4 enum contains
    only the codes M4 source code actually emits
    (`unsupported_doc_type`, `missing_file`, `storage_failure`,
    `extraction_failure`, `schema_validation_failure`).
    `persistence_failure`, `duplicate_detected`, `provider_disabled`
    are deferred to the epics that emit them.
11. **`CertaintyClassifier` boundary semantics.** Locked: Verified iff
    `confidence >= 0.85` AND `mapsToChartDestination` AND quote is
    "strong" (`strlen(trim) >= 3` AND not pure digits). DocumentFact
    iff `0.50 <= confidence < 0.85` OR no chart mapping. NeedsReview
    otherwise.
12. **`AttachAndExtractTool::forUploadedFile()` activation in M4.**
    Decision: **activate in M4**. The eval adapter calls
    `forUploadedFile()` against `agent-forge/docs/example-documents/...`
    for every M4-eligible case, exercising the storage-then-extract
    path end-to-end. `InMemorySourceDocumentStorage` is wired into the
    eval adapter. `OpenEmrSourceDocumentStorage` is the production
    direct-file storage adapter, but the queued worker path remains
    `DocumentJobWorker` → `OpenEmrDocumentLoader` → `IntakeExtractorWorker`.
    `forExistingDocument()` is a direct synchronous tool path and does not
    enqueue jobs or update worker status.
13. **`CertaintyClassifier` configurability.** Decision:
    `CertaintyClassifier::__construct(float $verifiedThreshold = 0.85,
    float $documentFactThreshold = 0.50)` stays env-free and validates
    `0 < documentFactThreshold < verifiedThreshold <= 1`. M4 wires the
    default thresholds; env-var threshold overrides are deferred until a
    project-wide env helper exists for this slice.
14. **`MEMORY.md` update timing.** Decision: **same M4 commit**. The
    eval caveat shift (`extraction_not_implemented` →
    `persistence_not_implemented` / `guideline_retrieval_not_implemented`)
    and the bounding-box-shape delta noted in §"Bounding-box coordinate
    shape" are both written to `agent-forge/docs/MEMORY.md` as part of
    M4's final commit, alongside any code touched in that commit.

## M4 implementation proof notes

Last verified: 2026-05-06.

- Host re-run (same day): `agent-forge/scripts/check-clinical-document.sh`
  passed end-to-end: 481 AgentForge isolated tests, 2297 assertions (1 skipped), clinical eval
  `baseline_met` with artifacts under
  `agent-forge/eval-results/clinical-document-20260506-012908/`, focused PHPStan
  clean, PHPCS on changed AgentForge paths, final line `PASS clinical document eval gate.`
- Optional temp-results eval: `AGENTFORGE_CLINICAL_DOCUMENT_EVAL_RESULTS_DIR` set to
  a fresh `mktemp -d` directory, `php agent-forge/scripts/run-clinical-document-evals.php`
  reported `baseline_met`, exit code `0` (2026-05-06 host); artifacts under
  `/var/folders/vq/4drfx8g53yx1wpb4_vyfk_f80000gn/T/tmp.K8urMZPvSS/clinical-document-20260506-011913/`
  only (not committed).
- Docker `development-easy` manual proof (2026-05-06): `docker compose up --detach --wait`
  healthy; core UI login and patient **900001** Documents flow; **re-upload** of an
  existing lab PDF without deleting first — `POST .../controller.php?document&upload...`
  returned **200** (upload path non-blocking). `agentforge-worker` log line for a
  processed job showed sanitized `clinical_document.worker.job_failed` context including
  `patient_ref` (no raw chart bytes). SQL tail of `clinical_document_processing_jobs` showed
  job **13** `failed` / `missing_file` with message prefix matching golden lipid SHA256:
  with `AGENTFORGE_VLM_PROVIDER=fixture`, the worker had **no** `AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST`
  default, so `FixtureExtractionProvider` used an **empty** manifest and could not resolve
  any fixture file (even when bytes match the golden corpus). **Compose fix:** `docker/development-easy/docker-compose.yml`
  now defaults `AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST` to the repo
  `agent-forge/fixtures/clinical-document-golden/extraction/manifest.json` path inside the
  app container; **recreate** `agentforge-worker` (or full stack) to pick it up. Only if the
  latest job is still **not** `succeeded`, enqueue **one** new job (upload once from the golden
  PDF path below). OpenEMR
  access logs during the session included unrelated noise (e.g. `background_service/$run` 500
  from Symfony process spawn) — not attributed to document extraction.
- Docker fixture-mode success proof after the Compose manifest and worker telemetry fixes
  (2026-05-06): recreated `agentforge-worker`, uploaded the verified golden PDF
  (`sha256=c387cf7d5e4a1f7e5cf8eeab604d808f004ba42d1063f83653a36a773f606a5d`)
  once for patient `900001`, and verified newest job **16**:
  `status=succeeded`, `doc_type=lab_pdf`, `error_code=NULL`, empty error message.
  Worker logs showed sanitized `OpenEMR.INFO` events:
  `document.extraction.completed` and `clinical_document.worker.job_completed`,
  both with `worker=intake-extractor`, `patient_ref`, `document_id=9`, and
  `status=succeeded`; no raw document bytes or extracted values were present.

### Procedure: prove a green `succeeded` job in `docker/development-easy` (fixture mode)

Run commands from **anywhere inside your git clone** (so `git rev-parse` works). Do **not** use a bare `$REPO` variable unless you set it yourself — an unset `REPO` breaks paths silently.

**Stop condition:** after step 5, if the newest row is `status=succeeded`, you are **done**. Do **not** upload again; extra uploads only add duplicate chart rows.

1. **Recreate the worker container** so it picks up `docker/development-easy/docker-compose.yml` defaults (including `AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST`). If you use `docker/development-easy/.env`, ensure you did not set `AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST=` to an empty value (see `agent-forge/.env.sample`).

   ```bash
   cd "$(git rev-parse --show-toplevel)/docker/development-easy"
   docker compose up -d --force-recreate agentforge-worker
   ```

2. **Confirm the golden PDF exists** (bytes must match manifest SHA256 `c387cf7d5e4a1f7e5cf8eeab604d808f004ba42d1063f83653a36a773f606a5d` — use this exact file, not a re-saved copy):

   ```bash
   test -f "$(git rev-parse --show-toplevel)/agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf" && echo OK
   ```

3. **Browser:** open `http://localhost/` (or your mapped OpenEMR URL) → log in as `admin` / `pass` → open patient **900001** (or any patient) → **Documents** → **Upload** → choose **exactly** the file from step 2 (Finder: open the repo folder and drill to `agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf`).

   Complete the upload wizard until you return to the document list with **HTTP 200** (no 500).

4. **Wait up to 60 seconds** for the worker poll (`AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS` default 5).

5. **Read the newest job row** (replace password if you changed it):

   ```bash
   cd "$(git rev-parse --show-toplevel)/docker/development-easy"
   docker compose exec mysql mariadb -uopenemr -popenemr openemr -e \
     "SELECT id, status, doc_type, error_code, LEFT(COALESCE(error_message,''),120) AS err FROM clinical_document_processing_jobs ORDER BY id DESC LIMIT 3;"
   ```

   **Pass:** top row shows `status=succeeded` and `error_code` / `err` are NULL or empty for that row.

6. **Confirm worker telemetry** (optional):

   ```bash
   cd "$(git rev-parse --show-toplevel)/docker/development-easy"
   docker compose logs agentforge-worker --tail 200 | grep document.extraction.completed
   ```

   **Pass:** at least one line contains `document.extraction.completed` with JSON context including `"worker":"intake-extractor"` and `"status":"succeeded"` (no raw document bytes).

If step 5 still shows `missing_file` with a full SHA256 that is **not** `c387cf7d5e4a1f7e5cf8eeab604d808f004ba42d1063f83653a36a773f606a5d`, the stored document bytes differ from the golden file (re-export, editor, or OpenEMR transform) — upload again from the verified path in step 2 only.

# Epic M2 — Schema Migration, Upload Eligibility, And Job Enqueue

## Context

Epic M1 closed: failing eval rubric scaffolding, the
`run-clinical-document-evals.php` runner, and `check-clinical-document.sh`
are committed. M2 is the first epic that adds runtime behavior to the
existing OpenEMR upload path.

The goal of M2 is narrow and load-bearing: when a user uploads a document
through normal OpenEMR UI to a category mapped to a Week 2 doc type
(`lab_pdf` or `intake_form`), AgentForge must record a `pending`
extraction job in a new table. M2 stops there — no extraction, no worker,
no schemas (those are M3, M4). M2 only makes the eligibility decision and
the enqueue idempotent and observable.

The core engineering risk is: the change touches `library/ajax/upload.php`,
which is on the user's upload critical path. A bug here can break uploads.
The mitigation is to wrap the hook in a `try/catch \Throwable` so that
even if the AgentForge schema is missing, an exception escapes the SQL
layer, or the enqueuer misbehaves, the OpenEMR upload still succeeds
exactly as before.

## Goals And Non-Goals

In scope:

- New tables `clinical_document_type_mappings` and `clinical_document_processing_jobs`.
- `sql/database.sql`, `sql/8_1_0-to-8_1_1_upgrade.sql`, and `version.php` updates.
- Seed entries in `agent-forge/sql/seed-demo-data.sql` for two demo categories
  and their mappings.
- New PHP module `src/AgentForge/Document/` containing:
  - Value objects: `DocumentType` (enum), `JobStatus` (enum), `DocumentId`,
    `CategoryId`, `DocumentJobId`.
  - DTOs: `DocumentTypeMapping`, `DocumentJob`.
  - Repository interfaces and SQL implementations:
    `DocumentTypeMappingRepository` / `SqlDocumentTypeMappingRepository`,
    `DocumentJobRepository` / `SqlDocumentJobRepository`.
  - Service: `DocumentUploadEnqueuer`.
  - Wiring helper: `DocumentUploadEnqueuerFactory` (static `createDefault()`).
- Hook in `library/ajax/upload.php` at both call sites of `addNewDocument(...)`
  — core path (line ~128) and portal path (lines ~90-102).
- `SensitiveLogPolicy::ALLOWED_KEYS` extended for the new W2 telemetry fields.
- Isolated tests under `tests/Tests/Isolated/AgentForge/Document/` written
  before production code.

Out of scope (later epics):

- The worker process itself, `agentforge_worker_heartbeats`, and
  `process-document-jobs.php` (M3).
- Extraction tool, providers, schemas, citations (M4).
- Fact persistence, lab promotion, embeddings, document search (M5).
- Guideline corpus and retrieval (M6).
- Supervisor and final-answer separation (M7).

## Critical Files

Modify:

- `library/ajax/upload.php` — capture `addNewDocument(...)` return, call enqueuer,
  guard with `try/catch \Throwable`. Apply at both core and portal call sites.
- `sql/database.sql` — append two CREATE TABLE statements at the AgentForge area
  (or at end with a section header).
- `sql/8_1_0-to-8_1_1_upgrade.sql` — append idempotent migration directives
  (`#IfNotTable`, `#IfMissingColumn`, `#IfNotRow`).
- `version.php` — bump `$v_database` from `538` to `539`.
- `agent-forge/sql/seed-demo-data.sql` — idempotent seeds for the two demo
  categories and the two mappings.
- `src/AgentForge/Observability/SensitiveLogPolicy.php` — add
  `patient_ref`, `document_id`, `doc_type`, `category_id`, `job_id`, `worker`,
  `attempts`, `error_code` to `ALLOWED_KEYS`. Confirm forbidden keys still
  block raw quote/value, prompts, answers.

Add:

- `src/AgentForge/Document/DocumentType.php` (backed enum).
- `src/AgentForge/Document/JobStatus.php` (backed enum).
- `src/AgentForge/Document/DocumentId.php` (readonly value object).
- `src/AgentForge/Document/CategoryId.php` (readonly value object).
- `src/AgentForge/Document/DocumentJobId.php` (readonly value object).
- `src/AgentForge/Document/DocumentTypeMapping.php` (readonly DTO).
- `src/AgentForge/Document/DocumentJob.php` (readonly DTO).
- `src/AgentForge/Document/DocumentTypeMappingRepository.php` (interface).
- `src/AgentForge/Document/SqlDocumentTypeMappingRepository.php`.
- `src/AgentForge/Document/DocumentJobRepository.php` (interface).
- `src/AgentForge/Document/SqlDocumentJobRepository.php`.
- `src/AgentForge/Document/DocumentUploadEnqueuer.php`.
- `src/AgentForge/Document/DocumentUploadEnqueuerFactory.php` (static
  `createDefault()`).
- Test files under `tests/Tests/Isolated/AgentForge/Document/` (see Tests).

Reused (do NOT duplicate):

- `src/AgentForge/Auth/PatientId.php` — existing readonly `int > 0` value object.
- `src/AgentForge/Observability/SensitiveLogPolicy.php` — existing allowlist
  mechanism.
- `src/AgentForge/Observability/PsrRequestLogger.php` (or current PSR-3 wrapper)
  — existing sanitized logger.
- OpenEMR DBAL/ADODB connection accessor used by other `Sql*Repository` classes
  (e.g., the same accessor used in `src/AgentForge/Evidence/SqlChartEvidenceRepository.php`)
  — match its style for consistency.

## Schema

### `clinical_document_type_mappings`

```sql
CREATE TABLE `clinical_document_type_mappings` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `category_id` INT          NOT NULL,
  `doc_type`    VARCHAR(32)  NOT NULL,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_type_mapping` (`category_id`),
  KEY `idx_clinical_document_type_active` (`active`, `category_id`)
) ENGINE=InnoDB;
```

Notes:

- `doc_type` is constrained at the application layer to the `DocumentType`
  enum cases — no DB-side ENUM so adding doc types in later epics doesn't
  require a migration.
- `category_id` is unique so a category maps to exactly one clinical document
  type in M2. Allowing two active doc types for one OpenEMR category would make
  enqueue behavior depend on insertion order, which is not acceptable for a
  clinical workflow switch.

### `clinical_document_processing_jobs`

```sql
CREATE TABLE `clinical_document_processing_jobs` (
  `id`            BIGINT       NOT NULL AUTO_INCREMENT,
  `patient_id`    INT          NOT NULL,
  `document_id`   INT          NOT NULL,
  `doc_type`      VARCHAR(32)  NOT NULL,
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'pending',
  `attempts`      INT          NOT NULL DEFAULT 0,
  `lock_token`    VARCHAR(64)      NULL,
  `created_at`    DATETIME     NOT NULL,
  `started_at`    DATETIME         NULL,
  `finished_at`   DATETIME         NULL,
  `error_code`    VARCHAR(64)      NULL,
  `error_message` TEXT             NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_processing_job` (`patient_id`, `document_id`, `doc_type`),
  KEY `idx_clinical_document_processing_status_created` (`status`, `created_at`)
) ENGINE=InnoDB;
```

Notes:

- `lock_token` is added now even though M3 will be the first user — adding
  it here avoids a second migration in the same upgrade window.
- Status values are the five named in PLAN-W2: `pending`, `running`,
  `succeeded`, `failed`, `retracted`. Enforced at the application layer
  (`JobStatus` enum), not via DB ENUM.
- `attempts` defaults to 0; M3 increments on each claim.
- ID column types match OpenEMR convention (`int(11)` for patient/document
  ids; `BIGINT` for the job table because jobs can outgrow document/patient
  cardinality over time).

### Migration directives (`sql/8_1_0-to-8_1_1_upgrade.sql`)

Append at the end using OpenEMR's existing migration syntax:

```text
#IfNotTable clinical_document_type_mappings
CREATE TABLE `clinical_document_type_mappings` ( ... ) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_document_processing_jobs
CREATE TABLE `clinical_document_processing_jobs` ( ... ) ENGINE=InnoDB;
#EndIf
```

### `version.php` bump

Change `$v_database` from `538` to `539`. No other version fields change.

### Seed (`agent-forge/sql/seed-demo-data.sql`)

Idempotent insert pattern for existing OpenEMR categories and their mappings.
Sketch:

```sql
SET @lab_pdf_cat_id := (SELECT id FROM categories WHERE name = 'Lab Report' LIMIT 1);

INSERT INTO clinical_document_type_mappings (category_id, doc_type, active, created_at)
SELECT @lab_pdf_cat_id, 'lab_pdf', 1, NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM clinical_document_type_mappings
  WHERE category_id = @lab_pdf_cat_id AND doc_type = 'lab_pdf'
);

-- intake_form is supported by code but intentionally not seeded until a real
-- OpenEMR intake workflow category is chosen.
```

Notes:

- The pattern is idempotent: re-running the seed does not duplicate rows.
- The seed must not create visible implementation-branded document categories.

## Module Design (`src/AgentForge/Document/`)

### Value objects (readonly, mirror existing PatientId pattern)

```php
final readonly class DocumentId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new \DomainException('DocumentId must be positive');
        }
    }
}

final readonly class CategoryId { /* same shape */ }
final readonly class DocumentJobId { /* same shape */ }
```

### Enums (backed, persisted/serialized)

```php
enum DocumentType: string
{
    case LabPdf      = 'lab_pdf';
    case IntakeForm  = 'intake_form';

    public static function fromStringOrThrow(string $raw): self
    {
        return self::tryFrom($raw)
            ?? throw new \DomainException("Unknown doc_type: {$raw}");
    }
}

enum JobStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Succeeded = 'succeeded';
    case Failed    = 'failed';
    case Retracted = 'retracted';
}
```

### DTOs (readonly)

```php
final readonly class DocumentTypeMapping
{
    public function __construct(
        public ?int $id,
        public CategoryId $categoryId,
        public DocumentType $docType,
        public bool $active,
        public \DateTimeImmutable $createdAt,
    ) {}
}

final readonly class DocumentJob
{
    public function __construct(
        public ?DocumentJobId $id,
        public PatientId $patientId,
        public DocumentId $documentId,
        public DocumentType $docType,
        public JobStatus $status,
        public int $attempts,
        public ?string $lockToken,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}
}
```

### Repository interfaces

```php
interface DocumentTypeMappingRepository
{
    /** Returns the active mapping for this category, or null. */
    public function findActiveByCategoryId(CategoryId $categoryId): ?DocumentTypeMapping;
}

interface DocumentJobRepository
{
    /**
     * Insert a `pending` job idempotently. If a job already exists for
     * (patient_id, document_id, doc_type), returns the existing job id.
     */
    public function enqueue(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentType $docType,
    ): DocumentJobId;

    public function findById(DocumentJobId $id): ?DocumentJob;

    public function findOneByUniqueKey(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentType $docType,
    ): ?DocumentJob;
}
```

### SQL implementations

`SqlDocumentTypeMappingRepository`:

- Queries `SELECT id, category_id, doc_type, active, created_at
  FROM clinical_document_type_mappings
  WHERE category_id = ? AND active = 1
  LIMIT 1`.
- Hydrates a `DocumentTypeMapping` (skipping unknown `doc_type` strings → log
  and return null; the application enum is the source of truth).

`SqlDocumentJobRepository`:

- `enqueue(...)`:
  - `INSERT INTO clinical_document_processing_jobs (...) VALUES (...)` with
    `created_at = NOW()`, `status = 'pending'`, `attempts = 0`.
  - On duplicate-key (the unique constraint), do a `SELECT id` for the
    existing row and return its id. Two acceptable implementations:
    1. `INSERT IGNORE` then `SELECT id`. Returns existing id either way.
    2. `INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` and
       read `LAST_INSERT_ID()`.
  - Choose option 1 for clarity unless option 2 simplifies an existing helper.
- `findById(...)` and `findOneByUniqueKey(...)` are thin SELECTs.

### Enqueuer service

```php
final class DocumentUploadEnqueuer
{
    public function __construct(
        private readonly DocumentTypeMappingRepository $mappings,
        private readonly DocumentJobRepository $jobs,
        private readonly LoggerInterface $logger,
        private readonly PatientRefHasher $patientRefHasher,
    ) {}

    public function enqueueIfEligible(
        PatientId $patientId,
        DocumentId $documentId,
        CategoryId $categoryId,
    ): ?DocumentJobId {
        try {
            $mapping = $this->mappings->findActiveByCategoryId($categoryId);
            if ($mapping === null) {
                return null;
            }

            $jobId = $this->jobs->enqueue($patientId, $documentId, $mapping->docType);

            $this->logger->info('clinical_document.job.enqueued', [
                'patient_ref' => $this->patientRefHasher->hash($patientId),
                'document_id' => $documentId->value,
                'category_id' => $categoryId->value,
                'doc_type'    => $mapping->docType->value,
                'job_id'      => $jobId->value,
            ]);

            return $jobId;
        } catch (\Throwable $e) {
            $this->logger->error('clinical_document.job.enqueue_failed', [
                'document_id' => $documentId->value,
                'category_id' => $categoryId->value,
                'error_code'  => $e::class,
            ]);
            return null;
        }
    }
}
```

Notes:

- `PatientRefHasher` is the small helper that turns `PatientId` into the
  short HMAC `patient:<hash>` form described in the architecture
  observability section. If a hasher already exists in
  `src/AgentForge/Observability/`, reuse it; otherwise add a tiny one in
  this epic since the M2 telemetry needs it. (Verify during implementation;
  add only if not already present — DRY.)
- The enqueuer never throws. It logs sanitized failures and returns `null`.
  This keeps the upload path safe.

### Wiring helper (static factory)

```php
final class DocumentUploadEnqueuerFactory
{
    public static function createDefault(): DocumentUploadEnqueuer
    {
        $db     = /* OpenEMR DB connection accessor used by other Sql* repos */;
        $logger = /* sanitized PSR-3 logger from existing AgentForge wiring */;

        return new DocumentUploadEnqueuer(
            mappings:          new SqlDocumentTypeMappingRepository($db),
            jobs:              new SqlDocumentJobRepository($db),
            logger:            $logger,
            patientRefHasher:  /* existing or new tiny helper */,
        );
    }
}
```

The factory is a thin wiring shim. It is not unit-tested. The enqueuer
behind it is fully unit-tested with mock repositories and a spy logger.

## Hook In `C_Document::upload_action_process()`

`C_Document::upload_action_process()` is the single integration point for M2.
It dispatches only after `Document::createDocument(...)` succeeds and a
document id exists. `addNewDocument(...)` already calls this controller method,
so the AJAX upload wrapper must not dispatch again from portal or core outer
call sites.

The intended shape is:

```php
if ($rc) {
    $error .= $rc . "\n";
} else {
    $this->assign("upload_success", "true");
    DocumentUploadEnqueuerHook::dispatch($patient_id, $category_id, ['doc_id' => $d->get_id()]);
}
```

### `DocumentUploadEnqueuerHook::dispatch` helper

A small static method that:

1. Returns immediately if `$result` is not an array or `doc_id` is missing
   (the OpenEMR upload failed; nothing to enqueue).
2. Constructs `PatientId`, `DocumentId`, `CategoryId` value objects;
   if any value object's domain validation throws, catches and logs.
3. Calls `DocumentUploadEnqueuerFactory::createDefault()->enqueueIfEligible(...)`.
4. Wraps the entire body in `try { ... } catch (\Throwable $e) { error_log(...) }`.

The reason for a `Hook::dispatch` helper instead of inlining: the same
invocation appears at two sites in `upload.php`. Defining it once keeps
the two hook points in sync and reduces copy-paste in the procedural
entry point. This is the smallest amount of indirection that satisfies
DRY without overengineering.

## Logging

Extend `SensitiveLogPolicy::ALLOWED_KEYS` to add the new W2 telemetry keys:

- `patient_ref` (the hashed form — never raw `patient_id`)
- `document_id`
- `category_id`
- `doc_type`
- `job_id`
- `worker` (used by M3 onward, included now to avoid a second migration of
  the allowlist in the same epic window)
- `attempts`
- `error_code`

Confirm `FORBIDDEN_KEYS` still blocks raw quote/value, prompts, answers,
patient_name, chart_text. No relaxation of forbidden keys.

The enqueuer uses `patient_ref` (hashed). It does not log raw `patient_id`.

## Tests (Written Before Production Code)

Location: `tests/Tests/Isolated/AgentForge/Document/`.

All tests are isolated PHPUnit (no DB, no Docker) and use mock/stub
collaborators. Pattern follows existing `tests/Tests/Isolated/AgentForge/`
suites.

### `DocumentTypeTest`

- Asserts exactly two cases exist: `LabPdf` (value `lab_pdf`),
  `IntakeForm` (value `intake_form`).
- `fromStringOrThrow('lab_pdf')` and `fromStringOrThrow('intake_form')`
  return the correct cases.
- `fromStringOrThrow('referral_fax')` throws `\DomainException`.

### `JobStatusTest`

- Asserts exactly five cases exist with the spec values.

### `DocumentIdTest`, `CategoryIdTest`, `DocumentJobIdTest`

- Construct with positive int — succeeds, exposes `value`.
- Construct with `0` or negative — throws `\DomainException`.

### `DocumentTypeMappingRepositoryStubTest` (interface contract)

A reusable stub repository (`InMemoryDocumentTypeMappingRepository`) is added
under tests/ for use in the enqueuer test. Its tiny test asserts that
`findActiveByCategoryId` honors the `active` flag.

### `DocumentJobRepositoryStubTest` (interface contract)

A reusable stub repository (`InMemoryDocumentJobRepository`) is added under
tests/. Its tiny test asserts `enqueue(...)` is idempotent on
(patient_id, document_id, doc_type) — repeated calls return the same
`DocumentJobId`.

### `DocumentUploadEnqueuerTest`

Uses the in-memory stubs plus a recording logger. Cases:

1. **Mapped active category → one pending job created.** Stub mapping is
   active; enqueue returns a non-null `DocumentJobId`; stub job repo has
   exactly one job with `status = pending`, `attempts = 0`,
   `lock_token = null`. Logger received `clinical_document.job.enqueued`
   exactly once.

2. **Mapped inactive category → no enqueue.** Stub returns null on inactive
   mapping; result is `null`; stub job repo has zero jobs. Logger received
   no enqueue event.

3. **Unmapped category → no enqueue.** Stub returns null; result is `null`;
   zero jobs. Logger received no enqueue event.

4. **Duplicate enqueue (same patient + doc + type) → idempotent.** Two calls
   to `enqueueIfEligible(...)` produce the same `DocumentJobId`; stub job
   repo still has one job. Logger received the enqueue event twice (one
   per call) — both with the same `job_id`.

5. **Mapping repo throws → caught and logged, returns null.** Stub mapping
   repo throws `\RuntimeException`; result is `null`; zero jobs; logger
   received `clinical_document.job.enqueue_failed` once with `error_code`
   = the exception class. No exception escapes `enqueueIfEligible`.

6. **Job repo throws → caught and logged, returns null.** Same shape as 5
   but the job repo throws.

7. **Logger context contains only allowlisted keys.** The recording logger's
   captured context is filtered through `SensitiveLogPolicy` and asserted to
   equal the input context — proving every key is on the allowlist.

8. **`patient_ref` is hashed, not raw.** The captured `patient_ref` value
   matches `PatientRefHasher::hash($patientId)` and is not equal to the
   raw `patient_id` integer.

### `DocumentUploadEnqueuerHookTest`

The `Hook::dispatch` helper is harder to unit-test because it constructs the
default factory internally. Two options, choose at implementation time:

- **A (preferred):** Make `Hook::dispatch` parameterized on an
  enqueuer-resolver callable, defaulting to `[DocumentUploadEnqueuerFactory::class, 'createDefault']`.
  Tests substitute a no-op resolver or a recording resolver. Asserts:
  non-array `$result` returns without calling resolver; missing `doc_id`
  returns without calling resolver; valid `$result` calls the resolver
  and dispatches with correct value objects; resolver throwing is caught.

- **B:** Skip Hook unit tests; cover via the enqueuer tests + the eval-level
  scenario (M2 has no full eval scenario yet, so prefer option A).

Choose option A.

### SQL repository tests

`SqlDocumentTypeMappingRepository` and `SqlDocumentJobRepository` are
covered by:

- A small construction test (no DB) verifying the class accepts a connection
  argument.
- An integration check via the manual smoke verification (Verification
  section). Full DB-backed unit tests for SQL repos are deferred to M3 when
  the worker exercises the same repo path heavily, at which point a fixture
  pattern can be introduced.

This deliberately matches the existing AgentForge isolated-test convention
(no DB fixtures in isolated tests).

## Engineering Approach (SOLID, DRY, Modular)

### SOLID

- **SRP:** Each repository owns one table. Each value object owns one
  invariant. The enqueuer owns one decision (eligible? enqueue idempotently).
  The factory owns wiring.
- **OCP:** Repository interfaces let new implementations slot in (e.g., an
  in-memory test stub) without touching the enqueuer.
- **LSP:** Stubs and SQL impls satisfy the same interface contract; the
  enqueuer test passes against either.
- **ISP:** Two small repository interfaces, not a single god interface that
  knows about both mappings and jobs.
- **DIP:** Enqueuer depends on interfaces, not SQL implementations. The
  procedural `upload.php` depends on the factory + Hook helper, never on
  SQL classes directly.

### DRY

- Reuse `PatientId` from `Auth/`. Do not redefine.
- New ID value objects share a common shape with `PatientId` but each is its
  own type — type-safety beats one shared base class.
- The hook helper avoids duplicating dispatch logic at two `upload.php`
  call sites.
- Migration directives reuse OpenEMR's `#IfNotTable` pattern.
- Sanitized logging extends the existing `SensitiveLogPolicy` allowlist
  rather than introducing a parallel logger.

### Modularity

- The `src/AgentForge/Document/` namespace is self-contained for M2 and is
  the same module that M3, M4, M5 will extend. M3 adds extraction-job
  worker code in this same module without circular dependencies.
- Tests mirror the namespace under `tests/Tests/Isolated/AgentForge/Document/`.
- Procedural `upload.php` only sees a single `Hook::dispatch` and a single
  factory class — small, contained surface area in the legacy path.

## Acceptance Criteria

1. Normal OpenEMR upload still creates a `documents` row first; user-facing
   upload behavior is unchanged.
2. Mapped active category creates exactly one pending row in
   `clinical_document_processing_jobs` with the correct `doc_type`.
3. Unmapped categories create no row in `clinical_document_processing_jobs`.
4. Inactive mappings create no row.
5. Duplicate uploads of the same `(patient_id, document_id, doc_type)` do
   not create duplicate jobs (DB unique constraint enforces).
6. Enqueue failures (DB error, missing table, unknown enum, anything) do
   not fail or slow the OpenEMR upload — caught at the Hook boundary and
   sanitized-logged.
7. Both core (line ~128) and portal (line ~90-102) call sites of
   `addNewDocument(...)` invoke the same dispatcher.
8. `sql/database.sql` and `sql/8_1_0-to-8_1_1_upgrade.sql` produce the
   same final schema on fresh install vs upgrade.
9. `version.php` is bumped to `539`.
10. `agent-forge/sql/seed-demo-data.sql` is idempotent and produces the two
    seeded categories and two active mappings.
11. `SensitiveLogPolicy::ALLOWED_KEYS` extended; sanitization tests pass.
12. All isolated tests pass: `composer phpunit-isolated -- --filter
    'OpenEMR\\Tests\\Isolated\\AgentForge\\Document'`.
13. `composer phpstan` introduces no new baseline entries for the changed
    files.
14. `agent-forge/scripts/check-clinical-document.sh` runs end to end.
    Rubric-level eval failures from M1 are unchanged at this point — M2
    does not unblock the rubric gate.

## Definition Of Done

- All tests in the test list above are committed before the production
  classes they verify.
- All files in the Critical Files list are created or modified.
- Manual end-to-end smoke (Verification section) passes locally.
- A short note is added to `agent-forge/docs/week2/PLAN-W2.md` Epic M2
  status: `Completed`. (No structural change to PLAN-W2 — only the status
  line.)
- No new PHPStan baseline entries for the touched files.
- No raw PHI in any log line emitted during smoke.

## Verification Plan

End-to-end. Run after implementation, before declaring the epic complete.

### Schema migration on fresh install

```bash
cd docker/development-easy
docker compose down -v
docker compose up --detach --wait
```

Then:

```bash
docker compose exec mysql mysql -uroot -proot openemr \
  -e "SHOW TABLES LIKE 'agentforge_%';"
```

Expect rows for `clinical_document_type_mappings` and
`clinical_document_processing_jobs`.

### Schema migration on existing install

With a pre-existing DB at `v_database = 538`, run the OpenEMR upgrade flow
(via `setup.php` or `sql_upgrade.php`). Repeat the `SHOW TABLES` check;
expect the same two tables now exist.

### Seed

```bash
docker compose exec openemr mysql -uroot -proot openemr \
  < /var/www/localhost/htdocs/openemr/agent-forge/sql/seed-demo-data.sql
```

Then:

```bash
docker compose exec openemr mysql -uroot -proot openemr \
  -e "SELECT COUNT(*) AS branded_categories FROM categories WHERE name LIKE 'AgentForge%';"
docker compose exec openemr mysql -uroot -proot openemr \
  -e "SELECT c.name, m.doc_type, m.active FROM clinical_document_type_mappings m JOIN categories c ON c.id = m.category_id;"
```

Expect zero branded categories and one active `Lab Report -> lab_pdf` mapping.
Re-run the seed; counts do not change (idempotency).

### Hook (manual UI check)

1. Log into OpenEMR at `http://localhost:8300/` as `admin / pass`.
2. Open a demo patient chart.
3. Documents → upload a small PDF to category `Lab Report`.
4. SQL: `SELECT id, patient_id, document_id, doc_type, status FROM
   clinical_document_processing_jobs;` — expect exactly one row, `status='pending'`.
5. Upload the same PDF again to the same category — verify still one job
   row (idempotency by unique constraint).
6. Upload a PDF to an unmapped category (for example, `Medical Record`) —
   verify still one job row (no new enqueue).
7. Intake form upload mapping is intentionally not manually smoked in M2 because
   no real intake workflow category has been selected yet.

### Failure injection

1. `DROP TABLE clinical_document_processing_jobs;`
2. Upload a PDF to `Lab Report`.
3. Verify the upload still succeeds (file appears in chart, no error to
   user).
4. Verify the AgentForge log/error log contains a sanitized
   `clinical_document.job.enqueue_failed` entry with `error_code` set
   and no patient name, no document text, no PHI.
5. Re-create the table and verify behavior returns to normal.

### Tests

```bash
composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge\\Document'
composer phpstan
agent-forge/scripts/check-clinical-document.sh
```

The first two should be green. The third runs end to end; rubric-level
eval failures from M1 remain (expected at this stage).

## Risks And Tradeoffs

- **Multi-file uploads.** `C_Document::upload_action_process()` iterates
  `$_FILES['file']['name']` internally, but `addNewDocument(...)` returns
  the template var `"file"` which captures the LAST file processed.
  Mitigation: the unique constraint protects against duplicate jobs even
  if only the last doc id is enqueued for a multi-file upload. Earlier
  files in a multi-file upload may not be enqueued at the time of upload;
  M3 can introduce a sweep job that scans recent `documents` rows in
  mapped categories without an `clinical_document_processing_jobs` row and back-fills
  if this turns out to matter. Document this as known M2 behavior and
  revisit only if it shows up in eval cases.

- **Hook executes on every core/portal upload.** Even ineligible categories
  pay the cost of one mapping lookup. The lookup is an indexed SELECT on
  `(active, category_id)` — sub-millisecond. Acceptable.

- **Static factory vs container.** The static factory is a small wiring
  shim that the enqueuer is decoupled from. If a future epic introduces a
  proper container, the factory becomes a one-line service definition.

- **Mapped category names.** M2 maps existing OpenEMR categories. It must not
  create visible implementation-branded document categories. The demo seed maps
  `Lab Report` to `lab_pdf` and leaves `intake_form` unseeded until a real
  intake workflow category is selected.

- **Allowlist drift.** Adding `worker` to the allowlist now (before M3
  uses it) is intentional to avoid a second allowlist migration in the
  same epic window. If any unrelated code starts emitting a `worker` field
  before M3, the value still passes through sanitized — but no caller
  in M2 emits it.

- **`doc_type` stored as VARCHAR vs DB ENUM.** VARCHAR with application-
  layer enum keeps doc-type expansion (M3+) migration-free. The `DocumentType`
  enum is the source of truth.

## Notes For Future Epics (Not In Scope)

- M3 will read `clinical_document_processing_jobs` with `lock_token` for atomic
  claim. Schema is already in place.
- M3 will add `agentforge_worker_heartbeats` and a `worker` column on
  telemetry events that M2's allowlist already permits.
- M5 will add `clinical_document_facts` and embeddings; the `documents.id`
  it references is the same id we enqueue here.

## Implementation Progress - 2026-05-05

Status: In progress.

Files changed:

- `src/AgentForge/Document/` added document type/status enums, positive-id
  value objects, DTOs, repository interfaces, SQL repositories,
  `DocumentUploadEnqueuer`, `DocumentUploadEnqueuerFactory`, and
  `DocumentUploadEnqueuerHook`.
- `src/AgentForge/Document/DocumentRetractionReason.php` and
  `DocumentRetractionHook.php` added the source-document deletion retraction
  contract.
- `src/AgentForge/DatabaseExecutor.php` and
  `src/AgentForge/DefaultDatabaseExecutor.php` added a small AgentForge SQL
  boundary for read/write repositories.
- `src/AgentForge/Observability/PatientRefHasher.php` added hashed
  `patient:<hash>` telemetry ids.
- `controllers/C_Document.class.php` now dispatches the safe enqueue hook after
  the shared OpenEMR document upload process successfully creates a document.
- `library/ajax/upload.php` now relies on `addNewDocument(...)` routing through
  `C_Document::upload_action_process()` and does not duplicate enqueue
  dispatch from outer portal/core AJAX call sites.
- `interface/patient_file/deleter.php` now calls
  `DocumentRetractionHook::dispatch(...)` after OpenEMR marks a document
  deleted; direct document deletes also verify that non-super users are acting
  on the active session patient.
- `sql/database.sql`, `sql/8_1_0-to-8_1_1_upgrade.sql`, and `version.php`
  add the two document tables, retraction metadata columns on
  `clinical_document_processing_jobs`, and bump `v_database` to 539.
- `agent-forge/sql/seed-demo-data.sql` maps the existing `Lab Report` category
  to `lab_pdf` and does not create implementation-branded document categories.
- `src/AgentForge/Observability/SensitiveLogPolicy.php` allows the new
  sanitized document telemetry keys.
- `tests/Tests/Isolated/AgentForge/Document/` and
  `SensitiveLogPolicyTest` cover M2 behavior.
- Source-level integration tests prove the actual upload and delete wiring:
  `C_Document` dispatches once, AJAX upload does not duplicate dispatch,
  `addNewDocument(...)` uses the controller upload process, and delete retraction
  happens after `documents.deleted=1`.

Acceptance map:

- New schema exists in install and upgrade SQL: implemented in
  `sql/database.sql` and `sql/8_1_0-to-8_1_1_upgrade.sql`.
- Mapping determinism: `clinical_document_type_mappings` now has a unique
  `category_id`, so one OpenEMR category cannot map to multiple clinical
  document types.
- Clinical document category mapping: implemented in `seed-demo-data.sql`;
  local rerun smoke kept `branded_categories=0` and exactly one
  `Lab Report -> lab_pdf` mapping.
- Eligible category enqueue: covered by isolated enqueuer tests, SQL-level
  unique-key smoke, in-container OpenEMR `addNewDocument(...)` smoke, and
  manual browser upload smoke through the standard Documents screen.
- Ineligible category no-op: covered by isolated enqueuer tests and
  in-container OpenEMR `addNewDocument(...)` smoke.
- Duplicate enqueue idempotency: covered by isolated repository/enqueuer tests,
  local SQL smoke (`job_count=1`, `status=pending`, `attempts=0` after two
  duplicate `INSERT IGNORE` attempts), and duplicate hook dispatch smoke
  (`job_count_after_duplicate_dispatch=1`).
- Upload safety: hook tests cover non-array results, missing `doc_id`, invalid
  ids, and resolver failure without exception escape for modeled failures.
- Sanitized logging: enqueuer tests and `SensitiveLogPolicyTest` confirm
  `patient_ref` is hashed and raw `patient_id` is not emitted by the new M2
  enqueue log context; the policy also blocks raw quote/value-style keys such
  as `quote_or_value`, `raw_quote`, `raw_value`, `document_text`, and
  `extracted_fields`.
- Source-document deletion retraction: implemented at the OpenEMR
  `delete_document(...)` boundary. All jobs for the deleted `document_id` are
  updated where `status <> 'retracted'` to `status='retracted'`,
  `retracted_at=NOW()`, `retraction_reason='source_document_deleted'`,
  `finished_at=COALESCE(finished_at, NOW())`, and `lock_token=NULL`.
- M2 data boundary: M2 does not extract facts, create embeddings, retrieve
  document facts, or promote any values into existing OpenEMR clinical tables.
  Deleted-source retraction in M2 only retracts
  `clinical_document_processing_jobs`; downstream fact, embedding, retrieval,
  and promoted-row invalidation are explicit later-epic contracts.
- Wrong-patient document handling: explicitly not M2 content validation.
  OpenEMR upload destination remains authoritative; if the source document is
  deleted, the current AgentForge jobs are retracted. Later extraction,
  identity verification, fact, embedding, retrieval, and chart-promotion epics
  must add downstream invalidation by `document_id`.
- Future promotion provenance: no extracted value should be written into
  existing OpenEMR clinical tables unless the promoted row can be traced from
  source `document_id` to `job_id` to extracted fact id to promoted OpenEMR row,
  with citation, confidence/review status, and promotion outcome.
- Direct document-delete guard: users do not see a field named `document_id`,
  but OpenEMR document delete links submit the document id in a request such as
  `deleter.php?document=<id>`. The M2 guard applies only to that direct document
  branch for non-super users; the broader patient-delete cleanup path still
  loops through `delete_document()` only after admin/super patient-delete
  authorization.

Proof run:

- `vendor/bin/phpunit -c phpunit-isolated.xml tests/Tests/Isolated/AgentForge/Document tests/Tests/Isolated/AgentForge/SensitiveLogPolicyTest.php`
  passed after clinical-document naming and seed hardening: 40 tests, 112 assertions.
- `vendor/bin/phpunit -c phpunit-isolated.xml --filter 'Document|SensitiveLogPolicy'`
  passed: 102 tests, 678 assertions.
- `vendor/bin/phpcs src/AgentForge/DatabaseExecutor.php src/AgentForge/DefaultDatabaseExecutor.php src/AgentForge/Observability/PatientRefHasher.php src/AgentForge/Document tests/Tests/Isolated/AgentForge/Document tests/Tests/Isolated/AgentForge/SensitiveLogPolicyTest.php library/ajax/upload.php`
  passed.
- `vendor/bin/phpstan analyze --memory-limit=4G --configuration=phpstan.neon.dist src/AgentForge/DatabaseExecutor.php src/AgentForge/DefaultDatabaseExecutor.php src/AgentForge/Observability/PatientRefHasher.php src/AgentForge/Document tests/Tests/Isolated/AgentForge/Document tests/Tests/Isolated/AgentForge/SensitiveLogPolicyTest.php`
  passed after allowing PHPStan to open its local analysis socket.
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist src/AgentForge tests/Tests/Isolated/AgentForge/Document tests/Tests/Isolated/AgentForge/SensitiveLogPolicyTest.php --memory-limit=1G`
  passed for the full AgentForge source tree plus focused document/logging
  tests.
- `composer phpcs -- -n src/AgentForge/Document tests/Tests/Isolated/AgentForge/Document sql/8_1_0-to-8_1_1_upgrade.sql sql/database.sql agent-forge/sql/seed-demo-data.sql`
  passed: 13 / 13 files.
- `composer phpcs -- -n src/AgentForge/Document/DocumentRetractionHook.php src/AgentForge/Document/DocumentRetractionReason.php src/AgentForge/Document/DocumentJob.php src/AgentForge/Document/DocumentJobRepository.php src/AgentForge/Document/SqlDocumentJobRepository.php src/AgentForge/DatabaseExecutor.php src/AgentForge/DefaultDatabaseExecutor.php interface/patient_file/deleter.php tests/Tests/Isolated/AgentForge/Document/DocumentRetractionHookTest.php tests/Tests/Isolated/AgentForge/Document/DocumentRetractionReasonTest.php tests/Tests/Isolated/AgentForge/Document/DocumentUploadEnqueuerTest.php tests/Tests/Isolated/AgentForge/Document/SqlDocumentRepositoriesTest.php`
  passed: 12 / 12 files.
- Local Docker SQL smoke passed:
  tables `clinical_document_processing_jobs` and `clinical_document_type_mappings`
  exist; `Lab Report` exists; mapping is active for `lab_pdf`; rerunning the
  seed kept the clinical-document mapping idempotent; duplicate job insert stayed at one
  pending row.
- After review, the branded category seed approach was deleted. The seed now
  maps the existing `Lab Report` category directly and does not create document
  categories for clinical-document processing.
- Local dev DB was renamed and cleaned after the contract rename:
  `agentforge_document_type_mappings` -> `clinical_document_type_mappings`,
  `agentforge_document_jobs` -> `clinical_document_processing_jobs`, old index
  names were renamed, temporary `AgentForge%` categories were removed,
  documents `77` and `78` were marked deleted, category links for `77` and
  `78` were removed, and job `5` for document `78` was retracted.
- Corrected seed idempotency smoke passed: before and after rerunning
  `seed-demo-data.sql`, `branded_categories=0` and
  `Lab Report -> lab_pdf` mapping count stayed `1`.
- `agent-forge/scripts/check-clinical-document.sh` partially passed after final
  closeout validation: diff whitespace, PHP syntax, shell syntax, and
  AgentForge isolated PHPUnit all passed (342 tests, 1655 assertions). The
  final clinical eval verdict remains
  `threshold_violation`, with artifact
  `agent-forge/eval-results/clinical-document-20260505-143516`, because later
  M3/M4 extraction behavior is still not implemented.
- In-container OpenEMR upload smoke passed for a mapped category by calling the
  real `addNewDocument(...)` function and then the same
  `DocumentUploadEnqueuerHook::dispatch(...)` used by `library/ajax/upload.php`.
  It created document `75`, inserted one `pending` `lab_pdf` job, linked the
  document to category `35`, and a second duplicate dispatch kept
  `job_count_after_duplicate_dispatch=1`.
- In-container OpenEMR upload smoke passed for an unmapped category by calling
  the real `addNewDocument(...)` function and dispatching category `1`; it
  created document `76` and left `job_count_for_unmapped_category=0`.
- Manual browser upload smoke initially found a real missed path: uploading
  `p01-chen-lipid-panel.pdf` through the visible standard Documents form
  created document `77` in the temporary branded lab category but no clinical
  document job. That
  form posts through `C_Document::upload_action_process()`, not the Dropzone
  `library/ajax/upload.php` path.
- After hooking `C_Document::upload_action_process()`, manual browser upload
  smoke passed: the repeated upload created document `78` for patient `900001`
  in the temporary branded lab category and inserted job `5` with
  `doc_type=lab_pdf`, `status=pending`, `attempts=0`; latest job count for
  document `78` was exactly `1`.
- Corrected in-container OpenEMR upload smoke passed for the normal workflow:
  uploading `p01-chen-lipid-panel-clinical-contract.pdf` to existing category
  `Lab Report` created document `79` and job `6` in
  `clinical_document_processing_jobs` with `doc_type=lab_pdf`,
  `status=pending`, and no retraction metadata.
- Local DB retraction schema delta was applied idempotently to the active dev
  DB with `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`; `DESCRIBE
  clinical_document_processing_jobs` shows `retracted_at datetime NULL` and
  `retraction_reason varchar(64) NULL`.
- Simulated already-finished work proof passed on `document_id=75` / `job_id=3`:
  the job was set to `succeeded` with a `manual-finished-token`, then
  `DocumentRetractionHook::dispatch(75)` was invoked through the OpenEMR
  container with `$ignoreAuth=true`; the row became `status=retracted`,
  `lock_token=NULL`, `retracted_at=2026-05-05 15:18:26`, and
  `retraction_reason=source_document_deleted`, while preserving the prior
  `finished_at=2026-05-05 15:17:51`.
- Repeated hook dispatch for `document_id=75` was idempotent: the row remained
  `retracted` and `retracted_at` did not change.
- Corrected retraction smoke for document `79` passed by applying the same DB
  state transitions as `delete_document(...)` and invoking
  `DocumentRetractionHook::dispatch(79)`: `documents.deleted=1`, category link
  count `0`, job `6` became `status=retracted`, `retracted_at` was set, and
  `retraction_reason=source_document_deleted`.
- `composer phpunit-isolated` passed outside the sandbox after allowing the
  built-in routing-test server to bind to `127.0.0.1:8765`: 3073 tests, 8637
  assertions, 3 pre-existing warnings, 1 pre-existing notice, 3 skipped, 14
  incomplete.
- `composer phpunit-isolated` was rerun during source-retraction hardening and
  failed only because the local routing test server was unavailable at
  `127.0.0.1:8765`: 3080 tests, 8640 assertions, 8 connection errors, 3
  warnings, 1 notice, 3 skipped, 14 incomplete. The failing tests were
  `OpenEMR\Tests\Isolated\BC\FrontControllerRoutingTest` cases.
- `agent-forge/scripts/check-clinical-document.sh` was rerun during
  source-retraction hardening. Diff whitespace, PHP syntax, shell syntax, and
  AgentForge isolated PHPUnit passed (349 tests, 1672 assertions). The clinical
  eval verdict remained `threshold_violation`; artifact:
  `agent-forge/eval-results/clinical-document-20260505-151634`. The artifact
  shows the expected M1 downstream gaps: adapter status `not_implemented` for
  extraction/retrieval cases, while `no_phi_in_logs` passed 8/8.
- `agent-forge/scripts/check-clinical-document.sh` was rerun after the
  clinical-document contract rename. Diff whitespace, PHP syntax, shell syntax,
  and AgentForge isolated PHPUnit passed (352 tests, 1686 assertions). The
  eval verdict remains `threshold_violation`; artifact:
  `agent-forge/eval-results/clinical-document-20260505-154754`.
- Review hardening after the M2 full review removed duplicate AJAX enqueue
  dispatch, made `C_Document::upload_action_process()` the single enqueue
  integration point, added source-level integration wiring tests, made
  `clinical_document_type_mappings.category_id` unique, added schema contract
  tests for fresh/upgrade SQL shape, and added a direct document-delete active
  patient guard for non-super users.
- M2 closeout safety stubs were added to `PLAN-W2.md` for document identity
  verification/wrong-patient safeguards, promotion provenance/review/duplicate
  prevention, and promoted-data retraction/audit. `MEMORY.md` now carries the
  durable rule that promoted clinical data must never be anonymous side effects.
- `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge\\Document'`
  passed after review hardening: 49 tests, 136 assertions.
- `vendor/bin/phpcs library/ajax/upload.php interface/patient_file/deleter.php src/AgentForge/Document/DocumentUploadEnqueuer.php src/AgentForge/Document/DocumentUploadEnqueuerHook.php tests/Tests/Isolated/AgentForge/Document/DocumentIntegrationWiringTest.php tests/Tests/Isolated/AgentForge/Document/ClinicalDocumentSchemaContractTest.php tests/Tests/Isolated/AgentForge/Document/ClinicalDocumentSeedTest.php tests/Tests/Isolated/AgentForge/Document/DocumentUploadEnqueuerTest.php tests/Tests/Isolated/AgentForge/Document/DocumentUploadEnqueuerHookTest.php`
  passed: 9 / 9 files.
- `vendor/bin/phpstan analyze --memory-limit=4G --configuration=phpstan.neon.dist src/AgentForge/Document tests/Tests/Isolated/AgentForge/Document library/ajax/upload.php interface/patient_file/deleter.php .phpstan/baseline/variable.undefined.php`
  passed after allowing PHPStan to open its local analysis socket.
- `agent-forge/scripts/check-clinical-document.sh` was rerun after review
  hardening. Diff whitespace, PHP syntax, shell syntax, and AgentForge isolated
  PHPUnit passed (363 tests, 1730 assertions). The clinical eval verdict
  remains `threshold_violation`; artifact:
  `agent-forge/eval-results/clinical-document-20260505-162038`.
- After adding resolver-result validation to the upload hook, the focused
  document suite passed again (50 tests, 136 assertions), focused PHPCS passed
  (9 / 9 files), focused PHPStan passed with no errors, `git diff --check`
  passed, and `agent-forge/scripts/check-clinical-document.sh` reached the
  expected eval threshold step with AgentForge isolated PHPUnit passing
  (364 tests, 1730 assertions). The clinical eval verdict remains
  `threshold_violation`; artifact:
  `agent-forge/eval-results/clinical-document-20260505-162450`.
- After adding future safety epic stubs and documentation/source-contract tests,
  the focused document suite passed (55 tests, 158 assertions), focused PHPCS
  passed for the new/related planning tests, focused PHPStan passed for the new
  planning contract test, and `git diff --check` passed.
- `agent-forge/scripts/check-clinical-document.sh` was rerun after the safety
  stub closeout. Diff whitespace, PHP syntax, shell syntax, and AgentForge
  isolated PHPUnit passed (369 tests, 1752 assertions). The clinical eval
  verdict remains `threshold_violation`; artifact:
  `agent-forge/eval-results/clinical-document-20260505-170450`.
- M2 closeout automation was rerun before destructive clean-stack validation:
  focused document/logging PHPUnit passed (57 tests, 178 assertions), focused
  PHPCS passed (15 / 15 files), focused PHPStan passed with no errors,
  `git diff --check` passed, and `agent-forge/scripts/check-clinical-document.sh`
  reached the expected eval threshold step with AgentForge isolated PHPUnit
  passing (369 tests, 1752 assertions). The clinical eval verdict remains
  `threshold_violation`; artifact:
  `agent-forge/eval-results/clinical-document-20260505-171955`.
- Docker stack inspection showed `docker/development-easy` services up and
  healthy. The destructive `docker compose down -v` reset was not run because
  it requires explicit approval after the local database deletion warning.

Open proof gaps:

- Fresh-install and upgrade-flow verification now has source-level schema
  contract tests, but a destructive `docker compose down -v` fresh-stack
  reset/reseed was not run in this pass. Because the branded M2 schema was
  never accepted or shipped, final local validation should prefer a reset/reseed
  over supporting old partial `agentforge_document_*` tables.
- Manual browser deletion through the actual OpenEMR modal still has not been
  repeated after the clinical-document rename. The corrected upload/retraction
  behavior is proven through in-container OpenEMR upload plus direct hook/DB
  transition smoke, and the UI delete path contains the same
  `DocumentRetractionHook::dispatch(...)` call.
- The clinical document eval gate still reports `threshold_violation` as
  expected before later worker/extraction epics replace the M1 not-implemented
  adapter.

# Epic M3 - Automatic PHP Worker Skeleton

> **Status:** Implemented with automated proof and local Docker/manual proof; VM proof remains separate
> **Source spec:** `agent-forge/docs/week2/PLAN-W2.md` (M3 entry, lines 175-220)
> **Architecture refs:** `W2_ARCHITECTURE.md` (Sections 5, 6, 14, 16)
> **Constraint:** Tests-first per PLAN-W2 work order. No production code lands before its test.

---

## 1. Context

M2 (just-completed) wired the **enqueue path**: when a clinical document is uploaded into a mapped category, an `INSERT IGNORE` row lands in `clinical_document_processing_jobs` with `status='pending'`; when a document is retracted, all of its jobs flip to `status='retracted'`. M2 deliberately stopped at the row insert. **No process consumes the queue today.** Pending jobs accumulate forever and the queue is invisible to the rest of the application.

M3 introduces the **first consumer**: an out-of-band PHP CLI process that atomically claims pending jobs and runs them through a strategy-driven processor pipeline. M3 ships the *skeleton only* - the lifecycle, claim mechanics, heartbeating, observability, and Docker wiring. It deliberately does **not** ship a real extractor. The processor strategy slotted in for M3 is a no-op that marks every claimed job as `failed` with `error_code='extraction_not_implemented'`. Real OCR/LLM extraction is M4's responsibility, plugging into the M3 strategy seam.

Three carry-forward rules from `agent-forge/docs/MEMORY.md` constrain this design:

1. **Upload safety contract** - the M3 worker runs in a separate process. A worker crash, hang, or OOM must never prevent a document upload from succeeding. M2's enqueue hook already swallows its own failures; M3 honors the contract by running out-of-process.
2. **No raw PHI in logs** - every log emission goes through `SensitiveLogPolicy::sanitizeContext()`. Document text, extracted fields, raw quotes, and patient names never leave the worker. Patients are referenced via HMAC-hashed `patient_ref`.
3. **`retracted` is terminal** - the worker's atomic claim must filter out retracted rows. A retraction that races a claim must lose to the retraction (the worker re-checks status after claim and abandons retracted jobs without processing).

---

## 2. Goals / Non-Goals

### Goals

- Stand up a long-running PHP CLI worker that claims and runs document jobs, isolated from the web request path.
- Atomic single-row claim with re-entry safety - two workers can run concurrently without double-processing the same row.
- Strategy-pattern seam (`DocumentJobProcessor` interface) so M4 can plug real extraction in without touching the worker loop.
- Heartbeat persistence so M4's supervisor and operators can see "is this worker alive, what's it doing, when did it last beat?"
- One-shot mode (`--max-iterations=N`) for deterministic isolated tests; daemon mode for production.
- Graceful shutdown on SIGTERM - finish the current job, mark heartbeat stopped, exit clean.
- Docker compose service `agentforge-worker` that runs the CLI under the same image and code mount as the OpenEMR app.
- Observability: structured PSR-3 logs with sanitized context, including job lifecycle events and heartbeat ticks.

### Non-Goals

- **Any real document extraction** - the M3 processor is a no-op stub. (M4)
- **Supervisor process** that spawns/restarts workers or reaps stale locks. (M4)
- **Vector indexing** of extracted document content. (M4/M5)
- **Multi-worker-type routing** - M3 ships only the `intake-extractor` worker name. M4 adds `supervisor` and `evidence-retriever`.
- **Stale-lock recovery** - if a worker dies holding a lock, the row stays in `running` status. M4 reaper handles this.
- **Retry policy** beyond incrementing the existing `attempts` column on claim - no exponential backoff, no dead-letter queue.
- **Cross-host coordination** - lock_token disambiguates two processes on the same DB; multi-host scaling not in scope.
- **UI surfacing** of worker state. Operators read logs and the heartbeat table.

---

## 3. Critical Files

### Add (production)

| Path | Purpose |
|------|---------|
| `agent-forge/scripts/process-document-jobs.php` | CLI entry point; parses flags, wires factory, runs worker loop, handles signals |
| `src/AgentForge/Document/Worker/DocumentJobWorker.php` | Orchestration: claim -> load -> process -> finalize -> heartbeat loop |
| `src/AgentForge/Document/Worker/DocumentJobWorkerFactory.php` | Default wiring (mirrors `DocumentUploadEnqueuerFactory`) |
| `src/AgentForge/Document/Worker/JobClaimer.php` | Interface: `claimNext(WorkerName, LockToken): ?DocumentJob` and `releaseStaleClaim(LockToken): void` |
| `src/AgentForge/Document/Worker/SqlJobClaimer.php` | Portable atomic `UPDATE ... WHERE status='pending' AND retracted_at IS NULL ORDER BY created_at ASC LIMIT 1`, followed by `SELECT ... WHERE lock_token=?` |
| `src/AgentForge/Document/Worker/DocumentJobProcessor.php` | Interface: `process(DocumentJob, DocumentLoadResult): ProcessingResult` |
| `src/AgentForge/Document/Worker/NoopDocumentJobProcessor.php` | M3 stub - returns `ProcessingResult::failed('extraction_not_implemented', '...')` for every job |
| `src/AgentForge/Document/Worker/ProcessingResult.php` | Readonly DTO: `succeeded()` / `failed(errorCode, errorMessage)` constructors |
| `src/AgentForge/Document/Worker/OpenEmrDocumentLoader.php` | Adapter wrapping legacy `Document` class - exposes `load(DocumentId): DocumentLoadResult` and rejects deleted/expired |
| `src/AgentForge/Document/Worker/DocumentLoader.php` | Interface: `load(DocumentId): DocumentLoadResult` (allows in-memory test stub) |
| `src/AgentForge/Document/Worker/DocumentLoadResult.php` | Readonly DTO: `bytes` (string), `mimeType` (string), `name` (string), `byteCount` (int) |
| `src/AgentForge/Document/Worker/DocumentLoadException.php` | Domain exception for missing / deleted / expired / unreadable documents - carries `errorCode` |
| `src/AgentForge/Document/Worker/WorkerHeartbeat.php` | Readonly DTO: workerName, processId, status, iterationCount, jobsProcessed, jobsFailed, lastHeartbeatAt |
| `src/AgentForge/Document/Worker/WorkerHeartbeatRepository.php` | Interface: `upsert(WorkerHeartbeat): void`, `findByWorker(WorkerName): ?WorkerHeartbeat` |
| `src/AgentForge/Document/Worker/SqlWorkerHeartbeatRepository.php` | MariaDB upsert against `clinical_document_worker_heartbeats` |
| `src/AgentForge/Document/Worker/WorkerName.php` | Backed string enum: `Supervisor`, `IntakeExtractor`, `EvidenceRetriever` + `fromStringOrThrow()` |
| `src/AgentForge/Document/Worker/WorkerStatus.php` | Backed string enum: `Starting`, `Running`, `Idle`, `Stopping`, `Stopped` + `fromStringOrThrow()` |
| `src/AgentForge/Document/Worker/LockToken.php` | Readonly value object - 64-char hex string from `random_bytes(32)`; `LockToken::generate()` factory |
| `src/AgentForge/Document/Worker/DocumentJobWorkerRepository.php` | Worker-facing repository seam for `markFinished(DocumentJobId, LockToken, JobStatus, errorCode, errorMessage): int` and `findClaimedByLockToken(LockToken): ?DocumentJob` |
| `sql/clinical_document_worker_heartbeats.sql.append` *(conceptual)* | New table DDL added inline to `sql/database.sql` |

### Add (tests, written first)

| Path | What it covers |
|------|---------------|
| `tests/Tests/Isolated/AgentForge/Document/Worker/DocumentJobWorkerTest.php` | Loop orchestration: claim -> load -> process -> finalize ordering; idle path; SIGTERM-style shutdown via `--max-iterations=0`; sanitized log assertions |
| `tests/Tests/Isolated/AgentForge/Document/Worker/SqlJobClaimerTest.php` | SQL/binds for atomic claim + post-claim re-fetch; verifies retracted-row filter; uses `DocumentRepositoryExecutor` test stub |
| `tests/Tests/Isolated/AgentForge/Document/Worker/NoopDocumentJobProcessorTest.php` | Returns `ProcessingResult::failed('extraction_not_implemented', ...)` for every input |
| `tests/Tests/Isolated/AgentForge/Document/Worker/OpenEmrDocumentLoaderTest.php` | Behavior contract via injected `Document` factory closure: deleted -> `DocumentLoadException('source_document_deleted')`; expired -> `DocumentLoadException('source_document_expired')`; unreadable -> `DocumentLoadException('source_document_unreadable')`; happy path returns bytes + mimeType + name |
| `tests/Tests/Isolated/AgentForge/Document/Worker/SqlWorkerHeartbeatRepositoryTest.php` | Upsert SQL/binds; find by worker SQL; hydration of returned row |
| `tests/Tests/Isolated/AgentForge/Document/Worker/DocumentWorkerValueObjectTest.php` | Worker DTO/value-object invariants and parser behavior |
| `tests/Tests/Isolated/AgentForge/Document/Worker/ProcessDocumentJobsScriptShapeTest.php` | CLI script shape and Docker Compose service shape |
| `tests/Tests/Isolated/AgentForge/Document/SqlDocumentRepositoriesTest.php` *(extended from M2)* | Worker repository `markFinished()` and `findClaimedByLockToken()` SQL/binds and behavior |

### Modify

| Path | Change |
|------|--------|
| `src/AgentForge/Document/DocumentJobRepository.php` | Remains the M2 enqueue/retract/read boundary |
| `src/AgentForge/Document/SqlDocumentJobRepository.php` | Implements both `DocumentJobRepository` and the worker-facing `DocumentJobWorkerRepository` |
| `src/AgentForge/Observability/SensitiveLogPolicy.php` | Extend `ALLOWED_KEYS` with worker-specific keys (see Section 6) |
| `docker/development-easy/docker-compose.yml` | Add `agentforge-worker` service (see Section 8) |
| `sql/database.sql` | Add `clinical_document_worker_heartbeats` DDL (see Section 4) |
| `version.php` | `$v_database = 539` -> `540` |
| `agent-forge/docs/MEMORY.md` | After M3 lands, append carry-forward notes (stale-lock reaper deferred to M4, NoopDocumentJobProcessor must be replaced in M4, worker must remain out-of-process to honor upload safety) |
| `agent-forge/docs/week2/PLAN-W2.md` | Move M3 status from "Not started" -> "In progress" -> "Complete" as work proceeds |

### Reused (no change)

- `src/AgentForge/Database/DefaultDatabaseExecutor.php` - all SQL goes through `DatabaseExecutor` exactly like M2
- `src/AgentForge/Document/{DocumentJob, DocumentJobId, DocumentId, PatientId, JobStatus, DocumentType, DocumentRetractionReason}.php` - DTO and value objects already shaped for the worker
- `src/AgentForge/Observability/PsrRequestLogger.php` and `SensitiveLogPolicy.php` - logging path
- `src/AgentForge/Observability/PatientRefHasher.php` - patient_ref hashing
- `src/AgentForge/ServiceContainer.php` - logger resolution
- `library/classes/Document.class.php` - legacy `Document` class wrapped (read-only) by `OpenEmrDocumentLoader`. Calls `new Document($id)`, then `is_deleted()`, `has_expired()`, `get_data()`, `get_mimetype()`, `get_name()`. `get_data()` handles transparent decryption via `CryptoGen` - the worker never touches encryption directly.

---

## 4. Schema

### New table

```sql
CREATE TABLE `clinical_document_worker_heartbeats` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `worker` varchar(64) NOT NULL,
  `process_id` int(11) NOT NULL,
  `status` varchar(32) NOT NULL,
  `iteration_count` bigint(20) NOT NULL DEFAULT 0,
  `jobs_processed` bigint(20) NOT NULL DEFAULT 0,
  `jobs_failed` bigint(20) NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL,
  `last_heartbeat_at` datetime NOT NULL,
  `stopped_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_worker_heartbeats_worker` (`worker`),
  KEY `idx_clinical_document_worker_heartbeats_status` (`status`)
) ENGINE=InnoDB;
```

`UNIQUE KEY` on `worker` enforces one row per worker name and powers the upsert. `process_id` updates on restart so operators can correlate logs to PIDs. `started_at` only updates on first insert; `last_heartbeat_at` updates every loop tick.

### `clinical_document_processing_jobs` (no schema change)

The M2 table already carries `lock_token`, `attempts`, `started_at`, `finished_at`, `error_code`, `error_message`. M3 adds usage of these columns but no new columns.

### Version bump

`version.php`: `$v_database = 539` -> `540`. M2 left this at 539; M3 increments by 1.

### Migration safety

The new table is greenfield - no data migration. Idempotent creation via `CREATE TABLE IF NOT EXISTS` is preferred for the deploy script integration but final form follows the `sql/database.sql` convention used in M2.

---

## 5. Module Design

### Component diagram (data flow)

```
process-document-jobs.php (CLI)
        |
        v
DocumentJobWorkerFactory::createDefault(workerName, args)
        |
        v
+-----------------------+
|  DocumentJobWorker    |
|  - run(MaxIterations) |
+-----------------------+
   |             |              |               |
   v             v              v               v
JobClaimer  DocumentLoader  DocumentJob-     WorkerHeartbeat-
            (interface)     Processor        Repository
   |             |          (interface)         |
   v             v              v               v
SqlJobClaimer  OpenEmrDoc-  Noop...           SqlWorkerHeart-
               Loader       Processor         beatRepository
   |             |              |               |
   v             v              v               v
DatabaseExec.  legacy        (M3 stub)       DatabaseExec.
               Document
               class
```

### `DocumentJobWorker` lifecycle

The worker runs an outer loop bounded by `--max-iterations` (default unbounded for daemon mode, finite for tests). Each iteration:

1. **Heartbeat** with `WorkerStatus::Running` and current counters (upsert).
2. **Claim**: call `JobClaimer::claimNext(workerName, LockToken::generate())`. Returns `?DocumentJob`.
3. **No job claimed**: heartbeat with `WorkerStatus::Idle`, `sleep($idleSleepSeconds)`, increment `iterationCount`, continue.
4. **Job claimed**:
   - Re-check `$job->status === JobStatus::Running` and `$job->retractedAt === null` (the post-claim re-fetch is part of `claimNext`'s contract; this is a defensive double-check).
   - **Load**: call `DocumentLoader::load($job->documentId)`. On `DocumentLoadException`, finalize job as `failed` with the exception's `errorCode` and a generic message, log `clinical_document.worker.job_failed`, increment `jobsFailed`, continue to next iteration.
   - **Process**: call `DocumentJobProcessor::process($job, $loadResult)`. Returns `ProcessingResult`.
   - **Finalize**: call `DocumentJobWorkerRepository::markFinished($job->id, $lockToken, terminal, errorCode, errorMessage)`. Terminal status is `Succeeded` or `Failed` per the `ProcessingResult`; zero affected rows means the worker lost ownership and must not count/log completion.
   - Log `clinical_document.worker.job_completed` (succeeded) or `clinical_document.worker.job_failed` (failed) with sanitized context.
   - Increment `jobsProcessed` (always) and `jobsFailed` (if failed).
5. **End of iteration**: if `--max-iterations` reached, break and proceed to shutdown.

**Shutdown** (loop exit, or Docker shell trap invoking `--mark-stopped` because OpenEMR's PHP configuration disables `pcntl_signal*`):
- Heartbeat with `WorkerStatus::Stopping`.
- Finish current iteration's work (don't interrupt mid-job).
- Heartbeat with `WorkerStatus::Stopped`, set `stopped_at` via repository semantics.
- Log `clinical_document.worker.shutdown`.
- Return exit code 0 from worker; CLI script propagates.

### `JobClaimer` contract

```php
interface JobClaimer
{
    /**
     * Atomically claim one pending, non-retracted job and return it
     * with status=running, lock_token set, attempts incremented.
     * Returns null if no claimable rows exist.
     */
    public function claimNext(WorkerName $workerName, LockToken $lockToken): ?DocumentJob;
}
```

### `SqlJobClaimer` mechanics

**Portable M3 path:**

```sql
-- Step 1: atomic claim
UPDATE clinical_document_processing_jobs
   SET status = 'running',
       lock_token = ?,
       started_at = NOW(),
       attempts = attempts + 1
 WHERE status = 'pending'
   AND retracted_at IS NULL
 ORDER BY created_at ASC
 LIMIT 1

-- Step 2: re-fetch the row we just claimed
SELECT id, patient_id, document_id, doc_type, status, attempts,
       lock_token, created_at, started_at, finished_at,
       error_code, error_message, retracted_at, retraction_reason
  FROM clinical_document_processing_jobs
 WHERE lock_token = ?
   AND status = 'running'
 LIMIT 1
```

If `affectedRows()` from step 1 is 0, return null without running step 2. The two-step pattern avoids any ambiguity about which row was claimed when multiple workers run concurrently.

This avoids `SKIP LOCKED` portability issues and is sufficient for M3's no-double-processing contract when paired with lock-token guarded finish.

### `DocumentLoader` and `OpenEmrDocumentLoader`

The legacy `Document` class is global (no namespace), constructed as `new Document($id)`, and auto-populates from the `documents` table. We wrap it behind an interface so unit tests don't need a database.

```php
interface DocumentLoader
{
    /** @throws DocumentLoadException */
    public function load(DocumentId $documentId): DocumentLoadResult;
}
```

`OpenEmrDocumentLoader` accepts an optional `Closure(int): \Document` factory in its constructor (default: `fn (int $id) => new \Document($id)`). The factory seam is the test injection point - tests pass a closure that returns a stub that implements the same five legacy method signatures.

`OpenEmrDocumentLoader::load()` flow:

```
$doc = ($this->documentFactory)($documentId->value);
if ($doc->get_id() === null) throw DocumentLoadException::missing();
if ($doc->is_deleted())      throw DocumentLoadException::sourceDeleted();
if ($doc->has_expired())     throw DocumentLoadException::expired();
try {
    $bytes = $doc->get_data();             // transparent decrypt via CryptoGen
} catch (\Throwable $e) {
    throw DocumentLoadException::unreadable();   // chain via ->getPrevious()
}
return new DocumentLoadResult(
    bytes:     $bytes,
    mimeType:  $doc->get_mimetype(),
    name:      $doc->get_name(),
    byteCount: strlen($bytes),
);
```

The exception's `errorCode` maps to job `error_code` values: `source_document_missing`, `source_document_deleted`, `source_document_expired`, `source_document_unreadable`. The original exception message never propagates to logs or the DB - only the stable error code does.

### `DocumentJobProcessor` strategy seam

```php
interface DocumentJobProcessor
{
    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult;
}
```

`NoopDocumentJobProcessor` implementation:

```php
public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult
{
    return ProcessingResult::failed(
        errorCode: 'extraction_not_implemented',
        errorMessage: 'M3 worker skeleton; M4 will replace this processor.',
    );
}
```

This is the seam M4 plugs into. Replacing `NoopDocumentJobProcessor` with a real OCR/LLM extractor is M4's only required change to the worker module.

### `LockToken`

```php
final readonly class LockToken
{
    public function __construct(public string $value)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            throw new DomainException('Lock token must be 64 lowercase hex characters.');
        }
    }
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }
}
```

### `WorkerHeartbeat` and upsert SQL

```sql
INSERT INTO clinical_document_worker_heartbeats
  (worker, process_id, status, iteration_count, jobs_processed, jobs_failed,
   started_at, last_heartbeat_at, stopped_at)
VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
ON DUPLICATE KEY UPDATE
  process_id        = VALUES(process_id),
  status            = VALUES(status),
  iteration_count   = VALUES(iteration_count),
  jobs_processed    = VALUES(jobs_processed),
  jobs_failed       = VALUES(jobs_failed),
  last_heartbeat_at = VALUES(last_heartbeat_at),
  stopped_at        = VALUES(stopped_at)
```

`stopped_at` is null until `WorkerStatus::Stopped`; only the stop transition writes a non-null value.

### CLI script (`agent-forge/scripts/process-document-jobs.php`)

Follows the existing `agent-forge/scripts/run-evals.php` skeleton:

```
#!/usr/bin/env php
<?php declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

exit(\OpenEMR\AgentForge\Document\Worker\ProcessDocumentJobsCommand::main($argv));
```

`ProcessDocumentJobsCommand::main()` is a static function (testable) that:

1. Parses `$argv` into a `WorkerArgs` value object via `WorkerArgs::fromArgv()` (testable in isolation).
2. Resolves the worker via `DocumentJobWorkerFactory::createDefault($args->workerName)`.
3. Runs `DocumentJobWorker::run($args->maxIterations, $args->idleSleepSeconds)`.
4. Supports `--mark-stopped --process-id=N` for the Docker shell trap so stopped heartbeats are written even though OpenEMR disables the PHP signal functions.
5. Returns 0 on graceful exit, 1 on caught domain/runtime failures (logged through sanitized context).

CLI flags:

| Flag | Default | Purpose |
|------|---------|---------|
| `--worker=NAME` | (required) | one of `intake-extractor`, `evidence-retriever`, `supervisor` |
| `--max-iterations=N` | `0` (unbounded) | exit after N loop iterations - used for tests and one-shot runs |
| `--one-shot` | false | shorthand for `--max-iterations=1` |
| `--idle-sleep-seconds=N` | `5` | seconds to sleep when no jobs available |

### Repository extensions (existing M2 file)

Two new methods on `DocumentJobRepository` (interface + SQL impl):

```php
public function markFinished(
    DocumentJobId $id,
    JobStatus $terminal,         // must be Succeeded or Failed
    ?string $errorCode,
    ?string $errorMessage,
): void;

public function findClaimedByLockToken(LockToken $lockToken): ?DocumentJob;
```

`markFinished` SQL:

```sql
UPDATE clinical_document_processing_jobs
   SET status = ?,
       finished_at = NOW(),
       error_code = ?,
       error_message = ?,
       lock_token = NULL
 WHERE id = ?
```

The `lock_token = NULL` write releases the row from the worker's logical ownership.

---

## 6. Logging

All log emissions go through `ServiceContainer::getLogger()` and are sanitized via `SensitiveLogPolicy::sanitizeContext()` before reaching the logger - no exceptions.

### Event names (stable, used in dashboards and tests)

| Level | Event | When |
|-------|-------|------|
| info  | `clinical_document.worker.started` | First iteration after process boot |
| info  | `clinical_document.worker.idle` | Iteration ended with no claimable job |
| info  | `clinical_document.worker.job_claimed` | After a claim succeeds, before processing |
| info  | `clinical_document.worker.job_completed` | Job marked succeeded |
| warning | `clinical_document.worker.job_failed` | Job marked failed (load failure or processor failure) |
| info  | `clinical_document.worker.heartbeat` | Each heartbeat upsert (info; downgraded to debug if too noisy in real ops) |
| info  | `clinical_document.worker.shutdown` | Graceful shutdown completed |
| error | `clinical_document.worker.fatal` | Uncaught throwable from worker loop - process exits 1 |

### Context keys

The worker emits these keys; **all must be in `SensitiveLogPolicy::ALLOWED_KEYS`** before any log call lands. The M3 modify list adds the missing ones to that allowlist:

| Key | Already allowed (M2)? | Notes |
|-----|----------------------|-------|
| `worker` | yes | worker name string |
| `request_id` | yes | per-iteration UUID for log stitching |
| `patient_ref` | yes | HMAC-hashed patient id from `PatientRefHasher` |
| `document_id` | yes | int |
| `doc_type` | yes | enum value |
| `job_id` | yes | int |
| `status` | yes | job status enum value |
| `attempts` | yes | int |
| `error_code` | yes | stable error code string |
| `latency_ms` | yes | per-job processing latency |
| `process_id` | **add** | OS PID of worker |
| `iteration_count` | **add** | int |
| `jobs_processed` | **add** | int |
| `jobs_failed` | **add** | int |
| `lock_token_prefix` | **add** | first 8 hex chars of lock_token (full token never logged) |
| `idle_seconds` | **add** | int (sleep duration) |
| `claimed_count` | **add** | always 0 or 1 in M3 (room for batch claim later) |
| `worker_status` | **add** | WorkerStatus enum value |

**Forbidden** - already in `FORBIDDEN_KEYS`, no change: `document_text`, `document_image`, `extracted_fields`, `exception`, `raw_exception`, `quote`, `raw_quote`, `chart_text`. The worker never logs raw bytes, never logs `\Throwable::getMessage()`, never logs full lock tokens.

### Patient identifiers

Every log carrying a patient reference uses `patient_ref` (the HMAC hash) - never `patient_id` directly in M3 worker code, even though the M2 `ALLOWED_KEYS` permits raw `patient_id` for backwards compatibility. This is a tightening that future epics should consider extending across the board (note for MEMORY).

---

## 7. Tests (written first)

PLAN-W2 mandates test-first. The work order is: each test file lands and fails first, then its production counterpart lands and turns it green. Test files mirror the M2 convention: same-file inline stubs, `AbstractLogger` mock named `*RecordingLogger`, `DocumentRepositoryExecutor` for SQL assertions.

### Stub strategy (mirrors M2)

- **In-memory repositories**: `InMemoryWorkerHeartbeatRepository`, `InMemoryDocumentJobRepository` (extended from M2 to include the new methods), `InMemoryJobClaimer` (deterministic queue), `InMemoryDocumentLoader`, `InMemoryDocumentJobProcessor`. All inline at the bottom of the test file that uses them.
- **Recording logger**: `WorkerRecordingLogger extends AbstractLogger` - records `[level, message, context]` tuples. Tests assert on event names and sanitized context.
- **DB executor stub** for SQL-shape tests: reuse `DocumentRepositoryExecutor` from M2's `SqlDocumentRepositoriesTest.php`.
- **Document factory closure** for `OpenEmrDocumentLoaderTest`: returns a small inline class that implements the four `Document` methods used (`get_id`, `is_deleted`, `has_expired`, `get_data`, `get_mimetype`, `get_name`).

### Coverage matrix

`DocumentJobWorkerTest` covers:
- Happy path: claim returns a job -> loader returns bytes -> processor returns succeeded -> `markFinished(Succeeded, null, null)` called -> `jobs_processed=1` -> `clinical_document.worker.job_completed` logged.
- Stub processor path (M3 default): claim returns a job -> loader returns bytes -> processor returns failed(`extraction_not_implemented`) -> `markFinished(Failed, 'extraction_not_implemented', ...)` -> `jobs_failed=1` -> `clinical_document.worker.job_failed` logged.
- Loader failure: claim returns a job -> loader throws `DocumentLoadException` -> `markFinished(Failed, 'source_document_deleted', ...)` -> processor never called -> `jobs_failed=1`.
- Idle path: claim returns null -> sleep called once -> `iteration_count` increments -> heartbeat upserted with `WorkerStatus::Idle`.
- Bounded loop: `--max-iterations=3` runs exactly 3 iterations and exits.
- Shutdown signal: `$shouldStop` flag set after iteration 1 of 5 -> loop exits early -> heartbeat upserted with `WorkerStatus::Stopped` -> exit code 0.
- Sanitized logs: every recorded log's context contains only `ALLOWED_KEYS`. No `forbidden_key` ever appears.

`SqlJobClaimerTest` covers:
- Emitted SQL string matches the portable atomic `UPDATE ... ORDER BY created_at ASC LIMIT 1` claim and the `SELECT ... WHERE lock_token=?` re-fetch.
- Binds: running status, lock token, pending status, and lock token across the update/select in the correct positions.
- 0 rows affected on UPDATE -> returns null without issuing SELECT.
- 1 row affected -> SELECT issued -> `DocumentJob` hydrated correctly with `JobStatus::Running`.
- Retracted-row filter: status='retracted' rows do not appear in the candidate subquery (asserted by SQL string match including `retracted_at IS NULL`).

`OpenEmrDocumentLoaderTest` covers:
- Document deleted -> `DocumentLoadException` with `errorCode='source_document_deleted'`.
- Document expired -> `DocumentLoadException` with `errorCode='source_document_expired'`.
- `get_data()` throws -> `DocumentLoadException` with `errorCode='source_document_unreadable'`, original throwable preserved via `getPrevious()`.
- Happy path -> `DocumentLoadResult{bytes, mimeType, name, byteCount}` with `byteCount === strlen($bytes)`.
- Document factory called exactly once with the integer document id.

`SqlWorkerHeartbeatRepositoryTest` covers:
- `upsert()` emits the exact `INSERT ... ON DUPLICATE KEY UPDATE` SQL with binds in the documented order.
- `findByWorker()` emits the documented SELECT and hydrates the returned row.

`DocumentWorkerValueObjectTest` covers:
- `--worker=intake-extractor` parses to `WorkerName::IntakeExtractor`.
- `--max-iterations=10` parses to int 10; default 0.
- `--one-shot` parses to `maxIterations=1`.
- `--idle-sleep-seconds=2` parses to 2; default 5.
- Unknown flag -> `InvalidArgumentException`.
- Missing `--worker` -> `InvalidArgumentException`.

### Tests not in scope

- **End-to-end**: a docker-compose up + enqueue + observe-worker test is part of the verification plan, not the unit suite.
- **Real DB integration**: SQL is asserted via shape (the `DocumentRepositoryExecutor` stub records queries). M3's deploy-VM verification is the integration check; we don't add a database-backed integration test in M3.
- **Concurrency soak**: two workers fighting over rows is hand-verified during the verification plan; no automated concurrency test in M3.

---

## 8. Docker Compose Service

Add to `docker/development-easy/docker-compose.yml`:

```yaml
  agentforge-worker:
    image: openemr/openemr:flex@sha256:e4562b0c7d3f222ec8f72122ce00d10ffa93f559c38c00ab12c1355394c35d1c
    restart: unless-stopped
    depends_on:
      mysql:
        condition: service_healthy
      openemr:
        condition: service_healthy
    volumes:
      - ${OPENEMR_DIR:-../..}:/var/www/localhost/htdocs/openemr:rw
      - sitesvolume:/var/www/localhost/htdocs/openemr/sites:rw
      - vendordir:/var/www/localhost/htdocs/openemr/vendor:rw
      - logvolume:/var/log
    environment:
      MYSQL_HOST: mysql
      MYSQL_USER: openemr
      MYSQL_PASS: openemr
      AGENTFORGE_WORKER_NAME: "${AGENTFORGE_WORKER_NAME:-intake-extractor}"
      AGENTFORGE_WORKER_MAX_ITERATIONS: "${AGENTFORGE_WORKER_MAX_ITERATIONS:-0}"
      AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS: "${AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS:-5}"
      AGENTFORGE_PATIENT_REF_SALT: "${AGENTFORGE_PATIENT_REF_SALT:-}"
    command: >
      php /var/www/localhost/htdocs/openemr/agent-forge/scripts/process-document-jobs.php
      --worker=${AGENTFORGE_WORKER_NAME:-intake-extractor}
      --max-iterations=${AGENTFORGE_WORKER_MAX_ITERATIONS:-0}
      --idle-sleep-seconds=${AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS:-5}
```

Image and code-mount strategy mirrors the `openemr` service - same image, same code path. No new Dockerfile. `depends_on openemr: service_healthy` ensures the database is migrated to v540 before the worker tries to upsert into `clinical_document_worker_heartbeats`.

`AGENTFORGE_OPENAI_*` and `AGENTFORGE_ANTHROPIC_*` vars are deliberately **not** copied into this service's environment block - the M3 worker doesn't call any LLM. M4 will add them when the real processor lands.

---

## 9. Engineering Approach

### SOLID

- **SRP** - one class, one reason to change.
  - `DocumentJobWorker` orchestrates the loop only.
  - `JobClaimer` owns the atomic claim SQL only.
  - `DocumentLoader` owns retrieval-and-validation only.
  - `DocumentJobProcessor` owns the per-job business logic only (M3 stub, M4 real).
  - `WorkerHeartbeatRepository` owns heartbeat persistence only.
- **OCP** - the worker is open to new processing strategies (M4 plugs `OcrPdfProcessor` and `IntakeFormProcessor` behind `DocumentJobProcessor` without modifying the worker loop).
- **LSP** - any `DocumentJobProcessor` implementation honors the `process(DocumentJob, DocumentLoadResult): ProcessingResult` contract; the worker treats them interchangeably.
- **ISP** - `JobClaimer`, `DocumentLoader`, `DocumentJobProcessor`, `WorkerHeartbeatRepository` are minimal, single-method (or two-method) interfaces. No god-interface.
- **DIP** - `DocumentJobWorker` depends on abstractions only. Concrete wiring is `DocumentJobWorkerFactory`'s job; the worker doesn't know whether storage is MariaDB or in-memory.

### DRY

- All SQL flows through the M2-established `DatabaseExecutor` boundary. No new ad-hoc query helpers.
- All logging flows through `ServiceContainer::getLogger()` -> `SensitiveLogPolicy::sanitizeContext()`. No ad-hoc log formatting.
- Patient hashing uses the existing `PatientRefHasher`. No second hashing path.
- Value objects (`DocumentId`, `PatientId`, `DocumentJobId`) reused; not redefined.
- `DocumentUploadEnqueuerFactory` shape is the template for `DocumentJobWorkerFactory` - same wiring style.

### Modular

- All M3 worker code lives under one namespace: `OpenEMR\AgentForge\Document\Worker\*`. M2 code under `OpenEMR\AgentForge\Document\*` is untouched except for the two repository methods added to existing M2 classes (`DocumentJobRepository` interface, `SqlDocumentJobRepository` impl) - those additions sit naturally with the existing repository, not in a separate "worker repository".
- Tests mirror the namespace: `tests/Tests/Isolated/AgentForge/Document/Worker/*Test.php`.
- The CLI script is the only piece outside `src/` and is intentionally thin (parse args, wire factory, run loop) so the bulk of behavior remains testable in isolation.
- M4 can land its real processor as a new file without touching any M3 worker file *except* the factory's processor selection (which is the legitimate, intentional extension point).

### Coding standards alignment

- `declare(strict_types=1);` on every new file.
- `final readonly` on every value object and DTO.
- Backed string enums for `WorkerName`, `WorkerStatus`, reused enums for `JobStatus`, `DocumentType`, `DocumentRetractionReason`.
- Constructor DI everywhere. No service locator inside business logic. `ServiceContainer::getLogger()` is permitted only inside the factory, not inside the worker classes themselves.
- No `mixed`. `array` types are typed shapes (`@param list<...>` etc.).
- `\Throwable` catches at the worker loop boundary (one place, one log emission, one exit-1 path).
- `CryptoGen` decryption is transparent inside `Document::get_data()` - the worker never touches encryption directly. Per-CLAUDE.md "centralized" rule.
- No PHPStan baseline additions allowed for new files. Level-10 clean.

---

## 10. Acceptance Criteria

A1. Worker boots in `docker compose up agentforge-worker`, logs `clinical_document.worker.started`, and stays running.

A2. With one pending row in `clinical_document_processing_jobs`, the worker atomically claims it: row transitions `pending -> running` with `lock_token` set, `started_at = NOW()`, `attempts` incremented by 1, all in a single committed transaction observable by an outside reader.

A3. After M3's `NoopDocumentJobProcessor` runs, the row transitions `running -> failed` with `error_code = 'extraction_not_implemented'`, `finished_at = NOW()`, `lock_token = NULL`. This is the documented M3 outcome - "no fake success".

A4. With a row whose source document was deleted (`documents.deleted = 1`) before the worker claims it, the worker transitions the job to `failed` with `error_code = 'source_document_deleted'`. The processor is never invoked. Document bytes never read.

A5. With two `agentforge-worker` processes running concurrently against the same DB, no row is processed twice. (Verified by enqueueing 5 jobs and confirming exactly 5 terminal rows with 5 distinct lock_token histories.)

A6. A row with `status='retracted'` is never claimed, regardless of `retracted_at` ordering relative to enqueue. Confirmed by enqueueing then retracting (M2 hook) and seeing the worker skip past it as idle.

A7. The worker upserts a row in `clinical_document_worker_heartbeats` keyed by `worker` on every iteration, with `last_heartbeat_at = NOW()` and current counters.

A8. SIGTERM to the worker process triggers graceful shutdown: current iteration completes, heartbeat row's `status` becomes `stopped` and `stopped_at` is set, process exits 0.

A9. No worker log line contains raw document bytes, document text, extracted fields, full lock tokens, exception messages, or any FORBIDDEN_KEYS context. (Verified by `SensitiveLogPolicy` test plus a manual log scan in the verification plan.)

A10. Document upload through the OpenEMR UI continues to succeed when the `agentforge-worker` service is **stopped**. Pending rows accumulate but no upload fails. (Upload safety contract.)

A11. PHPStan level 10 passes for all new files with zero baseline additions. `composer phpunit-isolated` passes. `composer code-quality` passes.

---

## 11. Definition of Done

- [x] All M3 isolated worker/schema/logging tests pass.
- [x] All production files in Section 3 landed and focused tests stayed green.
- [x] `sql/database.sql` contains the new `clinical_document_worker_heartbeats` table; `version.php` bumped to 540.
- [x] `SensitiveLogPolicy::ALLOWED_KEYS` extended with the new worker keys (Section 6).
- [x] `docker/development-easy/docker-compose.yml` has the `agentforge-worker` service.
- [x] Focused local proof: `vendor/bin/phpunit -c phpunit-isolated.xml tests/Tests/Isolated/AgentForge/Document tests/Tests/Isolated/AgentForge/SensitiveLogPolicyTest.php` passed on 2026-05-05 with 104 tests and 407 assertions.
- [x] Focused `composer phpstan` clean for touched worker/repository/log-policy files; no baseline additions.
- [x] Focused PHPCS clean for touched worker/script/document test surfaces.
- [x] Manual local proof: Docker schema sanity confirmed clinical document tables and no `agentforge_%` tables. The shipped upgrade SQL includes `#IfNotTable clinical_document_worker_heartbeats`; the existing local volume was still at `v_database=539` and needed manual heartbeat-table creation before this proof run.
- [x] Manual local proof: worker stopped, OpenEMR UI upload to `Lab Report` created job `3` as `pending` with `attempts=0`.
- [x] Manual local proof: `agentforge-worker` processed job `3` to `failed` with `error_code='extraction_not_implemented'`, cleared `lock_token`, wrote heartbeat counters, and logged only sanitized metadata.
- [x] Manual local proof: Docker stop completed in 0.2 seconds, exited 0, and wrote `status='stopped'` plus non-null `stopped_at` after adding the shell trap path for disabled `pcntl_signal*` functions.
- [x] Manual local proof: existing `retracted` jobs stayed terminal with `attempts=0`; a worker run skipped them and recorded `jobs_processed=0`.
- [x] Manual local proof: two concurrent one-shot workers processed five synthetic pending jobs exactly once each; all five reached `failed`, each with `attempts=1`, `lock_token=NULL`, and total attempts five.
- [x] Manual local proof: after scoped legacy loader error handling, container logs for invalid synthetic document IDs contained only sanitized `clinical_document.worker.job_failed` lines and no PHP notices.
- [ ] Local automated proof script: a small bash helper under `agent-forge/scripts/` (out of M3 scope as production but in scope to write as a verification artifact) that asserts the above three outcomes. (Optional - if it lands, document. If not, manual proof suffices for M3.)
- [x] `agent-forge/docs/MEMORY.md` updated with the M3 carry-forward notes (Section 13).
- [x] `agent-forge/docs/week2/PLAN-W2.md` M3 status updated with a local proof reference.
- [ ] No production code introduces backwards-compatibility shims, dead branches, or feature flags. Per CLAUDE.md.
- [ ] No comments explaining what code does. Comments only where the WHY is genuinely non-obvious. Per CLAUDE.md.
- [ ] Conventional Commits format on all commits with `Assisted-by: Claude Code` trailer.

---

## 12. Verification Plan (gate-by-gate, per CLAUDE.md AgentForge guardrail)

### Gate 1 - Local UI checks (no automation)

1. `docker compose -f docker/development-easy/docker-compose.yml up -d --wait`.
2. Browse to https://localhost:9300, log in as `admin` / `pass`.
3. Open a test patient, navigate to Documents, upload a small PDF into a category mapped to `lab_pdf`.
4. In phpMyAdmin (http://localhost:8310/), `SELECT * FROM clinical_document_processing_jobs ORDER BY id DESC LIMIT 5;` - confirm row exists with `status='pending'`.
5. Tail the worker logs: `docker compose -f docker/development-easy/docker-compose.yml logs -f agentforge-worker`. Within ~30 seconds expect `clinical_document.worker.job_claimed` then `clinical_document.worker.job_failed` with `error_code=extraction_not_implemented`.
6. Re-query the row - confirm `status='failed'`, `error_code='extraction_not_implemented'`, `finished_at` populated, `lock_token=NULL`.
7. `SELECT * FROM clinical_document_worker_heartbeats;` - confirm one row with `worker='intake-extractor'`, recent `last_heartbeat_at`.

### Gate 2 - Local automated proof

Run `composer phpunit-isolated`, `composer phpstan`, `composer code-quality`. All must pass clean before any push.

### Gate 3 - Git status / diff review

`git status` and `git diff` reviewed against this plan. No surprise modifications. Strict file scope match. Commit messages follow Conventional Commits with `Assisted-by: Claude Code`.

### Gate 4 - Explicit commit/push decision

User confirms before push. Branch: `w2-m3` (already current per session start). PR opened against `master` only after gates 1-3 pass.

### Gate 5 - VM deploy script

`agent-forge/scripts/deploy-vm.sh` runs against the deploy host. M3 brings up the new `agentforge-worker` service alongside existing services. Health check expected to pass for the existing services; new service has no HTTP healthcheck but its presence is verified via `docker compose ps` in deploy script output.

### Gate 6 - VM seed / verify

`agent-forge/scripts/seed-demo-data.sh` (idempotent) re-runs after deploy. `verify-demo-data.sh` runs to confirm baseline data still healthy. New verification step: `docker compose exec mysql mariadb -u openemr -popenemr openemr -e "SELECT worker, status, last_heartbeat_at FROM clinical_document_worker_heartbeats;"` should show a recent heartbeat row.

### Gate 7 - VM UI checks

Same UI flow as Gate 1 but on the VM URL. Confirm worker is consuming jobs in production-like environment.

### Gate 8 - Proof file update

Update or create `agent-forge/docs/epics/EPIC-M3-WORKER-PROOF.md` (or extend the existing EPIC2 proof) with: VM URL, worker log excerpt, sample row before/after, heartbeat row. This is the durable proof artifact.

---

## 13. Risks / Tradeoffs

### Risk: Stale lock recovery deferred to M4

If a worker crashes (OOM, kernel kill, container restart) holding `status='running'` with a `lock_token`, the row is orphaned. M3 has **no reaper**. Such rows will remain `running` indefinitely.

- **Mitigation in M3**: documented in MEMORY.md as an M4 deliverable. M3's `markFinished` clears `lock_token` on terminal transitions, so the orphan window only exists when a process dies between claim and finalize.
- **M4 work**: implement a stale-lock reaper in the supervisor that scans for `status='running' AND started_at < NOW() - INTERVAL N MINUTE`, logs `clinical_document.worker.stale_lock_reaped`, and resets to `pending` (or marks `failed` after a max-recovery threshold).

### Risk: NoopDocumentJobProcessor "fakes" failure

A reviewer might be alarmed that every M3 job ends in `failed`. This is **intentional** - we honor the "no fake success" rule (MEMORY entry). The alternative (mark succeeded with no extraction done) would corrupt downstream state once M4 reads from `clinical_document_processing_jobs`.

- **Mitigation**: error code `extraction_not_implemented` is recognizable and ignorable. M4 ships, replaces the processor, and re-enqueues failed-with-`extraction_not_implemented` rows back to `pending` (one-time backfill script - M4 work).

### Risk: portable claim SQL has limited fairness guarantees

M3 uses a portable atomic `UPDATE ... WHERE status='pending' AND retracted_at IS NULL ORDER BY created_at ASC LIMIT 1`, then re-fetches by `lock_token`. This avoids `SKIP LOCKED` compatibility problems across supported OpenEMR database versions and proved no double-processing in the local two-worker/five-job manual test.

- **Mitigation**: keep the claim path simple and terminal updates lock-token guarded. M4 can revisit queue fairness and stale-lock recovery once supervisor/reaper work exists.

### Risk: OpenEmrDocumentLoader testability and legacy PHP notices

The legacy `Document` class is global, untyped, and instantiated via `new Document($id)` triggering DB I/O in its constructor. The factory-closure injection is a workaround, not an ideal seam.

- **Mitigation**: `DocumentLoader` interface lets tests bypass `OpenEmrDocumentLoader` entirely. `OpenEmrDocumentLoaderTest` exercises the legacy adapter via the closure, asserting only the contract (deleted/expired/throws/happy). Manual proof also showed invalid synthetic document IDs can trigger legacy PHP notices; the loader now uses scoped error handling and the CLI script suppresses display output after OpenEMR bootstrap so those become typed, sanitized load failures instead of raw container noise.

### Risk: Heartbeat write amplification

Every iteration writes to `clinical_document_worker_heartbeats`. At 5-second idle sleep that is ~17,000 writes/day per worker - low for MariaDB but noisy in slow-query logs.

- **Mitigation**: M3 ships with `info`-level heartbeat logs that we can downgrade to `debug` if logs become noisy. Heartbeat DB writes are a single-row upsert with primary-key lookup - cheap. Acceptable for M3.

### Risk: OpenEMR PHP configuration disables `pcntl_signal*`

Manual proof showed OpenEMR's PHP configuration disables the `pcntl_signal*` functions, so PHP signal handlers cannot catch Docker SIGTERM in the worker container. The first local stop test exited 137 after Docker's full 30-second grace period.

- **Mitigation**: `docker/development-easy` keeps `/bin/sh` as PID 1 for the worker service. The shell trap invokes `process-document-jobs.php --mark-stopped`, kills the child worker, waits, and exits 0. The re-run stopped in 0.2 seconds and wrote `status='stopped'` with `stopped_at`.

### Risk: Tightening `patient_id` -> `patient_ref` only is partial

M3 worker code uses `patient_ref` exclusively, but `ALLOWED_KEYS` still permits raw `patient_id` for M2 compatibility. A future epic should remove `patient_id` from the allowlist once all callers migrate. Documented as a follow-up.

### Risk: Multiple workers configured with the same name

If an operator boots two containers both with `AGENTFORGE_WORKER_NAME=intake-extractor`, the heartbeat row's `process_id` will flap between them. M3 doesn't prevent this; the unique key on `worker` means heartbeats merge.

- **Mitigation**: documented as an operator pitfall. M4 supervisor enforces single-instance-per-name.

### Tradeoff: One subdirectory per epic vs flat module

I chose `src/AgentForge/Document/Worker/*` (a subdirectory) over flat `src/AgentForge/Document/*` because M3 introduces 12+ new classes that all share one bounded context (the worker loop). The subdirectory keeps M2 enqueue/retract files visually clean and signals modularity at a glance. The cost is a slightly longer namespace path. Worth it.

### Tradeoff: Strategy seam now vs in M4

We could ship M3 with a hardcoded "always fail" inside `DocumentJobWorker` and let M4 introduce `DocumentJobProcessor`. I chose to ship the seam now because (a) the M2 plan explicitly mentions M4 plugging extraction in, (b) the seam is one interface + one constructor parameter, and (c) introducing it in M4 would force a worker refactor mid-epic. Cost is ~50 lines of code; benefit is M4 moves faster and the worker contract is settled in M3 review.

---

## 14. Notes for Future Epics

- **M4 Supervisor** consumes the `clinical_document_worker_heartbeats` table to know which workers are alive. The unique key on `worker` is the supervisor's primary lookup index.
- **M4 Stale-lock reaper** scans `clinical_document_processing_jobs WHERE status='running' AND started_at < NOW() - INTERVAL N MINUTE` and either resets to `pending` or marks `failed` with `error_code='worker_died'`. M3 leaves `lock_token` set on orphans; reaper clears it.
- **M4 Real processor** swaps `NoopDocumentJobProcessor` for the real OCR/LLM extractor by changing one line in `DocumentJobWorkerFactory::createDefault()`. Worker loop unchanged.
- **M4 Job re-enqueue** for M3-failed rows: a one-shot script that finds `status='failed' AND error_code='extraction_not_implemented'` and resets them to `pending`. Trivial.
- **M5 Evidence retriever** worker reuses the same `DocumentJobWorker` skeleton with a different `WorkerName` and a different processor strategy. M3's worker loop is generic over strategy - `evidence-retriever` is an M5 wiring change, not a worker re-implementation.
- **M5+ Vector indexing** sits inside the M4 real processor's success path. M3 worker loop never touches it.
- **MEMORY carry-forwards to add when M3 lands**:
  - "M3 worker honors upload safety by running out-of-process; do not collapse worker into the request path."
  - "M3 ships with `NoopDocumentJobProcessor` that intentionally fails every job; M4 must replace and re-enqueue."
  - "Stale-lock reaper is M4's responsibility; M3 leaves orphan `running` rows untouched."
  - "Worker claim SQL is portable atomic update plus lock-token re-fetch; do not reintroduce branded table names or unguarded finish updates."
  - "`patient_ref` (HMAC) is the worker's only patient identifier in logs; `patient_id` allowlist entry is M2 legacy."

---

## 15. Implementation Progress

| Step | Status | Notes |
|------|--------|-------|
| Plan written and approved | complete | Local M3 scope; VM proof remains separate |
| Test files (Section 7) | complete | Focused M3/document tests green |
| `LockToken`, `WorkerName`, `WorkerStatus` value objects | complete | |
| `WorkerHeartbeat`, `DocumentLoadResult`, `ProcessingResult` DTOs | complete | |
| `DocumentLoader` + `OpenEmrDocumentLoader` + `DocumentLoadException` | complete | Legacy notices converted to sanitized load failures |
| `JobClaimer` + `SqlJobClaimer` | complete | Portable atomic update plus lock-token re-fetch |
| `WorkerHeartbeatRepository` + `SqlWorkerHeartbeatRepository` | complete | |
| `DocumentJobProcessor` interface + `NoopDocumentJobProcessor` | complete | M3 stub |
| Worker repository finish/find-by-lock-token methods | complete | Split into worker-facing repository contract |
| `DocumentJobWorker` + `DocumentJobWorkerFactory` | complete | Includes stopped-heartbeat path |
| `process-document-jobs.php` CLI + `WorkerArgs` parser | complete | Includes `--mark-stopped` for Docker shell trap |
| `SensitiveLogPolicy` allowlist additions | complete | |
| `clinical_document_worker_heartbeats` DDL + `version.php` bump | complete | Existing local volume needed manual table creation because DB was still 539 |
| `docker-compose.yml` `agentforge-worker` service | complete | Uses shell trap because PHP signal functions are disabled |
| Unit tests passing | complete | 104 document/log tests, 407 assertions on 2026-05-05 |
| PHPStan level 10 clean | complete | Focused touched files |
| Local manual proof | complete | Gates 1-6B passed after fixes |
| Push + PR | not started | |
| VM deploy proof (Gates 5-8) | not started | |
| MEMORY.md update | complete | Local proof notes added |
| PLAN-W2.md status update | complete | Local proof notes added |

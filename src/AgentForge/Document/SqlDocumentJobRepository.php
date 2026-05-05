<?php

/**
 * SQL-backed document job repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DateTimeImmutable;
use InvalidArgumentException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\DefaultDatabaseExecutor;
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorkerRepository;
use OpenEMR\AgentForge\Document\Worker\LockToken;
use RuntimeException;

final readonly class SqlDocumentJobRepository implements DocumentJobRepository, DocumentJobWorkerRepository
{
    private DatabaseExecutor $executor;

    public function __construct(?DatabaseExecutor $executor = null)
    {
        $this->executor = $executor ?? new DefaultDatabaseExecutor();
    }

    public function enqueue(PatientId $patientId, DocumentId $documentId, DocumentType $docType): DocumentJobId
    {
        $this->executor->executeStatement(
            'INSERT IGNORE INTO clinical_document_processing_jobs '
            . '(patient_id, document_id, doc_type, status, attempts, created_at) '
            . 'VALUES (?, ?, ?, ?, 0, NOW())',
            [$patientId->value, $documentId->value, $docType->value, JobStatus::Pending->value],
        );

        $job = $this->findOneByUniqueKey($patientId, $documentId, $docType);
        if ($job?->id === null) {
            throw new RuntimeException('Document job enqueue did not produce a retrievable job id.');
        }

        return $job->id;
    }

    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        return $this->executor->executeAffected(
            'UPDATE clinical_document_processing_jobs '
            . 'SET status = ?, retracted_at = NOW(), retraction_reason = ?, '
            . 'finished_at = COALESCE(finished_at, NOW()), lock_token = NULL '
            . 'WHERE document_id = ? AND status <> ?',
            [
                JobStatus::Retracted->value,
                $reason->value,
                $documentId->value,
                JobStatus::Retracted->value,
            ],
        );
    }

    public function findById(DocumentJobId $id): ?DocumentJob
    {
        $records = $this->executor->fetchRecords(
            'SELECT id, patient_id, document_id, doc_type, status, attempts, lock_token, '
            . 'created_at, started_at, finished_at, error_code, error_message, '
            . 'retracted_at, retraction_reason '
            . 'FROM clinical_document_processing_jobs WHERE id = ? LIMIT 1',
            [$id->value],
        );

        return $records === [] ? null : $this->hydrate($records[0]);
    }

    public function findOneByUniqueKey(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentType $docType,
    ): ?DocumentJob {
        $records = $this->executor->fetchRecords(
            'SELECT id, patient_id, document_id, doc_type, status, attempts, lock_token, '
            . 'created_at, started_at, finished_at, error_code, error_message, '
            . 'retracted_at, retraction_reason '
            . 'FROM clinical_document_processing_jobs '
            . 'WHERE patient_id = ? AND document_id = ? AND doc_type = ? LIMIT 1',
            [$patientId->value, $documentId->value, $docType->value],
        );

        return $records === [] ? null : $this->hydrate($records[0]);
    }

    public function markFinished(
        DocumentJobId $id,
        LockToken $lockToken,
        JobStatus $terminal,
        ?string $errorCode,
        ?string $errorMessage,
    ): int {
        if ($terminal !== JobStatus::Succeeded && $terminal !== JobStatus::Failed) {
            throw new InvalidArgumentException('Finished document jobs must be marked succeeded or failed.');
        }

        return $this->executor->executeAffected(
            'UPDATE clinical_document_processing_jobs '
            . 'SET status = ?, finished_at = NOW(), error_code = ?, error_message = ?, lock_token = NULL '
            . 'WHERE id = ? AND status = ? AND lock_token = ? AND retracted_at IS NULL',
            [
                $terminal->value,
                $errorCode,
                $errorMessage,
                $id->value,
                JobStatus::Running->value,
                $lockToken->value,
            ],
        );
    }

    public function findClaimedByLockToken(LockToken $lockToken): ?DocumentJob
    {
        $records = $this->executor->fetchRecords(
            'SELECT id, patient_id, document_id, doc_type, status, attempts, lock_token, '
            . 'created_at, started_at, finished_at, error_code, error_message, '
            . 'retracted_at, retraction_reason '
            . 'FROM clinical_document_processing_jobs '
            . 'WHERE lock_token = ? AND status = ? LIMIT 1',
            [$lockToken->value, JobStatus::Running->value],
        );

        return $records === [] ? null : $this->hydrate($records[0]);
    }

    /** @param array<string, mixed> $record */
    private function hydrate(array $record): DocumentJob
    {
        return new DocumentJob(
            id: new DocumentJobId($this->intValue($record['id'] ?? null, 'id')),
            patientId: new PatientId($this->intValue($record['patient_id'] ?? null, 'patient_id')),
            documentId: new DocumentId($this->intValue($record['document_id'] ?? null, 'document_id')),
            docType: DocumentType::fromStringOrThrow($this->stringValue($record['doc_type'] ?? null, 'doc_type')),
            status: JobStatus::fromStringOrThrow($this->stringValue($record['status'] ?? null, 'status')),
            attempts: $this->intValue($record['attempts'] ?? null, 'attempts'),
            lockToken: $this->optionalString($record['lock_token'] ?? null),
            createdAt: new DateTimeImmutable($this->stringValue($record['created_at'] ?? null, 'created_at')),
            startedAt: $this->optionalDateTime($record['started_at'] ?? null),
            finishedAt: $this->optionalDateTime($record['finished_at'] ?? null),
            errorCode: $this->optionalString($record['error_code'] ?? null),
            errorMessage: $this->optionalString($record['error_message'] ?? null),
            retractedAt: $this->optionalDateTime($record['retracted_at'] ?? null),
            retractionReason: $this->optionalRetractionReason($record['retraction_reason'] ?? null),
        );
    }

    private function intValue(mixed $value, string $field): int
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (int) $value;
    }

    private function stringValue(mixed $value, string $field): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (string) $value;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Expected nullable scalar.');
        }

        return (string) $value;
    }

    private function optionalDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new DateTimeImmutable($this->stringValue($value, 'date_time'));
    }

    private function optionalRetractionReason(mixed $value): ?DocumentRetractionReason
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DocumentRetractionReason::fromStringOrThrow($this->stringValue($value, 'retraction_reason'));
    }
}

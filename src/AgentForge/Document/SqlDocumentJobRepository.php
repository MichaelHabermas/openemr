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
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorkerRepository;
use OpenEMR\AgentForge\Document\Worker\LockToken;
use OpenEMR\AgentForge\RowHydrator;
use RuntimeException;

final readonly class SqlDocumentJobRepository implements DocumentJobRepository, DocumentJobWorkerRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
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
        $affected = $this->executor->executeAffected(
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
        if ($affected > 0) {
            $this->executor->executeStatement(
                'UPDATE procedure_result pr '
                . 'INNER JOIN clinical_document_promoted_facts pf ON pf.native_table = ? AND pf.native_id = pr.procedure_result_id '
                . 'SET pr.result_status = ?, pr.comments = CONCAT(COALESCE(pr.comments, \'\'), ?), pf.promotion_status = ?, pf.review_status = ?, pf.updated_at = NOW() '
                . 'WHERE pf.document_id = ? AND pf.promotion_status = ?',
                [
                    'procedure_result',
                    'corrected',
                    ' AgentForge source document retracted.',
                    'superseded',
                    'needs_review',
                    $documentId->value,
                    'promoted',
                ],
            );
            $this->executor->executeStatement(
                'UPDATE lists l '
                . 'INNER JOIN clinical_document_promoted_facts pf ON pf.native_table = ? AND pf.native_id = l.id '
                . 'SET l.activity = 0, l.comments = CONCAT(COALESCE(l.comments, \'\'), ?), pf.promotion_status = ?, pf.review_status = ?, pf.updated_at = NOW() '
                . 'WHERE pf.document_id = ? AND pf.promotion_status = ?',
                [
                    'lists',
                    ' AgentForge source document retracted.',
                    'superseded',
                    'needs_review',
                    $documentId->value,
                    'promoted',
                ],
            );
            $this->executor->executeStatement(
                'UPDATE clinical_document_promoted_facts '
                . 'SET promotion_status = ?, review_status = ?, updated_at = NOW() '
                . 'WHERE document_id = ? AND promotion_status IN (?, ?)',
                [
                    'superseded',
                    'needs_review',
                    $documentId->value,
                    'needs_review',
                    'skipped_duplicate',
                ],
            );
        }

        return $affected;
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
            id: new DocumentJobId(RowHydrator::intValue($record['id'] ?? null, 'id')),
            patientId: new PatientId(RowHydrator::intValue($record['patient_id'] ?? null, 'patient_id')),
            documentId: new DocumentId(RowHydrator::intValue($record['document_id'] ?? null, 'document_id')),
            docType: DocumentType::fromStringOrThrow(RowHydrator::stringValue($record['doc_type'] ?? null, 'doc_type')),
            status: JobStatus::fromStringOrThrow(RowHydrator::stringValue($record['status'] ?? null, 'status')),
            attempts: RowHydrator::intValue($record['attempts'] ?? null, 'attempts'),
            lockToken: RowHydrator::nullableString($record['lock_token'] ?? null, 'lock_token'),
            createdAt: new DateTimeImmutable(RowHydrator::stringValue($record['created_at'] ?? null, 'created_at')),
            startedAt: $this->optionalDateTime($record['started_at'] ?? null),
            finishedAt: $this->optionalDateTime($record['finished_at'] ?? null),
            errorCode: RowHydrator::nullableString($record['error_code'] ?? null, 'error_code'),
            errorMessage: RowHydrator::nullableString($record['error_message'] ?? null, 'error_message'),
            retractedAt: $this->optionalDateTime($record['retracted_at'] ?? null),
            retractionReason: $this->optionalRetractionReason($record['retraction_reason'] ?? null),
        );
    }

    private function optionalDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new DateTimeImmutable(RowHydrator::stringValue($value, 'date_time'));
    }

    private function optionalRetractionReason(mixed $value): ?DocumentRetractionReason
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DocumentRetractionReason::fromStringOrThrow(RowHydrator::stringValue($value, 'retraction_reason'));
    }
}

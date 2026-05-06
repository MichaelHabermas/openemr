<?php

/**
 * SQL-backed identity check repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use JsonException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use RuntimeException;

final readonly class SqlDocumentIdentityCheckRepository implements DocumentIdentityCheckRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function saveResult(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentJobId $jobId,
        DocumentType $docType,
        IdentityMatchResult $result,
    ): void {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_identity_checks '
            . '(patient_id, document_id, job_id, doc_type, identity_status, extracted_identifiers_json, '
            . 'matched_patient_fields_json, mismatch_reason, review_required, checked_at, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'identity_status = IF(review_decision IS NULL, VALUES(identity_status), identity_status), '
            . 'extracted_identifiers_json = IF(review_decision IS NULL, VALUES(extracted_identifiers_json), extracted_identifiers_json), '
            . 'matched_patient_fields_json = IF(review_decision IS NULL, VALUES(matched_patient_fields_json), matched_patient_fields_json), '
            . 'mismatch_reason = IF(review_decision IS NULL, VALUES(mismatch_reason), mismatch_reason), '
            . 'review_required = IF(review_decision IS NULL, VALUES(review_required), review_required), checked_at = NOW()',
            [
                $patientId->value,
                $documentId->value,
                $jobId->value,
                $docType->value,
                $result->status->value,
                $this->json($result->extractedIdentifiers),
                $this->json($result->matchedPatientFields),
                $result->mismatchReason,
                $result->reviewRequired ? 1 : 0,
            ],
        );
    }

    public function trustedForEvidence(DocumentJobId $jobId): bool
    {
        $records = $this->executor->fetchRecords(
            'SELECT ic.identity_status, ic.review_decision '
            . 'FROM clinical_document_identity_checks ic '
            . 'INNER JOIN clinical_document_processing_jobs j ON j.id = ic.job_id '
            . 'INNER JOIN documents d ON d.id = ic.document_id '
            . 'WHERE ic.job_id = ? '
            . 'AND j.document_id = ic.document_id '
            . 'AND j.patient_id = ic.patient_id '
            . 'AND d.activity = 1 '
            . 'AND (d.deleted IS NULL OR d.deleted = 0) '
            . 'LIMIT 1',
            [$jobId->value],
        );
        if ($records === []) {
            return false;
        }

        $status = $records[0]['identity_status'] ?? null;
        if (!is_scalar($status)) {
            return false;
        }

        $reviewDecision = $records[0]['review_decision'] ?? null;
        if (is_scalar($reviewDecision) && (string) $reviewDecision === 'approved') {
            return true;
        }

        $identityStatus = IdentityStatus::tryFrom((string) $status);

        return $identityStatus !== null && $identityStatus->trustedForEvidence();
    }

    /** @param mixed $value */
    private function json($value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Identity check JSON serialization failed.', 0, $e);
        }
    }
}

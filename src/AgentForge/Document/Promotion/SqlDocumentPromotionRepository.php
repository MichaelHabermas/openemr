<?php

/**
 * Read-side repository for AgentForge document promotion provenance.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentId;

final readonly class SqlDocumentPromotionRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findReviewOutcomes(PatientId $patientId, DocumentId $documentId): array
    {
        return $this->executor->fetchRecords(
            'SELECT id, patient_id, document_id, job_id, fact_id, fact_type, field_path, display_label, '
            . 'fact_fingerprint, clinical_content_fingerprint, promoted_table, promoted_record_id, '
            . 'outcome, duplicate_key, conflict_reason, confidence, review_status, active, created_at, updated_at '
            . 'FROM clinical_document_promotions '
            . 'WHERE patient_id = ? AND document_id = ? '
            . 'ORDER BY created_at ASC, id ASC',
            [$patientId->value, $documentId->value],
        );
    }
}

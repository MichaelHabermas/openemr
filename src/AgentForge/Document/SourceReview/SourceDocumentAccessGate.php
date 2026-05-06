<?php

/**
 * Shared authorization and lifecycle gate for AgentForge document source review.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;

final readonly class SourceDocumentAccessGate
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function allows(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentJobId $jobId,
        ?int $factId = null,
    ): bool {
        $rows = $this->executor->fetchRecords(
            $this->sql($factId !== null),
            $this->binds($patientId, $documentId, $jobId, $factId),
        );

        return $rows !== [];
    }

    private function sql(bool $requireFact): string
    {
        $sql = 'SELECT j.id '
            . 'FROM clinical_document_processing_jobs j '
            . 'INNER JOIN clinical_document_identity_checks ic ON ic.job_id = j.id '
            . 'INNER JOIN documents d ON d.id = j.document_id ';

        if ($requireFact) {
            $sql .= 'INNER JOIN clinical_document_facts f ON f.job_id = j.id '
                . 'AND f.document_id = j.document_id '
                . 'AND f.patient_id = j.patient_id ';
        }

        $sql .= 'WHERE j.id = ? '
            . 'AND j.patient_id = ? '
            . 'AND j.document_id = ? '
            . 'AND j.status = ? '
            . 'AND j.retracted_at IS NULL '
            . 'AND (ic.identity_status IN (?, ?) OR ic.review_decision = ?) '
            . 'AND (ic.review_required = 0 OR ic.review_decision = ?) '
            . 'AND (d.deleted IS NULL OR d.deleted = 0) '
            . 'AND (d.activity IS NULL OR d.activity = 1) ';

        if ($requireFact) {
            $sql .= 'AND f.id = ? '
                . 'AND f.active = 1 '
                . 'AND f.retracted_at IS NULL '
                . 'AND f.deactivated_at IS NULL ';
        }

        return $sql . 'LIMIT 1';
    }

    /**
     * @return list<mixed>
     */
    private function binds(PatientId $patientId, DocumentId $documentId, DocumentJobId $jobId, ?int $factId): array
    {
        $binds = [
            $jobId->value,
            $patientId->value,
            $documentId->value,
            'succeeded',
            'identity_verified',
            'identity_review_approved',
            'approved',
            'approved',
        ];

        if ($factId !== null) {
            $binds[] = $factId;
        }

        return $binds;
    }
}

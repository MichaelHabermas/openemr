<?php

/**
 * Builds source-scoped and patient-scoped fingerprints for promotion idempotency.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use OpenEMR\AgentForge\Document\DocumentJob;

final readonly class ClinicalContentFingerprint
{
    /**
     * @param array<string, mixed> $stableValue
     */
    public function sourceFactFingerprint(DocumentJob $job, string $factType, string $fieldPath, array $stableValue): string
    {
        return $this->hash([
            'scope' => 'source_fact',
            'patient_id' => $job->patientId->value,
            'document_id' => $job->documentId->value,
            'job_id' => $job->id?->value,
            'doc_type' => $job->docType->value,
            'fact_type' => $factType,
            'field_path' => $fieldPath,
            'value' => $stableValue,
        ]);
    }

    /**
     * @param array<string, mixed> $stableValue
     */
    public function patientClinicalFingerprint(string $factType, string $label, array $stableValue): string
    {
        return $this->hash([
            'scope' => 'patient_clinical_content',
            'fact_type' => $factType,
            'label' => strtolower($label),
            'value' => $stableValue,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

<?php

/**
 * Persisted, cited clinical fact extracted from a source document.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DateTimeImmutable;
use DomainException;
use OpenEMR\AgentForge\Auth\PatientId;

final readonly class DocumentFact
{
    /**
     * @param array<string, mixed> $structuredValue
     * @param array<string, mixed> $citation
     */
    public function __construct(
        public ?int $id,
        public PatientId $patientId,
        public DocumentId $documentId,
        public DocumentJobId $jobId,
        public DocumentType $docType,
        public string $factType,
        public string $certainty,
        public string $factFingerprint,
        public string $clinicalContentFingerprint,
        public string $factText,
        public array $structuredValue,
        public array $citation,
        public ?float $confidence,
        public string $promotionStatus = 'not_promoted',
        public bool $active = true,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $retractedAt = null,
        public ?string $retractionReason = null,
        public ?DateTimeImmutable $deactivatedAt = null,
    ) {
        foreach ([
            'fact type' => $factType,
            'certainty' => $certainty,
            'fact fingerprint' => $factFingerprint,
            'clinical content fingerprint' => $clinicalContentFingerprint,
            'fact text' => $factText,
            'promotion status' => $promotionStatus,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new DomainException(sprintf('Document fact %s is required.', $label));
            }
        }
    }
}

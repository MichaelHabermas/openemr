<?php

/**
 * Deterministic certainty boundary for extracted clinical document facts.
 *
 * Policy: {@see self::classify()} is the single source for downstream bucketing. The model may still
 * emit a `certainty` field on each row/finding for audit and JSON schema; that value is honored only
 * when it is {@see Certainty::NeedsReview} (fail closed). Otherwise confidence, citation strength, and
 * document type determine the bucket.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;

final readonly class CertaintyClassifier
{
    public function __construct(
        private float $verifiedThreshold = 0.85,
        private float $documentFactThreshold = 0.50,
    ) {
        if ($documentFactThreshold <= 0.0 || $documentFactThreshold >= $verifiedThreshold || $verifiedThreshold > 1.0) {
            throw new DomainException('Expected 0 < documentFactThreshold < verifiedThreshold <= 1.');
        }
    }

    public function classify(DocumentType $documentType, LabResultRow | IntakeFormFinding $candidate): Certainty
    {
        if ($candidate->certainty === Certainty::NeedsReview) {
            return Certainty::NeedsReview;
        }

        if ($this->quoteIsWeak($candidate->citation->quoteOrValue) || $candidate->confidence < $this->documentFactThreshold) {
            return Certainty::NeedsReview;
        }

        if ($candidate->confidence >= $this->verifiedThreshold && $this->mapsToChartDestination($documentType, $candidate)) {
            return Certainty::Verified;
        }

        return Certainty::DocumentFact;
    }

    private function quoteIsWeak(string $quote): bool
    {
        $trimmed = trim($quote);

        return strlen($trimmed) < 3 || ctype_digit($trimmed);
    }

    private function mapsToChartDestination(DocumentType $documentType, LabResultRow | IntakeFormFinding $candidate): bool
    {
        return $documentType === DocumentType::LabPdf
            && $candidate instanceof LabResultRow
            && $candidate->testName !== ''
            && $candidate->value !== ''
            && $candidate->unit !== '';
    }
}

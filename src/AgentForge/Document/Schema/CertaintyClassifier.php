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
use OpenEMR\AgentForge\Document\Mapping\DocumentFactDraft;

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
        if (
            $documentType === DocumentType::LabPdf
            && $candidate instanceof LabResultRow
            && $candidate->testName !== ''
            && $candidate->value !== ''
            && $candidate->unit !== ''
        ) {
            return true;
        }

        if ($documentType !== DocumentType::IntakeForm || !$candidate instanceof IntakeFormFinding) {
            return false;
        }

        $field = strtolower($candidate->field . ' ' . $candidate->value);
        return str_contains($field, 'allerg')
            || str_contains($field, 'medication')
            || str_contains($field, 'medicine')
            || str_contains($field, 'current meds')
            || str_contains($field, 'family')
            || str_contains($field, 'problem')
            || str_contains($field, 'condition')
            || str_contains($field, 'concern')
            || str_contains($field, 'chief');
    }

    public function classifyDraft(DocumentType $documentType, DocumentFactDraft $draft): Certainty
    {
        if ($draft->modelCertainty === Certainty::NeedsReview) {
            return Certainty::NeedsReview;
        }

        if ($this->quoteIsWeak($draft->citation->quoteOrValue) || $draft->confidence < $this->documentFactThreshold) {
            return Certainty::NeedsReview;
        }

        if ($draft->confidence >= $this->verifiedThreshold && $this->draftMapsToChartDestination($documentType, $draft)) {
            return Certainty::Verified;
        }

        return Certainty::DocumentFact;
    }

    private function draftMapsToChartDestination(DocumentType $documentType, DocumentFactDraft $draft): bool
    {
        return match ($documentType) {
            DocumentType::LabPdf => $this->labDraftMapsToChart($draft),
            DocumentType::IntakeForm => $this->intakeDraftMapsToChart($draft),
            DocumentType::ReferralDocx => $this->referralDraftMapsToChart($draft),
            DocumentType::ClinicalWorkbook => $this->workbookDraftMapsToChart($draft),
            DocumentType::FaxPacket => false,
            DocumentType::Hl7v2Message => $this->hl7v2DraftMapsToChart($draft),
        };
    }

    private function labDraftMapsToChart(DocumentFactDraft $draft): bool
    {
        if ($draft->factType !== 'lab_result') {
            return false;
        }

        $rawTestName = $draft->structuredValue['test_name'] ?? '';
        $rawValue = $draft->structuredValue['value'] ?? '';
        $rawUnit = $draft->structuredValue['unit'] ?? '';
        $testName = is_string($rawTestName) ? $rawTestName : '';
        $value = is_string($rawValue) ? $rawValue : '';
        $unit = is_string($rawUnit) ? $rawUnit : '';

        return $testName !== '' && $value !== '' && $unit !== '';
    }

    private function intakeDraftMapsToChart(DocumentFactDraft $draft): bool
    {
        if ($draft->factType !== 'intake_finding') {
            return false;
        }

        $rawField = $draft->structuredValue['field'] ?? '';
        $rawValue = $draft->structuredValue['value'] ?? '';
        $field = strtolower(
            (is_string($rawField) ? $rawField : '')
            . ' '
            . (is_string($rawValue) ? $rawValue : ''),
        );

        return str_contains($field, 'allerg')
            || str_contains($field, 'medication')
            || str_contains($field, 'medicine')
            || str_contains($field, 'current meds')
            || str_contains($field, 'family')
            || str_contains($field, 'problem')
            || str_contains($field, 'condition')
            || str_contains($field, 'concern')
            || str_contains($field, 'chief');
    }

    private function referralDraftMapsToChart(DocumentFactDraft $draft): bool
    {
        $factType = strtolower($draft->factType);

        return str_contains($factType, 'referral_reason')
            || str_contains($factType, 'referring_clinician')
            || str_contains($factType, 'specialist')
            || str_contains($factType, 'diagnosis')
            || str_contains($factType, 'problem');
    }

    private function workbookDraftMapsToChart(DocumentFactDraft $draft): bool
    {
        $factType = strtolower($draft->factType);

        return str_contains($factType, 'lab')
            || str_contains($factType, 'medication')
            || str_contains($factType, 'care_gap')
            || str_contains($factType, 'observation');
    }

    private function hl7v2DraftMapsToChart(DocumentFactDraft $draft): bool
    {
        $factType = strtolower($draft->factType);

        return str_contains($factType, 'observation')
            || str_contains($factType, 'demographics')
            || str_contains($factType, 'visit');
    }
}

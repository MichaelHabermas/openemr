<?php

/**
 * Converts HL7 v2 message extraction facts into normalized fact drafts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Schema\ClinicalWorkbookExtraction;
use OpenEMR\AgentForge\Document\Schema\ExtractedClinicalFact;
use OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;

final readonly class Hl7v2MessageFactMapper implements DocumentFactMapper
{
    public function supports(DocumentType $documentType): bool
    {
        return $documentType === DocumentType::Hl7v2Message;
    }

    public function map(
        LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction $extraction,
    ): array {
        if (!$extraction instanceof Hl7v2MessageExtraction) {
            throw new DomainException('Hl7v2MessageFactMapper requires a Hl7v2MessageExtraction.');
        }

        $drafts = [];
        foreach ($extraction->facts as $index => $fact) {
            $drafts[] = $this->draftFromFact($fact, $index);
        }

        return $drafts;
    }

    private function draftFromFact(ExtractedClinicalFact $fact, int $index): DocumentFactDraft
    {
        $fieldPath = $fact->fieldPath !== '' ? $fact->fieldPath : sprintf('facts[%d]', $index);
        $factText = trim($fact->label . ': ' . $fact->value);
        if ($factText === ':') {
            $factText = $fieldPath;
        }

        return new DocumentFactDraft(
            factType: $fact->type,
            fieldPath: $fieldPath,
            displayLabel: $fact->label,
            factText: $factText,
            structuredValue: [
                'type' => $fact->type,
                'label' => $fact->label,
                'value' => $fact->value,
                'certainty' => $fact->certainty->value,
                'confidence' => $fact->confidence,
                'field_path' => $fieldPath,
            ],
            citation: $fact->citation,
            modelCertainty: $fact->certainty,
            confidence: $fact->confidence,
        );
    }
}

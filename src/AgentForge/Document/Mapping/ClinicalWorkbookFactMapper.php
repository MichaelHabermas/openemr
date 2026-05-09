<?php

/**
 * Converts clinical workbook extraction facts into normalized fact drafts.
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

final readonly class ClinicalWorkbookFactMapper implements DocumentFactMapper
{
    public function supports(DocumentType $documentType): bool
    {
        return $documentType === DocumentType::ClinicalWorkbook;
    }

    public function map(
        LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction $extraction,
    ): array {
        if (!$extraction instanceof ClinicalWorkbookExtraction) {
            throw new DomainException('ClinicalWorkbookFactMapper requires a ClinicalWorkbookExtraction.');
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

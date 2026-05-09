<?php

/**
 * Converts lab PDF extraction results into normalized fact drafts.
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
use OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;

final readonly class LabPdfFactMapper implements DocumentFactMapper
{
    public function supports(DocumentType $documentType): bool
    {
        return $documentType === DocumentType::LabPdf;
    }

    public function map(
        LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction $extraction,
    ): array {
        if (!$extraction instanceof LabPdfExtraction) {
            throw new DomainException('LabPdfFactMapper requires a LabPdfExtraction.');
        }

        $drafts = [];
        foreach ($extraction->results as $index => $row) {
            $drafts[] = $this->draftFromRow($row, $index);
        }

        return $drafts;
    }

    private function draftFromRow(LabResultRow $row, int $index): DocumentFactDraft
    {
        $fieldPath = sprintf('results[%d]', $index);

        $textParts = array_filter([
            $row->testName,
            $this->displayLabValue($row),
            $row->referenceRange !== '' ? 'reference range: ' . $row->referenceRange : '',
            'abnormal: ' . $row->abnormalFlag->value,
        ]);

        return new DocumentFactDraft(
            factType: 'lab_result',
            fieldPath: $fieldPath,
            displayLabel: $row->testName,
            factText: implode('; ', $textParts),
            structuredValue: [
                'test_name' => $row->testName,
                'value' => $row->value,
                'unit' => $row->unit,
                'reference_range' => $row->referenceRange,
                'collected_at' => $row->collectedAt,
                'abnormal_flag' => $row->abnormalFlag->value,
                'certainty' => $row->certainty->value,
                'confidence' => $row->confidence,
                'field_path' => $fieldPath,
            ],
            citation: $row->citation,
            modelCertainty: $row->certainty,
            confidence: $row->confidence,
        );
    }

    private function displayLabValue(LabResultRow $row): string
    {
        if ($row->unit === '' || str_ends_with(strtolower($row->value), strtolower(' ' . $row->unit))) {
            return $row->value;
        }

        return $row->value . ' ' . $row->unit;
    }
}

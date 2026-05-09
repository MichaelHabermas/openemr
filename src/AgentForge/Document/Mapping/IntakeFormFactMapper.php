<?php

/**
 * Converts intake form extraction findings into normalized fact drafts.
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
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;

final readonly class IntakeFormFactMapper implements DocumentFactMapper
{
    public function supports(DocumentType $documentType): bool
    {
        return $documentType === DocumentType::IntakeForm;
    }

    public function map(
        LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction $extraction,
    ): array {
        if (!$extraction instanceof IntakeFormExtraction) {
            throw new DomainException('IntakeFormFactMapper requires an IntakeFormExtraction.');
        }

        $drafts = [];
        foreach ($extraction->findings as $index => $finding) {
            $drafts[] = $this->draftFromFinding($finding, $index);
        }

        return $drafts;
    }

    private function draftFromFinding(IntakeFormFinding $finding, int $index): DocumentFactDraft
    {
        $fieldPath = $finding->field !== '' ? $finding->field : sprintf('findings[%d]', $index);

        return new DocumentFactDraft(
            factType: 'intake_finding',
            fieldPath: $fieldPath,
            displayLabel: $finding->field,
            factText: $finding->value,
            structuredValue: [
                'display_label' => $finding->field,
                'field' => $finding->field,
                'value' => $finding->value,
                'certainty' => $finding->certainty->value,
                'confidence' => $finding->confidence,
                'field_path' => $fieldPath,
            ],
            citation: $finding->citation,
            modelCertainty: $finding->certainty,
            confidence: $finding->confidence,
        );
    }
}

<?php

/**
 * Adapts strict extraction DTOs into verifier identity evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\Schema\ClinicalWorkbookExtraction;
use OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\PatientIdentityCandidate;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;

final readonly class ExtractionIdentityEvidenceBuilder
{
    public function build(
        DocumentId $documentId,
        LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction $extraction,
        ?string $documentName,
    ): DocumentIdentityEvidence {
        return new DocumentIdentityEvidence(
            $documentId,
            $extraction->documentType,
            $documentName,
            array_map(
                static fn (PatientIdentityCandidate $candidate): DocumentIdentityCandidate => new DocumentIdentityCandidate(
                    $candidate->kind,
                    $candidate->value,
                    $candidate->fieldPath,
                    $candidate->confidence,
                    $candidate->certainty,
                    $candidate->citation,
                ),
                $extraction->patientIdentity,
            ),
        );
    }
}

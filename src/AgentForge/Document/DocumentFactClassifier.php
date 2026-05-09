<?php

/**
 * Converts strict extraction candidates into M5 persistence buckets.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Document\Mapping\DocumentFactDraft;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\ExtractedClinicalFact;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;

final readonly class DocumentFactClassifier
{
    public function __construct(private CertaintyClassifier $classifier = new CertaintyClassifier())
    {
    }

    public function classify(DocumentJob $job, LabResultRow | IntakeFormFinding | ExtractedClinicalFact $candidate): Certainty
    {
        if ($candidate instanceof ExtractedClinicalFact) {
            return $candidate->certainty === Certainty::NeedsReview ? Certainty::NeedsReview : Certainty::DocumentFact;
        }

        if ($candidate instanceof IntakeFormFinding) {
            return $candidate->certainty === Certainty::NeedsReview ? Certainty::NeedsReview : Certainty::DocumentFact;
        }

        return $this->classifier->classify($job->docType, $candidate);
    }

    public function classifyDraft(DocumentType $documentType, DocumentFactDraft $draft): Certainty
    {
        return $this->classifier->classifyDraft($documentType, $draft);
    }

    public function promotionStatus(Certainty $certainty): string
    {
        return match ($certainty) {
            Certainty::Verified => 'eligible',
            Certainty::DocumentFact => 'document_fact',
            Certainty::NeedsReview => 'needs_review',
        };
    }
}

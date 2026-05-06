<?php

/**
 * Orchestration boundary for document fact promotion.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;

final readonly class DocumentPromotionPipeline implements ClinicalDocumentFactPromotionRepository
{
    public function __construct(private ClinicalDocumentFactPromotionRepository $promotions)
    {
    }

    public function promote(DocumentJob $job, LabPdfExtraction | IntakeFormExtraction $extraction): PromotionSummary
    {
        return $this->promotions->promote($job, $extraction);
    }
}

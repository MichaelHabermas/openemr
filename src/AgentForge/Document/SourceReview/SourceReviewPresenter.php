<?php

/**
 * Presentation boundary for AgentForge cited document source review.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

use OpenEMR\AgentForge\Evidence\DocumentEvidenceFormatting as Fmt;

final readonly class SourceReviewPresenter
{
    public function __construct(
        private string $reviewUrlBase = 'agent_document_source_review.php',
        private string $sourceUrlBase = 'agent_document_source.php',
        private string $pageImageUrlBase = 'agent_document_source_page.php',
    ) {
    }

    public function locator(string $docType, NormalizedDocumentCitation $citation): ReviewLocator
    {
        return match ($docType) {
            'referral_docx' => new ReviewLocator(ReviewLocatorKind::TextAnchor, [
                'section' => $citation->pageOrSection,
                'anchor' => $citation->fieldOrChunkId,
            ]),
            'clinical_workbook' => new ReviewLocator(ReviewLocatorKind::TableCell, [
                'sheet' => $citation->pageOrSection,
                'cell_ref' => $citation->fieldOrChunkId,
            ]),
            'hl7v2_message' => new ReviewLocator(ReviewLocatorKind::MessageField, [
                'message' => $citation->pageOrSection,
                'field_path' => $citation->fieldOrChunkId,
            ]),
            default => $this->pageLocator($citation),
        };
    }

    public function reviewUrl(int $documentId, int $jobId, ?int $factId): string
    {
        $separator = str_contains($this->reviewUrlBase, '?') ? '&' : '?';
        $url = $this->reviewUrlBase
            . $separator . 'document_id=' . rawurlencode((string) $documentId)
            . '&job_id=' . rawurlencode((string) $jobId);
        if ($factId !== null) {
            $url .= '&fact_id=' . rawurlencode((string) $factId);
        }

        return $url;
    }

    public function openSourceUrl(int $patientId, int $documentId, int $jobId): string
    {
        $separator = str_contains($this->sourceUrlBase, '?') ? '&' : '?';

        return $this->sourceUrlBase
            . $separator . 'patient_id=' . rawurlencode((string) $patientId)
            . '&document_id=' . rawurlencode((string) $documentId)
            . '&job_id=' . rawurlencode((string) $jobId)
            . '&as_file=false';
    }

    public function pageImageUrl(int $patientId, int $documentId, int $jobId, ?int $factId, ?int $pageNumber): string
    {
        $separator = str_contains($this->pageImageUrlBase, '?') ? '&' : '?';
        $url = $this->pageImageUrlBase
            . $separator . 'patient_id=' . rawurlencode((string) $patientId)
            . '&document_id=' . rawurlencode((string) $documentId)
            . '&job_id=' . rawurlencode((string) $jobId)
            . '&page=' . rawurlencode((string) max(1, $pageNumber ?? 1));
        if ($factId !== null) {
            $url .= '&fact_id=' . rawurlencode((string) $factId);
        }

        return $url;
    }

    public function inlineMarker(string $docType, string $pageOrSection, string $field): string
    {
        return Fmt::evidenceCitationSuffix($docType, $pageOrSection, $field);
    }

    private function pageLocator(NormalizedDocumentCitation $citation): ReviewLocator
    {
        if ($citation->boundingBox !== null) {
            return new ReviewLocator(ReviewLocatorKind::ImageRegion, [
                'page' => $citation->pageNumber,
                'page_label' => $citation->pageOrSection,
                'field' => $citation->fieldOrChunkId,
                'bounding_box' => $citation->boundingBox,
            ]);
        }

        return new ReviewLocator(ReviewLocatorKind::PageQuote, [
            'page' => $citation->pageNumber,
            'page_label' => $citation->pageOrSection,
            'field' => $citation->fieldOrChunkId,
        ]);
    }
}

<?php

/**
 * Isolated tests for AgentForge source review presentation boundary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\SourceReview;

use OpenEMR\AgentForge\Document\SourceReview\NormalizedDocumentCitation;
use OpenEMR\AgentForge\Document\SourceReview\ReviewLocatorKind;
use OpenEMR\AgentForge\Document\SourceReview\SourceReviewPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceReviewPresenterTest extends TestCase
{
    /**
     * @return array<string, array{string, NormalizedDocumentCitation, string, array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function locatorProvider(): array
    {
        return [
            'lab_pdf with bbox → image_region' => [
                'lab_pdf',
                new NormalizedDocumentCitation('lab_pdf', 'doc:11', 'page 1', 1, 'results[0]', 'LDL 148', ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08]),
                'image_region',
                ['page' => 1, 'page_label' => 'page 1', 'field' => 'results[0]', 'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08]],
            ],
            'lab_pdf no bbox → page_quote' => [
                'lab_pdf',
                new NormalizedDocumentCitation('lab_pdf', 'doc:11', 'page 1', 1, 'results[0]', 'LDL 148', null),
                'page_quote',
                ['page' => 1, 'page_label' => 'page 1', 'field' => 'results[0]'],
            ],
            'intake_form with bbox → image_region' => [
                'intake_form',
                new NormalizedDocumentCitation('intake_form', 'doc:12', 'page 1', 1, 'allergies[0]', 'Penicillin', ['x' => 0.1, 'y' => 0.3, 'width' => 0.2, 'height' => 0.05]),
                'image_region',
                ['page' => 1, 'page_label' => 'page 1', 'field' => 'allergies[0]', 'bounding_box' => ['x' => 0.1, 'y' => 0.3, 'width' => 0.2, 'height' => 0.05]],
            ],
            'fax_packet with bbox → image_region' => [
                'fax_packet',
                new NormalizedDocumentCitation('fax_packet', 'doc:44', 'page 3', 3, 'facts[2]', 'Cardiology consult', ['x' => 0.05, 'y' => 0.15, 'width' => 0.25, 'height' => 0.1]),
                'image_region',
                ['page' => 3, 'page_label' => 'page 3', 'field' => 'facts[2]', 'bounding_box' => ['x' => 0.05, 'y' => 0.15, 'width' => 0.25, 'height' => 0.1]],
            ],
            'fax_packet no bbox → page_quote' => [
                'fax_packet',
                new NormalizedDocumentCitation('fax_packet', 'doc:44', 'page 3', 3, 'facts[2]', 'Cardiology consult', null),
                'page_quote',
                ['page' => 3, 'page_label' => 'page 3', 'field' => 'facts[2]'],
            ],
            'referral_docx → text_anchor' => [
                'referral_docx',
                new NormalizedDocumentCitation('referral_docx', 'doc:55', 'section:reason-for-referral', null, 'paragraph:3', 'Cardiology consult', null),
                'text_anchor',
                ['section' => 'section:reason-for-referral', 'anchor' => 'paragraph:3'],
            ],
            'clinical_workbook → table_cell' => [
                'clinical_workbook',
                new NormalizedDocumentCitation('clinical_workbook', 'doc:66', 'sheet:Labs_Trend', null, 'Care_Gaps!A4:F4', 'Overdue', null),
                'table_cell',
                ['sheet' => 'sheet:Labs_Trend', 'cell_ref' => 'Care_Gaps!A4:F4'],
            ],
            'hl7v2_message → message_field' => [
                'hl7v2_message',
                new NormalizedDocumentCitation('hl7v2_message', 'sha256:abc123', 'message:MSG-ORU-1', null, 'OBX[2].5', '142', null),
                'message_field',
                ['message' => 'message:MSG-ORU-1', 'field_path' => 'OBX[2].5'],
            ],
        ];
    }

    /** @param array<string, mixed> $expectedFields */
    #[DataProvider('locatorProvider')]
    public function testLocator(string $docType, NormalizedDocumentCitation $citation, string $expectedKind, array $expectedFields): void
    {
        $locator = (new SourceReviewPresenter())->locator($docType, $citation);

        $this->assertSame($expectedKind, $locator->kind->value);
        $array = $locator->toArray();
        $this->assertSame($expectedKind, $array['kind']);
        foreach ($expectedFields as $key => $value) {
            $this->assertSame($value, $array[$key], "Field '{$key}' mismatch");
        }
    }

    public function testReviewUrlConstruction(): void
    {
        $url = (new SourceReviewPresenter())->reviewUrl(11, 7, 41);

        $this->assertStringContainsString('agent_document_source_review.php', $url);
        $this->assertStringContainsString('document_id=11', $url);
        $this->assertStringContainsString('job_id=7', $url);
        $this->assertStringContainsString('fact_id=41', $url);
    }

    public function testReviewUrlOmitsFactIdWhenNull(): void
    {
        $url = (new SourceReviewPresenter())->reviewUrl(11, 7, null);

        $this->assertStringNotContainsString('fact_id', $url);
    }

    public function testOpenSourceUrlConstruction(): void
    {
        $url = (new SourceReviewPresenter())->openSourceUrl(900101, 11, 7);

        $this->assertStringContainsString('agent_document_source.php', $url);
        $this->assertStringContainsString('patient_id=900101', $url);
        $this->assertStringContainsString('document_id=11', $url);
        $this->assertStringContainsString('job_id=7', $url);
        $this->assertStringContainsString('as_file=false', $url);
    }

    public function testPageImageUrlConstruction(): void
    {
        $url = (new SourceReviewPresenter())->pageImageUrl(900101, 11, 7, 41, 3);

        $this->assertStringContainsString('agent_document_source_page.php', $url);
        $this->assertStringContainsString('page=3', $url);
        $this->assertStringContainsString('fact_id=41', $url);
    }

    public function testPageImageUrlDefaultsToPageOne(): void
    {
        $url = (new SourceReviewPresenter())->pageImageUrl(900101, 11, 7, null, null);

        $this->assertStringContainsString('page=1', $url);
        $this->assertStringNotContainsString('fact_id', $url);
    }

    public function testInlineMarkerFormat(): void
    {
        $marker = (new SourceReviewPresenter())->inlineMarker('lab_pdf', 'page 1', 'results[0]');

        $this->assertSame('Citation: lab_pdf, page 1, results[0]', $marker);
    }

    public function testCustomUrlBases(): void
    {
        $presenter = new SourceReviewPresenter(
            reviewUrlBase: 'custom-review.php',
            sourceUrlBase: 'custom-source.php',
            pageImageUrlBase: 'custom-page.php',
        );

        $this->assertStringStartsWith('custom-review.php?', $presenter->reviewUrl(1, 2, 3));
        $this->assertStringStartsWith('custom-source.php?', $presenter->openSourceUrl(1, 1, 2));
        $this->assertStringStartsWith('custom-page.php?', $presenter->pageImageUrl(1, 1, 2, null, 1));
    }

    public function testImageRegionHasPageImage(): void
    {
        $this->assertTrue(ReviewLocatorKind::ImageRegion->hasPageImage());
        $this->assertTrue(ReviewLocatorKind::PageQuote->hasPageImage());
    }

    public function testNonPageKindsDoNotHavePageImage(): void
    {
        $this->assertFalse(ReviewLocatorKind::TextAnchor->hasPageImage());
        $this->assertFalse(ReviewLocatorKind::TableCell->hasPageImage());
        $this->assertFalse(ReviewLocatorKind::MessageField->hasPageImage());
    }
}

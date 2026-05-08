<?php

/**
 * Isolated tests for shared AgentForge document citation normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\SourceReview;

use OpenEMR\AgentForge\Document\SourceReview\DocumentCitationNormalizer;
use PHPUnit\Framework\TestCase;

final class DocumentCitationNormalizerTest extends TestCase
{
    public function testNormalizesPageFieldQuoteAndBoundingBox(): void
    {
        $citation = (new DocumentCitationNormalizer())->normalize([
            'source_type' => 'lab_pdf',
            'source_id' => 'doc:11',
            'page_or_section' => '1',
            'field_or_chunk_id' => 'results[0]',
            'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
            'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
        ]);

        $this->assertSame('page 1', $citation->pageOrSection);
        $this->assertSame(1, $citation->pageNumber);
        $this->assertSame('results[0]', $citation->fieldOrChunkId);
        $this->assertSame('LDL Cholesterol 148 mg/dL', $citation->quoteOrValue);
        $this->assertSame(['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08], $citation->boundingBox);
    }

    public function testFallsBackToStructuredFieldPath(): void
    {
        $citation = (new DocumentCitationNormalizer())->normalize(
            [
                'source_type' => 'intake_form',
                'source_id' => 'doc:12',
                'page_or_section' => 'allergies',
                'quote_or_value' => 'Needs review',
            ],
            ['field_path' => 'needs_review[0]'],
        );

        $this->assertSame('allergies', $citation->pageOrSection);
        $this->assertNull($citation->pageNumber);
        $this->assertSame('needs_review[0]', $citation->fieldOrChunkId);
        $this->assertNull($citation->boundingBox);
    }

    public function testDropsOutOfBoundsBoundingBox(): void
    {
        $citation = (new DocumentCitationNormalizer())->normalize([
            'source_type' => 'lab_pdf',
            'source_id' => 'doc:11',
            'page_or_section' => 'page 1',
            'field_or_chunk_id' => 'results[0]',
            'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
            'bounding_box' => ['x' => 0.8, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
        ]);

        $this->assertNull($citation->boundingBox);
    }

    public function testNormalizesFaxPageColonCitationAndKeepsBoundingBox(): void
    {
        $citation = (new DocumentCitationNormalizer())->normalize([
            'source_type' => 'fax_packet',
            'source_id' => 'doc:44',
            'page_or_section' => 'page:3',
            'field_or_chunk_id' => 'facts[2]',
            'quote_or_value' => 'Cardiology consult',
            'bounding_box' => ['x' => 0.05, 'y' => 0.15, 'width' => 0.25, 'height' => 0.1],
        ]);

        $this->assertSame('page 3', $citation->pageOrSection);
        $this->assertSame(3, $citation->pageNumber);
        $this->assertSame(['x' => 0.05, 'y' => 0.15, 'width' => 0.25, 'height' => 0.1], $citation->boundingBox);
    }

    public function testPreservesDocxSectionAndParagraphAnchors(): void
    {
        $citation = (new DocumentCitationNormalizer())->normalize([
            'source_type' => 'referral_docx',
            'source_id' => 'doc:55',
            'page_or_section' => 'section:reason-for-referral',
            'field_or_chunk_id' => 'paragraph:3',
            'quote_or_value' => 'Cardiology consult',
        ]);

        $this->assertSame('section:reason-for-referral', $citation->pageOrSection);
        $this->assertNull($citation->pageNumber);
        $this->assertSame('paragraph:3', $citation->fieldOrChunkId);
    }
}

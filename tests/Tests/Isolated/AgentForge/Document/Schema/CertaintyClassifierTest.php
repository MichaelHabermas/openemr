<?php

/**
 * Isolated tests for deterministic clinical document certainty classification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Schema;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Schema\AbnormalFlag;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CertaintyClassifierTest extends TestCase
{
    /**
     * @return array<string, array{float, string, Certainty}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function labBoundaryProvider(): array
    {
        return [
            'verified threshold inclusive' => [0.85, 'Potassium 5.4 H', Certainty::Verified],
            'below verified threshold' => [0.84999, 'Potassium 5.4 H', Certainty::DocumentFact],
            'document fact threshold inclusive' => [0.50, 'Potassium 5.4 H', Certainty::DocumentFact],
            'below document fact threshold' => [0.49999, 'Potassium 5.4 H', Certainty::NeedsReview],
            'short quote' => [1.0, 'ab', Certainty::NeedsReview],
            'digits-only quote' => [1.0, '42', Certainty::NeedsReview],
        ];
    }

    #[DataProvider('labBoundaryProvider')]
    public function testClassifiesLabBoundaries(float $confidence, string $quote, Certainty $expected): void
    {
        $classifier = new CertaintyClassifier();

        $this->assertSame($expected, $classifier->classify(
            DocumentType::LabPdf,
            self::labRow($confidence, $quote),
        ));
    }

    public function testHighConfidenceIntakeFindingStaysDocumentFact(): void
    {
        $classifier = new CertaintyClassifier();

        $this->assertSame(Certainty::DocumentFact, $classifier->classify(
            DocumentType::IntakeForm,
            self::intakeFinding(0.99, 'chest discomfort'),
        ));
    }

    public function testMismatchedDocumentTypeDoesNotVerifyLabRow(): void
    {
        $classifier = new CertaintyClassifier();

        $this->assertSame(Certainty::DocumentFact, $classifier->classify(
            DocumentType::IntakeForm,
            self::labRow(0.99, 'Potassium 5.4 H'),
        ));
    }

    public function testInvalidThresholdsFailFast(): void
    {
        $this->expectException(DomainException::class);

        new CertaintyClassifier(0.40, 0.50);
    }

    public function testModelNeedsReviewOverridesHighConfidenceVerifiedPath(): void
    {
        $classifier = new CertaintyClassifier();

        $row = new LabResultRow(
            'Potassium',
            '5.4',
            'mmol/L',
            '3.5-5.1',
            '2026-05-01',
            AbnormalFlag::High,
            Certainty::NeedsReview,
            0.99,
            self::citation(DocumentSourceType::LabPdf, 'Potassium 5.4 H'),
        );

        $this->assertSame(Certainty::NeedsReview, $classifier->classify(DocumentType::LabPdf, $row));
    }

    private static function labRow(float $confidence, string $quote): LabResultRow
    {
        return new LabResultRow(
            'Potassium',
            '5.4',
            'mmol/L',
            '3.5-5.1',
            '2026-05-01',
            AbnormalFlag::High,
            Certainty::DocumentFact,
            $confidence,
            self::citation(DocumentSourceType::LabPdf, $quote),
        );
    }

    private static function intakeFinding(float $confidence, string $quote): IntakeFormFinding
    {
        return new IntakeFormFinding(
            'chief_complaint',
            'chest discomfort',
            Certainty::Verified,
            $confidence,
            self::citation(DocumentSourceType::IntakeForm, $quote),
        );
    }

    private static function citation(DocumentSourceType $sourceType, string $quote): DocumentCitation
    {
        return new DocumentCitation(
            $sourceType,
            'source:1',
            'page 1',
            'field:1',
            $quote,
        );
    }
}

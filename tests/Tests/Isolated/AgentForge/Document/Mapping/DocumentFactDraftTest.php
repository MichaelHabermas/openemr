<?php

/**
 * Isolated tests for DocumentFactDraft value object construction and validation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\Mapping\DocumentFactDraft;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentFactDraftTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $citation = self::citation();
        $draft = new DocumentFactDraft(
            factType: 'lab_result',
            fieldPath: 'results[0]',
            displayLabel: 'Potassium',
            factText: 'Potassium; 5.4 mmol/L',
            structuredValue: ['test_name' => 'Potassium', 'value' => '5.4'],
            citation: $citation,
            modelCertainty: Certainty::Verified,
            confidence: 0.95,
        );

        self::assertSame('lab_result', $draft->factType);
        self::assertSame('results[0]', $draft->fieldPath);
        self::assertSame('Potassium', $draft->displayLabel);
        self::assertSame('Potassium; 5.4 mmol/L', $draft->factText);
        self::assertSame(['test_name' => 'Potassium', 'value' => '5.4'], $draft->structuredValue);
        self::assertSame($citation, $draft->citation);
        self::assertSame(Certainty::Verified, $draft->modelCertainty);
        self::assertSame(0.95, $draft->confidence);
    }

    /**
     * @return array<string, array{string, string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function emptyFieldProvider(): array
    {
        return [
            'empty factType' => ['', 'results[0]', 'some text'],
            'whitespace factType' => ['   ', 'results[0]', 'some text'],
            'empty fieldPath' => ['lab_result', '', 'some text'],
            'whitespace fieldPath' => ['lab_result', '  ', 'some text'],
            'empty factText' => ['lab_result', 'results[0]', ''],
            'whitespace factText' => ['lab_result', 'results[0]', '   '],
        ];
    }

    #[DataProvider('emptyFieldProvider')]
    public function testRejectsEmptyRequiredFields(string $factType, string $fieldPath, string $factText): void
    {
        $this->expectException(DomainException::class);

        new DocumentFactDraft(
            factType: $factType,
            fieldPath: $fieldPath,
            displayLabel: 'label',
            factText: $factText,
            structuredValue: [],
            citation: self::citation(),
            modelCertainty: Certainty::DocumentFact,
            confidence: 0.80,
        );
    }

    public function testAllowsEmptyDisplayLabel(): void
    {
        $draft = new DocumentFactDraft(
            factType: 'lab_result',
            fieldPath: 'results[0]',
            displayLabel: '',
            factText: 'some fact text',
            structuredValue: [],
            citation: self::citation(),
            modelCertainty: Certainty::DocumentFact,
            confidence: 0.80,
        );

        self::assertSame('', $draft->displayLabel);
    }

    private static function citation(): DocumentCitation
    {
        return new DocumentCitation(
            DocumentSourceType::LabPdf,
            'source:1',
            'page 1',
            'field:1',
            'Potassium 5.4',
        );
    }
}

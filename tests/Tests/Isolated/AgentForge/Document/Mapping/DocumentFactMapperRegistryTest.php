<?php

/**
 * Isolated tests for DocumentFactMapperRegistry first-match dispatch.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Mapping\ClinicalWorkbookFactMapper;
use OpenEMR\AgentForge\Document\Mapping\DocumentFactMapper;
use OpenEMR\AgentForge\Document\Mapping\DocumentFactMapperRegistry;
use OpenEMR\AgentForge\Document\Mapping\FaxPacketFactMapper;
use OpenEMR\AgentForge\Document\Mapping\Hl7v2MessageFactMapper;
use OpenEMR\AgentForge\Document\Mapping\IntakeFormFactMapper;
use OpenEMR\AgentForge\Document\Mapping\LabPdfFactMapper;
use OpenEMR\AgentForge\Document\Mapping\ReferralDocxFactMapper;
use OpenEMR\AgentForge\Document\Schema\AbnormalFlag;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentFactMapperRegistryTest extends TestCase
{
    /**
     * @return array<string, array{DocumentType, class-string<DocumentFactMapper>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function mapperForProvider(): array
    {
        return [
            'lab_pdf' => [DocumentType::LabPdf, LabPdfFactMapper::class],
            'intake_form' => [DocumentType::IntakeForm, IntakeFormFactMapper::class],
            'referral_docx' => [DocumentType::ReferralDocx, ReferralDocxFactMapper::class],
            'clinical_workbook' => [DocumentType::ClinicalWorkbook, ClinicalWorkbookFactMapper::class],
            'fax_packet' => [DocumentType::FaxPacket, FaxPacketFactMapper::class],
            'hl7v2_message' => [DocumentType::Hl7v2Message, Hl7v2MessageFactMapper::class],
        ];
    }

    /**
     * @param class-string<DocumentFactMapper> $expectedClass
     */
    #[DataProvider('mapperForProvider')]
    public function testMapperForReturnsCorrectMapper(DocumentType $docType, string $expectedClass): void
    {
        $registry = self::fullRegistry();

        self::assertInstanceOf($expectedClass, $registry->mapperFor($docType));
    }

    public function testMapperForThrowsOnEmptyRegistry(): void
    {
        $registry = new DocumentFactMapperRegistry();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No fact mapper registered for document type "lab_pdf".');

        $registry->mapperFor(DocumentType::LabPdf);
    }

    public function testFirstMatchWinsWhenMultipleMappersSupportSameType(): void
    {
        $first = new LabPdfFactMapper();
        $second = new LabPdfFactMapper();
        $registry = new DocumentFactMapperRegistry($first, $second);

        self::assertSame($first, $registry->mapperFor(DocumentType::LabPdf));
    }

    public function testMapDelegatesToCorrectMapper(): void
    {
        $registry = self::fullRegistry();

        $extraction = new LabPdfExtraction(
            DocumentType::LabPdf,
            'Acme Labs',
            '2026-05-01',
            [self::labRow()],
        );

        $drafts = $registry->map(DocumentType::LabPdf, $extraction);

        self::assertCount(1, $drafts);
        self::assertSame('lab_result', $drafts[0]->factType);
    }

    private static function fullRegistry(): DocumentFactMapperRegistry
    {
        return new DocumentFactMapperRegistry(
            new LabPdfFactMapper(),
            new IntakeFormFactMapper(),
            new ReferralDocxFactMapper(),
            new ClinicalWorkbookFactMapper(),
            new FaxPacketFactMapper(),
            new Hl7v2MessageFactMapper(),
        );
    }

    private static function labRow(): LabResultRow
    {
        return new LabResultRow(
            'Potassium',
            '5.4',
            'mmol/L',
            '3.5-5.1',
            '2026-05-01',
            AbnormalFlag::High,
            Certainty::DocumentFact,
            0.90,
            new DocumentCitation(
                DocumentSourceType::LabPdf,
                'source:1',
                'page 1',
                'field:1',
                'Potassium 5.4 H',
            ),
        );
    }
}

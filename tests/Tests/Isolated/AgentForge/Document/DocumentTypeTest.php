<?php

/**
 * Isolated tests for AgentForge document type enum.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentTypeTest extends TestCase
{
    public function testSupportedTypesAreExactlyWeekTwoTypes(): void
    {
        $this->assertSame([
            'lab_pdf',
            'intake_form',
            'referral_docx',
            'clinical_workbook',
            'fax_packet',
            'hl7v2_message',
        ], array_map(
            static fn (DocumentType $type): string => $type->value,
            DocumentType::cases(),
        ));
    }

    public function testFromStringOrThrowAcceptsSupportedTypes(): void
    {
        $this->assertSame(DocumentType::LabPdf, DocumentType::fromStringOrThrow('lab_pdf'));
        $this->assertSame(DocumentType::IntakeForm, DocumentType::fromStringOrThrow('intake_form'));
        $this->assertSame(DocumentType::ReferralDocx, DocumentType::fromStringOrThrow('referral_docx'));
        $this->assertSame(DocumentType::ClinicalWorkbook, DocumentType::fromStringOrThrow('clinical_workbook'));
        $this->assertSame(DocumentType::FaxPacket, DocumentType::fromStringOrThrow('fax_packet'));
        $this->assertSame(DocumentType::Hl7v2Message, DocumentType::fromStringOrThrow('hl7v2_message'));
    }

    public function testFromStringOrThrowRejectsUnsupportedType(): void
    {
        $this->expectException(DomainException::class);

        DocumentType::fromStringOrThrow('referral_fax');
    }

    public function testOnlyMatureDocumentTypesAreRuntimeIngestionSupported(): void
    {
        $this->assertTrue(DocumentType::LabPdf->runtimeIngestionSupported());
        $this->assertTrue(DocumentType::IntakeForm->runtimeIngestionSupported());
        $this->assertFalse(DocumentType::ReferralDocx->runtimeIngestionSupported());
        $this->assertFalse(DocumentType::ClinicalWorkbook->runtimeIngestionSupported());
        $this->assertFalse(DocumentType::FaxPacket->runtimeIngestionSupported());
        $this->assertFalse(DocumentType::Hl7v2Message->runtimeIngestionSupported());
    }
}

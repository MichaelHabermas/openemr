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
        $this->assertSame(['lab_pdf', 'intake_form'], array_map(
            static fn (DocumentType $type): string => $type->value,
            DocumentType::cases(),
        ));
    }

    public function testFromStringOrThrowAcceptsSupportedTypes(): void
    {
        $this->assertSame(DocumentType::LabPdf, DocumentType::fromStringOrThrow('lab_pdf'));
        $this->assertSame(DocumentType::IntakeForm, DocumentType::fromStringOrThrow('intake_form'));
    }

    public function testFromStringOrThrowRejectsUnsupportedType(): void
    {
        $this->expectException(DomainException::class);

        DocumentType::fromStringOrThrow('referral_fax');
    }
}

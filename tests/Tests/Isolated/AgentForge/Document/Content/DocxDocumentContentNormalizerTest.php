<?php

/**
 * Isolated tests for DOCX referral content normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationException;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationRequest;
use OpenEMR\AgentForge\Document\Content\DocxDocumentContentNormalizer;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\Tests\Isolated\AgentForge\Support\TickingMonotonicClock;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class DocxDocumentContentNormalizerTest extends TestCase
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function testDocxNormalizerExtractsParagraphsHeadersFootersTablesAndStableAnchors(): void
    {
        $bytes = self::docxBytes();
        $normalizer = new DocxDocumentContentNormalizer(new TickingMonotonicClock([100, 133]), 1024 * 1024);
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(77),
            DocumentType::ReferralDocx,
            new DocumentLoadResult($bytes, self::DOCX_MIME, 'chen-referral.docx'),
        );

        $first = $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
        $second = $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));

        $this->assertTrue($normalizer->supports($request));
        $this->assertFalse($normalizer->supports(new DocumentContentNormalizationRequest(
            new DocumentId(78),
            DocumentType::ClinicalWorkbook,
            new DocumentLoadResult($bytes, self::DOCX_MIME, 'workbook.docx'),
        )));
        $this->assertSame(hash('sha256', $bytes), $first->source->sha256);
        $this->assertSame(self::DOCX_MIME, $first->source->mimeType);
        $this->assertSame('docx', $first->telemetry()->normalizer);
        $this->assertSame(5, $first->telemetry()->textSectionCount);
        $this->assertSame(1, $first->telemetry()->tableCount);
        $this->assertSame(33, $first->telemetry()->normalizationElapsedMs);
        $this->assertSame(['unsupported_embedded_object'], $first->telemetry()->warningCodes);

        $anchors = array_map(static fn ($section): string => $section->sourceReference, $first->textSections);
        $this->assertSame($anchors, array_map(static fn ($section): string => $section->sourceReference, $second->textSections));
        $this->assertSame([
            'section:reason-for-referral; paragraph:1',
            'section:reason-for-referral; paragraph:2',
            'section:reason-for-referral; paragraph:3',
            'section:footer; paragraph:4',
            'section:header; paragraph:5',
        ], $anchors);
        $this->assertSame('Evaluation and co-management', $first->textSections[1]->text);
        $this->assertSame('Next line', $first->textSections[2]->text);
        $this->assertSame('table:1', $first->tables[0]->tableId);
        $this->assertSame('reason_for_referral', $first->tables[0]->columns[0]);
        $this->assertSame('table:1.row:1', $first->tables[0]->rows[0]['_anchor']);
    }

    public function testOversizedDocxFailsWithStablePhiSafeError(): void
    {
        $normalizer = new DocxDocumentContentNormalizer(new TickingMonotonicClock([100]), 4);
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(77),
            DocumentType::ReferralDocx,
            new DocumentLoadResult('Jane Doe DOCX bytes', self::DOCX_MIME, 'jane-referral.docx'),
        );

        try {
            $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
            $this->fail('Expected oversized DOCX to fail safely.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
            $this->assertSame('DOCX content normalization failed.', $exception->getMessage());
            $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
            $this->assertStringNotContainsString('jane-referral.docx', $exception->getMessage());
        }
    }

    public function testMalformedDocxFailsWithStablePhiSafeError(): void
    {
        $normalizer = new DocxDocumentContentNormalizer(new TickingMonotonicClock([100]), 1024);
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(77),
            DocumentType::ReferralDocx,
            new DocumentLoadResult('not-a-zip with Jane Doe', self::DOCX_MIME, 'jane-referral.docx'),
        );

        try {
            $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
            $this->fail('Expected malformed DOCX to fail safely.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
            $this->assertSame('DOCX content normalization failed.', $exception->getMessage());
            $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
            $this->assertStringNotContainsString('jane-referral.docx', $exception->getMessage());
        }
    }

    public function testOversizedUncompressedXmlPartFailsBeforeParsing(): void
    {
        $normalizer = new DocxDocumentContentNormalizer(
            new TickingMonotonicClock([100]),
            maxXmlPartBytes: 128,
        );
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(77),
            DocumentType::ReferralDocx,
            new DocumentLoadResult(self::docxBytes(documentXml: self::documentXmlWithText(str_repeat('A', 512))), self::DOCX_MIME, 'jane-referral.docx'),
        );

        try {
            $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100, 100]), 1_000));
            $this->fail('Expected oversized uncompressed DOCX XML to fail safely.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
            $this->assertSame('DOCX content normalization failed.', $exception->getMessage());
            $this->assertStringNotContainsString('jane-referral.docx', $exception->getMessage());
        }
    }

    public function testTableCellTextSharesTextBudget(): void
    {
        $normalizer = new DocxDocumentContentNormalizer(
            new TickingMonotonicClock([100]),
            maxTextChars: 64,
        );
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(77),
            DocumentType::ReferralDocx,
            new DocumentLoadResult(self::docxBytes(documentXml: self::documentXmlWithLargeTableCell()), self::DOCX_MIME, 'jane-referral.docx'),
        );

        try {
            $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100, 100, 100, 100]), 1_000));
            $this->fail('Expected oversized DOCX table text to fail safely.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
            $this->assertSame('DOCX content normalization failed.', $exception->getMessage());
            $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
        }
    }

    private static function docxBytes(?string $documentXml = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-docx-test-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $zip->addFromString('word/document.xml', $documentXml ?? self::documentXml());
        $zip->addFromString('word/_rels/document.xml.rels', self::relsXml());
        $zip->addFromString('word/header1.xml', self::headerXml());
        $zip->addFromString('word/footer1.xml', self::footerXml());
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        self::assertIsString($bytes);

        return $bytes;
    }

    private static function documentXmlWithText(string $text): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>%s</w:t></w:r></w:p></w:body></w:document>',
            htmlspecialchars($text, ENT_XML1),
        );
    }

    private static function documentXmlWithLargeTableCell(): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Header</w:t></w:r></w:p></w:tc></w:tr><w:tr><w:tc><w:p><w:r><w:t>%s</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>',
            str_repeat('Jane Doe ', 20),
        );
    }

    private static function documentXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Reason for Referral</w:t></w:r></w:p>
    <w:p><w:r><w:t>Evaluation and </w:t></w:r><w:hyperlink><w:r><w:t>co-management</w:t></w:r></w:hyperlink></w:p>
    <w:p><w:r><w:t>Next</w:t></w:r><w:tab/><w:r><w:t>line</w:t></w:r><w:del><w:r><w:t> deleted PHI</w:t></w:r></w:del></w:p>
    <w:tbl>
      <w:tr><w:tc><w:p><w:r><w:t>Reason for Referral</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Status</w:t></w:r></w:p></w:tc></w:tr>
      <w:tr><w:tc><w:p><w:r><w:t>ASCVD risk</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>review</w:t></w:r></w:p></w:tc></w:tr>
    </w:tbl>
  </w:body>
</w:document>
XML;
    }

    private static function relsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;
    }

    private static function headerXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p><w:r><w:t>Referral header</w:t></w:r></w:p>
</w:hdr>
XML;
    }

    private static function footerXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p><w:r><w:t>Referral footer</w:t></w:r></w:p>
</w:ftr>
XML;
    }
}

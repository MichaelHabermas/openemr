<?php

/**
 * Isolated tests for XLSX clinical workbook content normalization.
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
use OpenEMR\AgentForge\Document\Content\XlsxDocumentContentNormalizer;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\Tests\Isolated\AgentForge\Support\TickingMonotonicClock;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class XlsxDocumentContentNormalizerTest extends TestCase
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function testXlsxNormalizerExtractsSheetsTablesTextAndStableAnchors(): void
    {
        $bytes = self::workbookBytes();
        $normalizer = new XlsxDocumentContentNormalizer(new TickingMonotonicClock([100, 129]));
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(91),
            DocumentType::ClinicalWorkbook,
            new DocumentLoadResult($bytes, self::XLSX_MIME, 'chen-workbook.xlsx'),
        );

        $content = $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));

        $this->assertTrue($normalizer->supports($request));
        $this->assertFalse($normalizer->supports(new DocumentContentNormalizationRequest(
            new DocumentId(92),
            DocumentType::ReferralDocx,
            new DocumentLoadResult($bytes, self::XLSX_MIME, 'wrong-type.xlsx'),
        )));
        $this->assertSame('xlsx', $content->telemetry()->normalizer);
        $this->assertSame(self::XLSX_MIME, $content->source->mimeType);
        $this->assertSame(strlen($bytes), $content->telemetry()->sourceByteCount);
        $this->assertSame(2, $content->telemetry()->tableCount);
        $this->assertSame(3, $content->telemetry()->textSectionCount);
        $this->assertSame(29, $content->telemetry()->normalizationElapsedMs);
        $this->assertSame([
            'merged_cell_range_present',
            'hidden_row_or_column_skipped',
            'formula_cell_cached_value_used',
            'hidden_sheet_skipped',
        ], $content->telemetry()->warningCodes);

        $patientSections = array_map(static fn ($section): string => $section->sourceReference, $content->textSections);
        $this->assertContains('sheet:Patient; Patient!B2', $patientSections);
        $this->assertContains('sheet:Patient; Patient!B3', $patientSections);
        $this->assertContains('sheet:Patient; Patient!B4', $patientSections);
        $this->assertStringContainsString('DOB: 1968-03-12', $content->textSections[1]->text);

        $this->assertSame('sheet:Patient', $content->tables[0]->tableId);
        $this->assertSame('Patient!A2:B2', $content->tables[0]->rows[0]['_anchor']);
        $this->assertSame('Patient!B2', $content->tables[0]->rows[0]['_cell_value']);

        $this->assertSame('sheet:Labs_Trend', $content->tables[1]->tableId);
        $this->assertSame('Labs_Trend!A3:H3', $content->tables[1]->rows[1]['_anchor']);
        $this->assertSame('142', $content->tables[1]->rows[1]['2026_04_12']);
        $this->assertSame('Labs_Trend!H3', $content->tables[1]->rows[1]['_cell_2026_04_12']);
        $this->assertSame('3', $content->tables[1]->rows[2]['formula_result']);
        $this->assertSame('Labs_Trend!I4', $content->tables[1]->rows[2]['_cell_formula_result']);
        $payload = json_encode($content->tables, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Hidden row PHI should not appear', $payload);
        $this->assertStringNotContainsString('Hidden column PHI should not appear', $payload);
    }

    public function testRealChenWorkbookNormalizesWithExpectedWorkbookAnchors(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../../../../../../agent-forge/docs/example-documents/xlsx/p01-chen-workbook.xlsx');
        $this->assertIsString($bytes);
        $normalizer = new XlsxDocumentContentNormalizer(new TickingMonotonicClock([100, 100]));

        $content = $normalizer->normalize(new DocumentContentNormalizationRequest(
            new DocumentId(91),
            DocumentType::ClinicalWorkbook,
            new DocumentLoadResult($bytes, self::XLSX_MIME, 'p01-chen-workbook.xlsx'),
        ), new Deadline(new TickingMonotonicClock([100]), 1_000));

        $payload = json_encode([
            'sections' => $content->textSections,
            'tables' => $content->tables,
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('xlsx', $content->telemetry()->normalizer);
        $this->assertStringContainsString('Patient!B2', $payload);
        $this->assertStringContainsString('Labs_Trend!H3', $payload);
        $this->assertStringContainsString('Care_Gaps!A4:F4', $payload);
        $this->assertStringNotContainsString('p01-chen-workbook.xlsx', $payload);
    }

    public function testMalformedOversizedAndUnsafeXlsxFailuresAreStableAndPhiSafe(): void
    {
        foreach ([
            'malformed' => ['Jane Doe raw bytes', 10_485_760],
            'oversized' => [self::workbookBytes(), 8],
            'macro' => [self::unsafeZipBytes('xl/vbaProject.bin'), 10_485_760],
            'external_relationship' => [self::workbookBytes(addExternalRelationship: true), 10_485_760],
            'external_link_part' => [self::unsafeZipBytes('xl/externalLinks/externalLink1.xml'), 10_485_760],
            'unsafe_content_type' => [self::unsafeContentTypeBytes(), 10_485_760],
            'unsafe_relationship_type' => [self::unsafeRelationshipTypeBytes(), 10_485_760],
        ] as $case) {
            [$bytes, $maxBytes] = $case;
            $normalizer = new XlsxDocumentContentNormalizer(new TickingMonotonicClock([100]), maxSourceBytes: $maxBytes);
            $request = new DocumentContentNormalizationRequest(
                new DocumentId(91),
                DocumentType::ClinicalWorkbook,
                new DocumentLoadResult($bytes, self::XLSX_MIME, 'jane-workbook.xlsx'),
            );

            try {
                $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
                $this->fail('Expected unsafe XLSX to fail safely.');
            } catch (DocumentContentNormalizationException $exception) {
                $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
                $this->assertSame('XLSX content normalization failed.', $exception->getMessage());
                $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
                $this->assertStringNotContainsString('jane-workbook.xlsx', $exception->getMessage());
            }
        }
    }

    public function testFormulaWithoutCachedValueIsSkippedWithWarning(): void
    {
        $normalizer = new XlsxDocumentContentNormalizer(new TickingMonotonicClock([100, 100]));
        $content = $normalizer->normalize(new DocumentContentNormalizationRequest(
            new DocumentId(91),
            DocumentType::ClinicalWorkbook,
            new DocumentLoadResult(self::workbookBytes(includeFormulaCache: false), self::XLSX_MIME, 'workbook.xlsx'),
        ), new Deadline(new TickingMonotonicClock([100]), 1_000));

        $this->assertContains('formula_cell_skipped', $content->telemetry()->warningCodes);
        $payload = json_encode($content->tables, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SUM', $payload);
    }

    private static function workbookBytes(bool $includeFormulaCache = true, bool $addExternalRelationship = false): string
    {
        $spreadsheet = new Spreadsheet();
        $patient = $spreadsheet->getActiveSheet();
        $patient->setTitle('Patient');
        $patient->fromArray([
            ['Field', 'Value'],
            ['Name', 'Margaret Chen'],
            ['DOB', '1968-03-12'],
            ['MRN', 'BHS-2847163'],
        ]);

        $labs = new Worksheet($spreadsheet, 'Labs_Trend');
        $spreadsheet->addSheet($labs);
        $labs->fromArray([
            ['Test', 'LOINC', 'Units', 'Reference_Range', '2024-10-18', '2025-04-14', '2025-10-20', '2026-04-12', 'Formula_Result'],
            ['Total cholesterol', '2093-3', 'mg/dL', '<200', 200, 224, 221, 218, ''],
            ['LDL cholesterol (calc)', '13457-7', 'mg/dL', '<100', 130, 138, 141, 142, ''],
            ['Formula row', '0000-0', 'count', '', '', '', '', '', '=SUM(1,2)'],
        ]);
        $labs->getCell('I4')->setCalculatedValue($includeFormulaCache ? 3 : null);
        $labs->mergeCells('A5:B5');
        $labs->getCell('A5')->setValue('merged note');
        $labs->getCell('J1')->setValue(44924);
        $labs->getStyle('J1')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $labs->getCell('A6')->setValue('Hidden row PHI should not appear');
        $labs->getRowDimension(6)->setVisible(false);
        $labs->getCell('K2')->setValue('Hidden column PHI should not appear');
        $labs->getColumnDimension('K')->setVisible(false);

        $hidden = new Worksheet($spreadsheet, 'Hidden_Notes');
        $spreadsheet->addSheet($hidden);
        $hidden->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $hidden->setCellValue('A1', 'Hidden PHI should not appear');

        return self::bytesFromSpreadsheet(
            $spreadsheet,
            addExternalRelationship: $addExternalRelationship,
            removeFormulaCache: !$includeFormulaCache,
        );
    }

    private static function bytesFromSpreadsheet(
        Spreadsheet $spreadsheet,
        bool $addExternalRelationship,
        bool $removeFormulaCache = false,
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-xlsx-test-');
        self::assertIsString($path);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        if ($addExternalRelationship) {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            self::assertIsString($rels);
            $rels = str_replace(
                '</Relationships>',
                '<Relationship Id="rIdExternal" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.invalid/" TargetMode="External"/></Relationships>',
                $rels,
            );
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
            $zip->close();
        }
        if ($removeFormulaCache) {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml'] as $sheetPath) {
                $xml = $zip->getFromName($sheetPath);
                if (!is_string($xml) || !str_contains($xml, 'r="I4"')) {
                    continue;
                }
                $xml = preg_replace('/(<c[^>]+r="I4"[^>]*>.*?<f[^>]*>.*?<\/f>)<v>.*?<\/v>(.*?<\/c>)/s', '$1$2', $xml) ?? $xml;
                $zip->addFromString($sheetPath, $xml);
            }
            $zip->close();
        }
        $bytes = file_get_contents($path);
        unlink($path);
        self::assertIsString($bytes);

        return $bytes;
    }

    private static function unsafeZipBytes(string $unsafePath): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-xlsx-unsafe-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString($unsafePath, 'binary');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        self::assertIsString($bytes);

        return $bytes;
    }

    private static function unsafeContentTypeBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-xlsx-unsafe-content-type-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString(
            '[Content_Types].xml',
            '<Types><Override PartName="/xl/workbook.xml" ContentType="application/vnd.ms-excel.sheet.macroEnabled.main+xml"/></Types>',
        );
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        self::assertIsString($bytes);

        return $bytes;
    }

    private static function unsafeRelationshipTypeBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-xlsx-unsafe-relationship-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="externalLinks/externalLink1.xml"/></Relationships>',
        );
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        self::assertIsString($bytes);

        return $bytes;
    }
}

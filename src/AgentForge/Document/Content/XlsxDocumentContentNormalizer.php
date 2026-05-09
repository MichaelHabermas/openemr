<?php

/**
 * Normalizes clinical workbook XLSX files into deterministic table content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Time\MonotonicClock;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

final readonly class XlsxDocumentContentNormalizer implements DocumentContentNormalizer
{
    private const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private MonotonicClock $clock,
        private int $maxSourceBytes = 10_485_760,
        private int $maxZipEntries = 256,
        private int $maxUncompressedBytes = 20_971_520,
        private int $maxSheets = 32,
        private int $maxRowsPerSheet = 1_000,
        private int $maxColumnsPerSheet = 64,
        private int $maxCells = 20_000,
        private int $maxCellChars = 2_000,
        private int $maxTextChars = 300_000,
    ) {
    }

    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->documentType === DocumentType::ClinicalWorkbook
            && $request->document->mimeType === self::MIME_TYPE;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        $this->ensureDeadline($deadline);
        if ($request->document->byteCount > $this->maxSourceBytes) {
            throw $this->failure();
        }

        $started = $this->clock->nowMs();
        $warnings = [];
        $this->preflightPackage($request->document->bytes, $warnings, $deadline);

        $path = $this->writeTempXlsx($request->document->bytes);
        try {
            try {
                $spreadsheet = $this->loadSpreadsheet($path);
                try {
                    [$sections, $tables, $sheetWarnings] = $this->normalizeSpreadsheet($spreadsheet, $deadline);
                    $warnings = [...$warnings, ...$sheetWarnings];
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            } catch (DocumentContentNormalizationException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw $this->failure();
            }
        } finally {
            @unlink($path);
        }

        if ($sections === [] && $tables === []) {
            throw $this->failure();
        }

        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            textSections: $sections,
            tables: $tables,
            warnings: $warnings,
            normalizer: $this->name(),
            normalizationElapsedMs: max(0, $this->clock->nowMs() - $started),
        );
    }

    public function name(): string
    {
        return 'xlsx';
    }

    private function failure(): DocumentContentNormalizationException
    {
        return new DocumentContentNormalizationException(
            'XLSX content normalization failed.',
            ExtractionErrorCode::NormalizationFailure,
        );
    }

    private function ensureDeadline(Deadline $deadline): void
    {
        if ($deadline->exceeded()) {
            throw $this->failure();
        }
    }

    /** @param list<DocumentContentWarning> $warnings */
    private function preflightPackage(string $bytes, array &$warnings, Deadline $deadline): void
    {
        $path = $this->writeTempXlsx($bytes);
        try {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw $this->failure();
            }
            try {
                if ($zip->locateName('xl/workbook.xml') === false) {
                    throw $this->failure();
                }
                if ($zip->numFiles < 1 || $zip->numFiles > $this->maxZipEntries) {
                    throw $this->failure();
                }

                $totalUncompressed = 0;
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $this->ensureDeadline($deadline);
                    $name = $zip->getNameIndex($index);
                    if (!is_string($name) || $name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
                        throw $this->failure();
                    }
                    $stat = $zip->statIndex($index);
                    if (!is_array($stat)) {
                        throw $this->failure();
                    }
                    $size = $stat['size'];
                    if ($size < 0) {
                        throw $this->failure();
                    }
                    $totalUncompressed += $size;
                    if ($totalUncompressed > $this->maxUncompressedBytes) {
                        throw $this->failure();
                    }
                    if (
                        str_ends_with($name, '.bin')
                        || str_starts_with($name, 'xl/vbaProject')
                        || str_starts_with($name, 'xl/embeddings/')
                    ) {
                        throw $this->failure();
                    }
                    if (
                        str_starts_with($name, 'xl/externalLinks/')
                        || str_starts_with($name, 'xl/connections')
                    ) {
                        throw $this->failure();
                    }
                    if (str_ends_with($name, '.rels')) {
                        $rels = $zip->getFromName($name);
                        if (!is_string($rels)) {
                            throw $this->failure();
                        }
                        if (
                            str_contains($rels, 'TargetMode="External"')
                            || str_contains($rels, "TargetMode='External'")
                            || $this->containsUnsafeRelationship($rels)
                        ) {
                            throw $this->failure();
                        }
                    }
                    if ($name === '[Content_Types].xml') {
                        $contentTypes = $zip->getFromName($name);
                        if (!is_string($contentTypes) || $this->containsUnsafeContentType($contentTypes)) {
                            throw $this->failure();
                        }
                    }
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private function writeTempXlsx(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-xlsx-');
        if (!is_string($path) || file_put_contents($path, $bytes) === false) {
            throw $this->failure();
        }

        return $path;
    }

    private function loadSpreadsheet(string $path): Spreadsheet
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);
            $reader->setIncludeCharts(false);

            return $reader->load($path);
        } catch (Throwable) {
            throw $this->failure();
        }
    }

    /**
     * @return array{0: list<NormalizedTextSection>, 1: list<NormalizedTable>, 2: list<DocumentContentWarning>}
     */
    private function normalizeSpreadsheet(Spreadsheet $spreadsheet, Deadline $deadline): array
    {
        if ($spreadsheet->getSheetCount() > $this->maxSheets) {
            throw $this->failure();
        }

        $sections = [];
        $tables = [];
        $warnings = [];
        $cellCount = 0;
        $textChars = 0;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $this->ensureDeadline($deadline);
            if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                $warnings[] = new DocumentContentWarning(DocumentContentWarningCode::HiddenSheetSkipped, 'sheet');
                continue;
            }

            if ($sheet->getMergeCells() !== []) {
                $warnings[] = new DocumentContentWarning(DocumentContentWarningCode::MergedCellRangePresent, 'sheet');
            }

            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
            if ($highestRow > $this->maxRowsPerSheet || $highestColumnIndex > $this->maxColumnsPerSheet) {
                throw $this->failure();
            }

            $sheetName = $sheet->getTitle();
            $rows = [];
            $columns = [];
            $hiddenDimensionWarningEmitted = false;
            for ($rowNumber = 1; $rowNumber <= $highestRow; ++$rowNumber) {
                $this->ensureDeadline($deadline);
                if (!$sheet->getRowDimension($rowNumber)->getVisible()) {
                    if (!$hiddenDimensionWarningEmitted) {
                        $warnings[] = new DocumentContentWarning(DocumentContentWarningCode::HiddenRowOrColumnSkipped, 'sheet');
                        $hiddenDimensionWarningEmitted = true;
                    }
                    continue;
                }

                $rowValues = [];
                $rowAnchors = [];
                for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; ++$columnIndex) {
                    ++$cellCount;
                    if ($cellCount > $this->maxCells) {
                        throw $this->failure();
                    }
                    $coordinate = Coordinate::stringFromColumnIndex($columnIndex) . $rowNumber;
                    if (!$sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->getVisible()) {
                        if (!$hiddenDimensionWarningEmitted) {
                            $warnings[] = new DocumentContentWarning(DocumentContentWarningCode::HiddenRowOrColumnSkipped, 'sheet');
                            $hiddenDimensionWarningEmitted = true;
                        }
                        continue;
                    }

                    $value = $this->cellValue($sheet->getCell($coordinate), $warnings);
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (strlen($value) > $this->maxCellChars) {
                        throw $this->failure();
                    }
                    $textChars += strlen($value);
                    if ($textChars > $this->maxTextChars) {
                        throw $this->failure();
                    }
                    $rowValues[$columnIndex] = $value;
                    $rowAnchors[$columnIndex] = sprintf('%s!%s', $sheetName, $coordinate);
                }

                if ($rowValues === []) {
                    continue;
                }
                if ($columns === []) {
                    $columns = $this->columnsFromHeader($rowValues);
                    continue;
                }

                $row = ['_anchor' => $this->rowRange($sheetName, $rowNumber, array_keys($rowValues))];
                foreach ($columns as $columnIndex => $columnName) {
                    if (!isset($rowValues[$columnIndex])) {
                        continue;
                    }
                    $row[$columnName] = $rowValues[$columnIndex];
                    $row[sprintf('_cell_%s', $columnName)] = $rowAnchors[$columnIndex];
                }
                $rows[] = $row;

                if (count($columns) === 2 && isset($rowValues[1], $rowValues[2])) {
                    $sections[] = new NormalizedTextSection(
                        sprintf('%s!%s', $sheetName, Coordinate::stringFromColumnIndex(2) . $rowNumber),
                        $sheetName,
                        sprintf('%s: %s', $rowValues[1], $rowValues[2]),
                        sprintf('sheet:%s; %s', $sheetName, $rowAnchors[2]),
                    );
                }
            }

            if ($columns !== [] && $rows !== []) {
                $tables[] = new NormalizedTable(
                    sprintf('sheet:%s', $sheetName),
                    $sheetName,
                    array_values($columns),
                    $rows,
                );
            }
        }

        return [$sections, $tables, $warnings];
    }

    private function containsUnsafeRelationship(string $xml): bool
    {
        foreach ([
            '/activeX',
            '/connections',
            '/externalLink',
            '/oleObject',
            '/relationships/package',
            '/vbaProject',
        ] as $needle) {
            if (str_contains($xml, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsUnsafeContentType(string $xml): bool
    {
        foreach ([
            'macroEnabled',
            'activeX',
            'oleObject',
            'vbaProject',
            'vnd.ms-office',
            'vnd.openxmlformats-officedocument.oleObject',
            'vnd.openxmlformats-officedocument.packages',
        ] as $needle) {
            if (str_contains($xml, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $headerValues
     * @return array<int, string>
     */
    private function columnsFromHeader(array $headerValues): array
    {
        $columns = [];
        $used = [];
        foreach ($headerValues as $columnIndex => $value) {
            $base = $this->columnKey($value);
            $candidate = $base;
            $suffix = 2;
            while (isset($used[$candidate])) {
                $candidate = sprintf('%s_%d', $base, $suffix);
                ++$suffix;
            }
            $used[$candidate] = true;
            $columns[$columnIndex] = $candidate;
        }

        return $columns;
    }

    private function columnKey(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
        $key = trim($key, '_');

        return $key !== '' ? $key : 'column';
    }

    /** @param list<int> $columnIndexes */
    private function rowRange(string $sheetName, int $rowNumber, array $columnIndexes): string
    {
        $min = min($columnIndexes);
        $max = max($columnIndexes);

        return sprintf(
            '%s!%s%d:%s%d',
            $sheetName,
            Coordinate::stringFromColumnIndex($min),
            $rowNumber,
            Coordinate::stringFromColumnIndex($max),
            $rowNumber,
        );
    }

    /** @param list<DocumentContentWarning> $warnings */
    private function cellValue(Cell $cell, array &$warnings): ?string
    {
        if ($cell->isFormula()) {
            $cached = $cell->getOldCalculatedValue();
            if ($cached === null || $cached === '') {
                $warnings[] = new DocumentContentWarning(DocumentContentWarningCode::FormulaCellSkipped, 'cell');

                return null;
            }
            $warnings[] = new DocumentContentWarning(DocumentContentWarningCode::FormulaCellCachedValueUsed, 'cell');

            return $this->scalarValue($cached);
        }

        $value = $cell->getValue();
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value) && SpreadsheetDate::isDateTime($cell)) {
            return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return $this->scalarValue($value);
    }

    private function scalarValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return trim($value);
        }

        return trim((string) $value);
    }
}

<?php

/**
 * Normalizes referral DOCX files into deterministic text and table content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Time\MonotonicClock;
use ZipArchive;

final readonly class DocxDocumentContentNormalizer implements DocumentContentNormalizer
{
    private const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const MAIN_DOCUMENT = 'word/document.xml';
    private const DOCUMENT_RELS = 'word/_rels/document.xml.rels';
    private const RELATIONSHIP_TYPE_HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';
    private const RELATIONSHIP_TYPE_FOOTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';
    private const RELATIONSHIP_TYPE_OLE_OBJECT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject';
    private const RELATIONSHIP_TYPE_PACKAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/package';
    private const RELATIONSHIP_TYPE_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const REFERRAL_SECTION_LABELS = [
        're',
        'reason for referral',
        'history of present illness',
        'past medical history',
        'current medications',
        'medications',
        'allergies',
        'pertinent labs',
        'relevant labs',
        'assessment',
        'plan',
        'requested action',
        'request',
    ];

    public function __construct(
        private MonotonicClock $clock,
        private int $maxSourceBytes = 10_485_760,
        private int $maxParagraphs = 1_000,
        private int $maxTables = 100,
        private int $maxTableRows = 500,
        private int $maxCells = 5_000,
        private int $maxTextChars = 200_000,
        private int $maxXmlPartBytes = 2_097_152,
        private int $maxTotalXmlBytes = 10_485_760,
    ) {
    }

    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->documentType === DocumentType::ReferralDocx
            && $request->document->mimeType === self::MIME_TYPE;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        if ($deadline->exceeded()) {
            throw new DocumentContentNormalizationException('Deadline exceeded before DOCX content normalization.');
        }
        if ($request->document->byteCount > $this->maxSourceBytes) {
            throw $this->failure();
        }

        $started = $this->clock->nowMs();
        $warnings = [];
        $sections = [];
        $tables = [];
        $loadedXmlBytes = 0;

        $path = $this->writeTempDocx($request->document->bytes);
        try {
            $zip = $this->openPackage($path);
            try {
                $this->rejectUnsupportedPackage($zip);
                $this->ensureDeadline($deadline);
                $warnings = $this->relationshipWarnings($zip, self::DOCUMENT_RELS, $loadedXmlBytes, $deadline);
                $parts = [
                    new DocxPart('body', self::MAIN_DOCUMENT, 'body'),
                    ...$this->relatedHeaderFooterParts($zip, $loadedXmlBytes, $deadline),
                ];
                foreach ($parts as $part) {
                    $warnings = [
                        ...$warnings,
                        ...$this->relationshipWarnings($zip, $this->relationshipPath($part->path), $loadedXmlBytes, $deadline),
                    ];
                }

                $paragraphCount = 0;
                $tableCount = 0;
                $cellCount = 0;
                $textChars = 0;
                foreach ($parts as $part) {
                    $this->ensureDeadline($deadline);
                    $currentSection = $part->defaultSection;
                    $document = $this->loadXmlPart($zip, $part->path, $loadedXmlBytes);
                    $xpath = $this->xpath($document);
                    $containers = $this->contentContainers($xpath, $part);
                    foreach ($containers as $container) {
                        foreach ($container->childNodes as $child) {
                            $this->ensureDeadline($deadline);
                            if (!$child instanceof DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                                continue;
                            }
                            if ($child->localName === 'p') {
                                $text = $this->paragraphText($child);
                                if ($text === '') {
                                    continue;
                                }
                                ++$paragraphCount;
                                if ($paragraphCount > $this->maxParagraphs) {
                                    throw $this->failure();
                                }
                                $textChars += strlen($text);
                                if ($textChars > $this->maxTextChars) {
                                    throw $this->failure();
                                }
                                $heading = $this->headingSlug($xpath, $child, $text);
                                if ($heading !== null) {
                                    $currentSection = $heading;
                                }
                                $sections[] = new NormalizedTextSection(
                                    sprintf('paragraph:%d', $paragraphCount),
                                    $currentSection,
                                    $text,
                                    sprintf('section:%s; paragraph:%d', $currentSection, $paragraphCount),
                                );
                                continue;
                            }
                            if ($child->localName === 'tbl') {
                                ++$tableCount;
                                if ($tableCount > $this->maxTables) {
                                    throw $this->failure();
                                }
                                $table = $this->table($xpath, $child, $tableCount, $currentSection, $cellCount, $textChars, $deadline);
                                if ($cellCount > $this->maxCells || count($table->rows) > $this->maxTableRows) {
                                    throw $this->failure();
                                }
                                if ($table->columns !== [] || $table->rows !== []) {
                                    $tables[] = $table;
                                }
                            }
                        }
                    }
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        if ($sections === [] && $tables === []) {
            throw $this->failure();
        }

        $elapsed = max(0, $this->clock->nowMs() - $started);

        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            textSections: $sections,
            tables: $tables,
            warnings: $warnings,
            normalizer: $this->name(),
            normalizationElapsedMs: $elapsed,
        );
    }

    public function name(): string
    {
        return 'docx';
    }

    private function failure(): DocumentContentNormalizationException
    {
        return new DocumentContentNormalizationException(
            'DOCX content normalization failed.',
            ExtractionErrorCode::NormalizationFailure,
        );
    }

    private function ensureDeadline(Deadline $deadline): void
    {
        if ($deadline->exceeded()) {
            throw $this->failure();
        }
    }

    private function writeTempDocx(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-docx-');
        if (!is_string($path) || file_put_contents($path, $bytes) === false) {
            throw $this->failure();
        }

        return $path;
    }

    private function openPackage(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw $this->failure();
        }
        if ($zip->locateName(self::MAIN_DOCUMENT) === false) {
            $zip->close();
            throw $this->failure();
        }

        return $zip;
    }

    private function rejectUnsupportedPackage(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name)) {
                throw $this->failure();
            }
            if (str_starts_with($name, 'word/vbaProject') || str_ends_with($name, '.bin')) {
                throw $this->failure();
            }
        }
    }

    /** @return list<DocumentContentWarning> */
    private function relationshipWarnings(ZipArchive $zip, string $path, int &$loadedXmlBytes, Deadline $deadline): array
    {
        if ($zip->locateName($path) === false) {
            return [];
        }

        $this->ensureDeadline($deadline);
        $document = $this->loadXmlPart($zip, $path, $loadedXmlBytes);
        $warnings = [];
        foreach ($document->getElementsByTagName('Relationship') as $relationship) {
            $type = $relationship->getAttribute('Type');
            $targetMode = $relationship->getAttribute('TargetMode');
            if (
                $targetMode === 'External'
                || in_array($type, [self::RELATIONSHIP_TYPE_IMAGE, self::RELATIONSHIP_TYPE_OLE_OBJECT, self::RELATIONSHIP_TYPE_PACKAGE], true)
            ) {
                $warnings[] = new DocumentContentWarning(
                    DocumentContentWarningCode::UnsupportedEmbeddedObject,
                    'docx_relationship',
                    ['relationship_type_hash' => substr(hash('sha256', $type), 0, 12)],
                );
            }
        }

        return $warnings;
    }

    /** @return list<DocxPart> */
    private function relatedHeaderFooterParts(ZipArchive $zip, int &$loadedXmlBytes, Deadline $deadline): array
    {
        if ($zip->locateName(self::DOCUMENT_RELS) === false) {
            return [];
        }

        $this->ensureDeadline($deadline);
        $document = $this->loadXmlPart($zip, self::DOCUMENT_RELS, $loadedXmlBytes);
        $parts = [];
        foreach ($document->getElementsByTagName('Relationship') as $relationship) {
            $type = $relationship->getAttribute('Type');
            if ($type !== self::RELATIONSHIP_TYPE_HEADER && $type !== self::RELATIONSHIP_TYPE_FOOTER) {
                continue;
            }
            if ($relationship->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $target = $relationship->getAttribute('Target');
            if ($target === '' || str_contains($target, '..')) {
                continue;
            }
            $path = str_starts_with($target, 'word/') ? $target : 'word/' . ltrim($target, '/');
            if ($zip->locateName($path) === false) {
                continue;
            }
            $kind = $type === self::RELATIONSHIP_TYPE_HEADER ? 'header' : 'footer';
            $parts[] = new DocxPart($kind, $path, $kind);
        }

        usort($parts, static fn (DocxPart $a, DocxPart $b): int => $a->path <=> $b->path);

        return $parts;
    }

    private function relationshipPath(string $partPath): string
    {
        $slash = strrpos($partPath, '/');
        if ($slash === false) {
            return '_rels/' . $partPath . '.rels';
        }

        return substr($partPath, 0, $slash) . '/_rels/' . substr($partPath, $slash + 1) . '.rels';
    }

    private function loadXmlPart(ZipArchive $zip, string $path, int &$loadedXmlBytes): DOMDocument
    {
        $stat = $zip->statName($path);
        if (!is_array($stat)) {
            throw $this->failure();
        }
        $size = $stat['size'];
        if ($size < 1 || $size > $this->maxXmlPartBytes || $loadedXmlBytes + $size > $this->maxTotalXmlBytes) {
            throw $this->failure();
        }
        $loadedXmlBytes += $size;

        $xml = $zip->getFromName($path);
        if (!is_string($xml) || $xml === '') {
            throw $this->failure();
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw $this->failure();
        }

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);

        return $xpath;
    }

    /** @return list<DOMElement> */
    private function contentContainers(DOMXPath $xpath, DocxPart $part): array
    {
        $query = $part->kind === 'body'
            ? '/w:document/w:body'
            : '/*[local-name()="hdr" or local-name()="ftr"]';
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $containers = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $containers[] = $node;
            }
        }

        return $containers;
    }

    private function paragraphText(DOMElement $paragraph): string
    {
        return trim(preg_replace('/[ \t]+/', ' ', $this->nodeText($paragraph)) ?? '');
    }

    private function nodeText(DOMNode $node): string
    {
        if ($node instanceof DOMElement && $node->namespaceURI === self::WORD_NAMESPACE) {
            if ($node->localName === 'del') {
                return '';
            }
            if ($node->localName === 't') {
                return $node->textContent;
            }
            if ($node->localName === 'tab') {
                return "\t";
            }
            if ($node->localName === 'br' || $node->localName === 'cr') {
                return "\n";
            }
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->nodeText($child);
        }

        return $text;
    }

    private function headingSlug(DOMXPath $xpath, DOMElement $paragraph, string $text): ?string
    {
        $nodes = $xpath->query('w:pPr/w:pStyle/@w:val', $paragraph);
        if ($nodes !== false && $nodes->length > 0) {
            $style = strtolower((string) $nodes->item(0)?->nodeValue);
            if (str_starts_with($style, 'heading')) {
                return $this->slug($text) ?: 'heading';
            }
        }

        $colonPosition = strpos($text, ':');
        if ($colonPosition === false || $colonPosition > 48) {
            return null;
        }

        $label = strtolower(trim(substr($text, 0, $colonPosition)));
        if (!in_array($label, self::REFERRAL_SECTION_LABELS, true)) {
            return null;
        }

        return $this->slug($label);
    }

    private function slug(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '', '-'));

        return substr($slug, 0, 80);
    }

    private function table(
        DOMXPath $xpath,
        DOMElement $table,
        int $tableNumber,
        string $section,
        int &$cellCount,
        int &$textChars,
        Deadline $deadline,
    ): NormalizedTable
    {
        $rows = [];
        $rowNodes = $xpath->query('w:tr', $table);
        if ($rowNodes === false || $rowNodes->length === 0) {
            return new NormalizedTable(sprintf('table:%d', $tableNumber), $section, [], []);
        }

        $rawRows = [];
        foreach ($rowNodes as $rowNode) {
            if (!$rowNode instanceof DOMElement) {
                continue;
            }
            $this->ensureDeadline($deadline);
            $cells = [];
            $cellNodes = $xpath->query('w:tc', $rowNode);
            if ($cellNodes === false) {
                continue;
            }
            foreach ($cellNodes as $cellNode) {
                if (!$cellNode instanceof DOMElement) {
                    continue;
                }
                ++$cellCount;
                $cellText = trim(preg_replace('/[ \t]+/', ' ', $this->nodeText($cellNode)) ?? '');
                $textChars += strlen($cellText);
                if ($textChars > $this->maxTextChars) {
                    throw $this->failure();
                }
                $cells[] = $cellText;
            }
            if ($cells !== []) {
                $rawRows[] = $cells;
            }
        }

        if ($rawRows === []) {
            return new NormalizedTable(sprintf('table:%d', $tableNumber), $section, [], []);
        }

        $headers = array_map(
            fn (string $header, int $index): string => $this->tableColumnName($header, $index),
            $rawRows[0],
            array_keys($rawRows[0]),
        );

        foreach (array_slice($rawRows, 1) as $rowIndex => $rawRow) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $rawRow[$index] ?? '';
            }
            $row['_anchor'] = sprintf('table:%d.row:%d', $tableNumber, $rowIndex + 1);
            $rows[] = $row;
        }

        return new NormalizedTable(sprintf('table:%d', $tableNumber), $section, $headers, $rows);
    }

    private function tableColumnName(string $header, int $index): string
    {
        $slug = $this->slug($header);
        if ($slug === '') {
            return 'column_' . ($index + 1);
        }

        return str_replace('-', '_', $slug);
    }
}

final readonly class DocxPart
{
    public function __construct(
        public string $kind,
        public string $path,
        public string $defaultSection,
    ) {
    }
}

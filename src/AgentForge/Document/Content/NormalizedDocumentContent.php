<?php

/**
 * Provider-ready normalized document content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use DomainException;

final readonly class NormalizedDocumentContent
{
    /** @var list<NormalizedRenderedPage> */
    public array $renderedPages;

    /** @var list<NormalizedTextSection> */
    public array $textSections;

    /** @var list<NormalizedTable> */
    public array $tables;

    /** @var list<NormalizedMessageSegment> */
    public array $messageSegments;

    /** @var list<DocumentContentWarning> */
    public array $warnings;

    /**
     * @param list<NormalizedRenderedPage> $renderedPages
     * @param list<NormalizedTextSection> $textSections
     * @param list<NormalizedTable> $tables
     * @param list<NormalizedMessageSegment> $messageSegments
     * @param list<DocumentContentWarning> $warnings
     */
    public function __construct(
        public NormalizedDocumentSource $source,
        array $renderedPages = [],
        array $textSections = [],
        array $tables = [],
        array $messageSegments = [],
        array $warnings = [],
        private string $normalizer = 'unknown',
        private int $normalizationElapsedMs = 0,
    ) {
        if ($renderedPages === [] && $textSections === [] && $tables === [] && $messageSegments === []) {
            throw new DomainException('Normalized document content must include at least one content part.');
        }

        $this->renderedPages = self::listOf($renderedPages, NormalizedRenderedPage::class, 'rendered pages');
        $this->textSections = self::listOf($textSections, NormalizedTextSection::class, 'text sections');
        $this->tables = self::listOf($tables, NormalizedTable::class, 'tables');
        $this->messageSegments = self::listOf($messageSegments, NormalizedMessageSegment::class, 'message segments');
        $this->warnings = self::listOf($warnings, DocumentContentWarning::class, 'warnings');
    }

    public function telemetry(): DocumentContentTelemetry
    {
        return new DocumentContentTelemetry(
            $this->normalizer,
            $this->source->mimeType,
            $this->source->byteLength,
            count($this->renderedPages),
            count($this->textSections),
            count($this->tables),
            count($this->messageSegments),
            array_values(array_unique(array_map(
                static fn (DocumentContentWarning $warning): string => $warning->code->value,
                $this->warnings,
            ))),
            $this->normalizationElapsedMs,
        );
    }

    /**
     * @template T of object
     * @param list<mixed> $items
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function listOf(array $items, string $class, string $label): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof $class) {
                throw new DomainException(sprintf('Normalized document content %s must contain only %s.', $label, $class));
            }
            $out[] = $item;
        }

        return $out;
    }
}

<?php

/**
 * Normalizes HL7 v2 messages into deterministic segment content.
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

final readonly class Hl7v2DocumentContentNormalizer implements DocumentContentNormalizer
{
    /** @var array<string, true> */
    private const MIME_TYPES = [
        'text/plain' => true,
        'application/hl7-v2' => true,
        'application/hl7' => true,
        'x-application/hl7-v2+er7' => true,
    ];

    public function __construct(
        private MonotonicClock $clock,
        private int $maxSourceBytes = 1_048_576,
        private int $maxSegments = 2_000,
        private int $maxFieldChars = 10_000,
    ) {
    }

    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->documentType === DocumentType::Hl7v2Message
            && (
                isset(self::MIME_TYPES[strtolower($request->document->mimeType)])
                || str_ends_with(strtolower($request->document->name), '.hl7')
            );
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
        $message = $this->normalizeLineEndings($request->document->bytes);
        $lines = array_values(array_filter(explode("\r", $message), static fn (string $line): bool => trim($line) !== ''));
        if ($lines === [] || !str_starts_with($lines[0], 'MSH') || strlen($lines[0]) < 8) {
            throw $this->failure();
        }
        if (count($lines) > $this->maxSegments) {
            throw $this->failure();
        }

        $fieldSeparator = $this->separatorAt($lines[0], 3);
        $encodingCharacters = substr($lines[0], 4, 4);
        if (strlen($encodingCharacters) !== 4 || $encodingCharacters[0] === $fieldSeparator) {
            throw $this->failure();
        }

        $componentSeparator = $this->separatorAt($encodingCharacters, 0);
        $repetitionSeparator = $this->separatorAt($encodingCharacters, 1);
        $escapeCharacter = $this->separatorAt($encodingCharacters, 2);
        $subcomponentSeparator = $this->separatorAt($encodingCharacters, 3);
        $messageControlId = $this->messageControlId($lines[0], $fieldSeparator);

        $segments = [];
        $segmentCounts = [];
        foreach ($lines as $line) {
            $this->ensureDeadline($deadline);
            if (strlen($line) < 3) {
                throw $this->failure();
            }
            $segmentType = substr($line, 0, 3);
            if (!preg_match('/^[A-Z0-9]{3}$/', $segmentType)) {
                throw $this->failure();
            }
            $segmentCounts[$segmentType] = ($segmentCounts[$segmentType] ?? 0) + 1;
            $segmentIndex = $segmentCounts[$segmentType];
            $segmentAnchor = sprintf('%s[%d]', $segmentType, $segmentIndex);

            $segments[] = new NormalizedMessageSegment(
                sprintf('message:%s; %s', $messageControlId, $segmentAnchor),
                $segmentType,
                $this->segmentFields(
                    $line,
                    $segmentType,
                    $fieldSeparator,
                    $componentSeparator,
                    $repetitionSeparator,
                    $subcomponentSeparator,
                    $escapeCharacter,
                    $segmentAnchor,
                ),
            );
        }

        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            messageSegments: $segments,
            normalizer: $this->name(),
            normalizationElapsedMs: max(0, $this->clock->nowMs() - $started),
        );
    }

    public function name(): string
    {
        return 'hl7v2';
    }

    private function normalizeLineEndings(string $bytes): string
    {
        $message = str_replace(["\r\n", "\n"], "\r", trim($bytes));
        if (str_contains($message, "\0")) {
            throw $this->failure();
        }

        return $message;
    }

    /** @param non-empty-string $fieldSeparator */
    private function messageControlId(string $msh, string $fieldSeparator): string
    {
        $fields = explode($fieldSeparator, $msh);
        $controlId = trim($fields[9] ?? '');
        if ($controlId === '') {
            throw $this->failure();
        }

        return preg_replace('/[^A-Za-z0-9._:-]/', '_', $controlId) ?: 'unknown';
    }

    /** @return non-empty-string */
    private function separatorAt(string $value, int $offset): string
    {
        $separator = $value[$offset] ?? '';
        if ($separator === '') {
            throw $this->failure();
        }

        return $separator;
    }

    /**
     * @param non-empty-string $fieldSeparator
     * @param non-empty-string $componentSeparator
     * @param non-empty-string $repetitionSeparator
     * @param non-empty-string $subcomponentSeparator
     * @param non-empty-string $escapeCharacter
     * @return array<string, string>
     */
    private function segmentFields(
        string $line,
        string $segmentType,
        string $fieldSeparator,
        string $componentSeparator,
        string $repetitionSeparator,
        string $subcomponentSeparator,
        string $escapeCharacter,
        string $segmentAnchor,
    ): array {
        $rawFields = explode($fieldSeparator, $line);
        $fields = [
            '_anchor' => $segmentAnchor,
        ];

        if ($segmentType === 'MSH') {
            $fields['MSH.1'] = $fieldSeparator;
            $fields['MSH.2'] = $rawFields[1] ?? '';
            $start = 2;
        } else {
            $start = 1;
        }

        for ($index = $start; $index < count($rawFields); ++$index) {
            $fieldNumber = $segmentType === 'MSH' ? $index + 1 : $index;
            $path = sprintf('%s.%d', $segmentType, $fieldNumber);
            $this->appendField($fields, $path, $rawFields[$index], $componentSeparator, $repetitionSeparator, $subcomponentSeparator, $escapeCharacter);
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     * @param non-empty-string $componentSeparator
     * @param non-empty-string $repetitionSeparator
     * @param non-empty-string $subcomponentSeparator
     * @param non-empty-string $escapeCharacter
     */
    private function appendField(
        array &$fields,
        string $path,
        string $raw,
        string $componentSeparator,
        string $repetitionSeparator,
        string $subcomponentSeparator,
        string $escapeCharacter,
    ): void {
        if (strlen($raw) > $this->maxFieldChars) {
            throw $this->failure();
        }
        if ($raw === '') {
            return;
        }

        $fields[$path] = $this->unescapeValue($raw, $escapeCharacter, $componentSeparator, $repetitionSeparator, $subcomponentSeparator);
        $repetitions = explode($repetitionSeparator, $raw);
        foreach ($repetitions as $repetitionIndex => $repetition) {
            $repeatPath = sprintf('%s[%d]', $path, $repetitionIndex + 1);
            if (count($repetitions) > 1) {
                $fields[$repeatPath] = $this->unescapeValue($repetition, $escapeCharacter, $componentSeparator, $repetitionSeparator, $subcomponentSeparator);
            }

            $components = explode($componentSeparator, $repetition);
            foreach ($components as $componentIndex => $component) {
                if ($component === '' || count($components) === 1) {
                    continue;
                }
                $componentPath = sprintf('%s.%d', $repeatPath, $componentIndex + 1);
                $fields[$componentPath] = $this->unescapeValue($component, $escapeCharacter, $componentSeparator, $repetitionSeparator, $subcomponentSeparator);

                $subcomponents = explode($subcomponentSeparator, $component);
                foreach ($subcomponents as $subcomponentIndex => $subcomponent) {
                    if ($subcomponent === '' || count($subcomponents) === 1) {
                        continue;
                    }
                    $fields[sprintf('%s.%d', $componentPath, $subcomponentIndex + 1)] = $this->unescapeValue($subcomponent, $escapeCharacter, $componentSeparator, $repetitionSeparator, $subcomponentSeparator);
                }
            }
        }
    }

    private function unescapeValue(
        string $value,
        string $escapeCharacter,
        string $componentSeparator,
        string $repetitionSeparator,
        string $subcomponentSeparator,
    ): string {
        return strtr($value, [
            $escapeCharacter . 'F' . $escapeCharacter => '|',
            $escapeCharacter . 'S' . $escapeCharacter => $componentSeparator,
            $escapeCharacter . 'R' . $escapeCharacter => $repetitionSeparator,
            $escapeCharacter . 'E' . $escapeCharacter => $escapeCharacter,
            $escapeCharacter . 'T' . $escapeCharacter => $subcomponentSeparator,
        ]);
    }

    private function ensureDeadline(Deadline $deadline): void
    {
        if ($deadline->exceeded()) {
            throw $this->failure();
        }
    }

    private function failure(): DocumentContentNormalizationException
    {
        return new DocumentContentNormalizationException(
            'HL7 v2 content normalization failed.',
            ExtractionErrorCode::NormalizationFailure,
        );
    }
}

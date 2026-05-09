<?php

/**
 * Parsed HL7 v2 message projection used by deterministic extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Document\Content\NormalizedMessageSegment;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;

final readonly class Hl7v2ParsedMessage
{
    /** @var array<string, list<NormalizedMessageSegment>> */
    private array $segments;

    /**
     * @param array<string, list<NormalizedMessageSegment>> $segments
     * @param list<string> $warnings
     */
    private function __construct(
        array $segments,
        private string $sourceSha256,
        private string $messageType,
        private string $messageControlId,
        private string $componentSeparator,
        public array $warnings = [],
    ) {
        $this->segments = $segments;
    }

    /**
     * @param list<NormalizedMessageSegment> $segments
     */
    public static function fromSegments(array $segments, string $sourceSha256): self
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sourceSha256)) {
            throw new ExtractionProviderException('HL7 v2 source SHA-256 must be lowercase hex.', ExtractionErrorCode::SchemaValidationFailure);
        }

        $byType = [];
        foreach ($segments as $segment) {
            $byType[$segment->segmentType][] = $segment;
        }

        $msh = $byType['MSH'][0] ?? null;
        if (!$msh instanceof NormalizedMessageSegment) {
            throw new ExtractionProviderException('HL7 v2 message is missing MSH.', ExtractionErrorCode::SchemaValidationFailure);
        }
        if (!isset($byType['PID'][0])) {
            throw new ExtractionProviderException('HL7 v2 message is missing PID.', ExtractionErrorCode::SchemaValidationFailure);
        }

        $componentSeparator = self::componentSeparator($msh);
        $messageType = self::components($msh, '9', 2, $componentSeparator);
        if (!in_array($messageType, ['ADT^A08', 'ORU^R01'], true)) {
            throw new ExtractionProviderException('HL7 v2 message type is not supported.', ExtractionErrorCode::UnsupportedDocType);
        }

        $messageControlId = self::field($msh, '10');
        if ($messageControlId === '') {
            throw new ExtractionProviderException('HL7 v2 message control id is missing.', ExtractionErrorCode::SchemaValidationFailure);
        }

        if ($messageType === 'ORU^R01') {
            self::assertNoDuplicateObxRows($byType['OBX'] ?? []);
        }

        return new self($byType, $sourceSha256, $messageType, $messageControlId, $componentSeparator);
    }

    /** @return array<string, mixed> */
    public function toExtractionPayload(): array
    {
        return [
            'doc_type' => 'hl7v2_message',
            'message_type' => $this->messageType,
            'message_control_id' => $this->messageControlId,
            'patient_identity' => $this->patientIdentity(),
            'facts' => $this->facts(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function patientIdentity(): array
    {
        $pid = $this->requiredFirst('PID');
        $identity = [];

        foreach ([
            ['patient_name', '5', self::field($pid, '5')],
            ['date_of_birth', '7', self::formatDate(self::field($pid, '7'))],
            ['mrn', '3', $this->mrn($pid)],
        ] as [$kind, $field, $value]) {
            if ($value === '') {
                continue;
            }
            $identity[] = [
                'kind' => $kind,
                'value' => $value,
                'field_path' => sprintf('PID[1].%s', $field),
                'certainty' => 'verified',
                'confidence' => 0.99,
                'citation' => $this->citation('PID', 1, $field, self::field($pid, $field)),
            ];
        }

        return $identity;
    }

    /** @return list<array<string, mixed>> */
    private function facts(): array
    {
        return $this->messageType === 'ADT^A08'
            ? $this->adtFacts()
            : $this->oruFacts();
    }

    /** @return list<array<string, mixed>> */
    private function adtFacts(): array
    {
        $facts = [];
        $evn = $this->first('EVN');
        if ($evn instanceof NormalizedMessageSegment) {
            $eventNote = self::field($evn, '7') !== '' ? self::field($evn, '7') : self::field($evn, '6');
            if ($eventNote !== '') {
                $facts[] = $this->fact('visit_update', 'EVN[1].7', 'Event note', $eventNote, 'EVN', 1, '7');
            }
        }

        $pv1 = $this->first('PV1');
        if ($pv1 instanceof NormalizedMessageSegment) {
            if (self::field($pv1, '19') !== '') {
                $facts[] = $this->fact('visit_number', 'PV1[1].19', 'Visit number', self::field($pv1, '19'), 'PV1', 1, '19');
            }
            if (self::field($pv1, '2') !== '') {
                $facts[] = $this->fact('visit_class', 'PV1[1].2', 'Patient class', self::field($pv1, '2'), 'PV1', 1, '2');
            }
        }

        if ($facts === []) {
            $pid = $this->requiredFirst('PID');
            $value = self::field($pid, '5') !== '' ? self::field($pid, '5') : $this->mrn($pid);
            if ($value === '') {
                $value = 'ADT patient identity update';
            }
            $facts[] = $this->fact('patient_identity_update', 'PID[1]', 'ADT patient identity update', $value, 'PID', 1, null);
        }

        return $facts;
    }

    /** @return list<array<string, mixed>> */
    private function oruFacts(): array
    {
        $facts = [];

        foreach ($this->segments['ORC'] ?? [] as $index => $orc) {
            if (self::field($orc, '2') !== '') {
                $facts[] = $this->fact('order', sprintf('ORC[%d].2', $index + 1), 'Placer order number', self::field($orc, '2'), 'ORC', $index + 1, '2');
            }
        }

        foreach ($this->segments['OBR'] ?? [] as $index => $obr) {
            if (self::field($obr, '4') !== '') {
                $facts[] = $this->fact('observation_order', sprintf('OBR[%d].4', $index + 1), $this->labelFromCodedField(self::field($obr, '4')), self::field($obr, '4'), 'OBR', $index + 1, '4');
            }
        }

        foreach ($this->segments['OBX'] ?? [] as $index => $obx) {
            $facts[] = $this->obxFact($obx, $index + 1);
        }

        foreach ($this->segments['NTE'] ?? [] as $index => $nte) {
            if (self::field($nte, '3') !== '') {
                $facts[] = $this->fact('note', sprintf('NTE[%d].3', $index + 1), 'Result note', self::field($nte, '3'), 'NTE', $index + 1, '3');
            }
        }

        return $facts;
    }

    /** @return array<string, mixed> */
    private function obxFact(NormalizedMessageSegment $obx, int $occurrence): array
    {
        $label = $this->labelFromCodedField(self::field($obx, '3'));
        $value = trim(implode(' ', array_filter([
            self::field($obx, '5'),
            self::field($obx, '6'),
            self::field($obx, '8') !== '' ? 'abnormal ' . strtolower(self::field($obx, '8')) : '',
            self::field($obx, '14') !== '' ? 'collected ' . self::field($obx, '14') : '',
        ], static fn (string $part): bool => $part !== '')));

        return $this->fact(
            'lab_result',
            sprintf('OBX[%d].5', $occurrence),
            $label !== '' ? $label : sprintf('OBX %d', $occurrence),
            $value !== '' ? $value : self::field($obx, '5'),
            'OBX',
            $occurrence,
            '5',
            sprintf('%s %s', $label, $value),
        );
    }

    /** @return array<string, mixed> */
    private function fact(
        string $type,
        string $fieldPath,
        string $label,
        string $value,
        string $segmentType,
        int $occurrence,
        ?string $field,
        ?string $quote = null,
    ): array {
        return [
            'type' => $type,
            'field_path' => $fieldPath,
            'label' => $label,
            'value' => $value,
            'certainty' => 'document_fact',
            'confidence' => 0.98,
            'citation' => $this->citation($segmentType, $occurrence, $field, $quote ?? $value),
        ];
    }

    /** @return array<string, string> */
    private function citation(string $segmentType, int $occurrence, ?string $field, string $quoteOrValue): array
    {
        return [
            'source_type' => 'hl7v2_message',
            'source_id' => 'sha256:' . $this->sourceSha256,
            'page_or_section' => 'message:' . $this->messageControlId,
            'field_or_chunk_id' => sprintf('%s[%d]%s', $segmentType, $occurrence, $field !== null ? '.' . $field : ''),
            'quote_or_value' => $quoteOrValue,
        ];
    }

    private function first(string $segmentType): ?NormalizedMessageSegment
    {
        $segments = $this->segments[$segmentType] ?? [];

        return $segments[0] ?? null;
    }

    private function requiredFirst(string $segmentType): NormalizedMessageSegment
    {
        return $this->first($segmentType)
            ?? throw new ExtractionProviderException(sprintf('HL7 v2 message is missing %s.', $segmentType), ExtractionErrorCode::SchemaValidationFailure);
    }

    private static function field(NormalizedMessageSegment $segment, string $fieldNumber): string
    {
        $value = $segment->fields[$segment->segmentType . '.' . $fieldNumber] ?? '';
        return trim($value);
    }

    private static function component(NormalizedMessageSegment $segment, string $fieldNumber, int $componentNumber): string
    {
        $path = sprintf('%s.%s[1].%d', $segment->segmentType, $fieldNumber, $componentNumber);

        return trim($segment->fields[$path] ?? '');
    }

    /** @param non-empty-string $componentSeparator */
    private static function components(NormalizedMessageSegment $segment, string $fieldNumber, int $count, string $componentSeparator): string
    {
        $components = [];
        for ($index = 1; $index <= $count; ++$index) {
            $components[] = self::component($segment, $fieldNumber, $index);
        }

        if (implode('', $components) === '') {
            $components = array_slice(explode($componentSeparator, self::field($segment, $fieldNumber)), 0, $count);
        }

        return implode('^', $components);
    }

    private static function formatDate(string $value): string
    {
        if (preg_match('/^\d{8}$/', $value) !== 1) {
            return $value;
        }

        return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }

    private function labelFromCodedField(string $field): string
    {
        $components = explode($this->componentSeparatorValue(), $field);
        return trim($components[1] ?? $components[0]);
    }

    private function mrn(NormalizedMessageSegment $pid): string
    {
        for ($repetition = 1; $repetition <= 50; ++$repetition) {
            $id = trim($pid->fields[sprintf('PID.3[%d].1', $repetition)] ?? '');
            $idType = strtoupper(trim($pid->fields[sprintf('PID.3[%d].5', $repetition)] ?? ''));
            if ($id === '' && $idType === '') {
                continue;
            }
            if ($idType === 'MR') {
                return $id !== '' ? $id : trim($pid->fields[sprintf('PID.3[%d]', $repetition)] ?? '');
            }
        }

        $component = self::component($pid, '3', 1);
        if ($component !== '') {
            return $component;
        }

        return trim(explode($this->componentSeparatorValue(), self::field($pid, '3'))[0]);
    }

    /** @return non-empty-string */
    private function componentSeparatorValue(): string
    {
        if ($this->componentSeparator === '') {
            throw new ExtractionProviderException('HL7 v2 MSH encoding characters are missing.', ExtractionErrorCode::SchemaValidationFailure);
        }

        return $this->componentSeparator;
    }

    /** @return non-empty-string */
    private static function componentSeparator(NormalizedMessageSegment $msh): string
    {
        $encodingCharacters = self::field($msh, '2');
        $separator = $encodingCharacters[0] ?? '';
        if ($separator === '') {
            throw new ExtractionProviderException('HL7 v2 MSH encoding characters are missing.', ExtractionErrorCode::SchemaValidationFailure);
        }

        return $separator;
    }

    /** @param list<NormalizedMessageSegment> $obxSegments */
    private static function assertNoDuplicateObxRows(array $obxSegments): void
    {
        $seen = [];
        foreach ($obxSegments as $obx) {
            $key = implode("\n", [
                self::field($obx, '3'),
                self::field($obx, '5'),
                self::field($obx, '14'),
            ]);
            if (trim($key) === '') {
                continue;
            }
            if (isset($seen[$key])) {
                throw new ExtractionProviderException('HL7 v2 ORU message has duplicate OBX rows.', ExtractionErrorCode::SchemaValidationFailure);
            }
            $seen[$key] = true;
        }
    }
}

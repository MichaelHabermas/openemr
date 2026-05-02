<?php

/**
 * Formats vital sign rows into source-carrying evidence values.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final class VitalEvidenceValues
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, array{label: string, value: string}>
     */
    public static function fromRow(array $row): array
    {
        $values = [];
        $bps = EvidenceRowValue::string($row, 'bps');
        $bpd = EvidenceRowValue::string($row, 'bpd');
        if (self::present($bps) && self::present($bpd)) {
            $values['blood-pressure'] = [
                'label' => 'Blood pressure',
                'value' => sprintf('%s/%s mmHg', self::displayNumber($bps), self::displayNumber($bpd)),
            ];
        }

        foreach ([
            'pulse' => ['Pulse', 'bpm'],
            'temperature' => ['Temperature', 'F'],
            'respiration' => ['Respiration', 'breaths/min'],
            'oxygen_saturation' => ['Oxygen saturation', '%'],
            'weight' => ['Weight', 'lb'],
            'height' => ['Height', 'in'],
            'BMI' => ['BMI', 'kg/m2'],
        ] as $key => [$label, $unit]) {
            $value = EvidenceRowValue::string($row, $key);
            if (self::present($value)) {
                $values[strtolower(str_replace('_', '-', $key))] = [
                    'label' => $label,
                    'value' => sprintf('%s %s', self::displayNumber($value), $unit),
                ];
            }
        }

        return $values;
    }

    private static function present(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return (float) $value !== 0.0;
    }

    private static function displayNumber(string $value): string
    {
        $value = trim($value);
        if (!is_numeric($value) || !str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}

<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Case;

use OpenEMR\AgentForge\StringKeyedArray;
use RuntimeException;

final class EvalCaseLoader
{
    /**
     * @return list<EvalCase>
     */
    public function loadDirectory(string $casesDir): array
    {
        $files = glob(rtrim($casesDir, '/') . '/*.json') ?: [];
        sort($files);

        return array_map(fn (string $file): EvalCase => $this->loadFile($file), $files);
    }

    public function loadFile(string $file): EvalCase
    {
        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read Clinical document eval case file: %s', $file));
        }

        return $this->loadJson($json, $file);
    }

    public function loadJson(string $json, string $sourceName = 'inline'): EvalCase
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid JSON for Clinical document eval case %s: %s', $sourceName, json_last_error_msg()));
        }

        return $this->fromArray(StringKeyedArray::filter($data), $sourceName);
    }

    /** @param array<string, mixed> $data */
    private function fromArray(array $data, string $sourceName): EvalCase
    {
        $versionValue = $data['case_format_version'] ?? null;
        $version = is_int($versionValue) ? $versionValue : 0;
        if ($version !== 1) {
            throw new RuntimeException(sprintf('Unsupported clinical document eval case_format_version in %s.', $sourceName));
        }

        foreach (['case_id', 'category', 'patient_ref', 'input', 'expected'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new RuntimeException(sprintf('Missing required clinical document eval case field "%s" in %s.', $field, $sourceName));
            }
        }

        if (!is_array($data['input']) || !is_array($data['expected'])) {
            throw new RuntimeException(sprintf('clinical document eval case input and expected must be objects in %s.', $sourceName));
        }

        if (!is_string($data['case_id']) || !is_string($data['category']) || !is_string($data['patient_ref'])) {
            throw new RuntimeException(sprintf('clinical document eval case id, category, and patient_ref must be strings in %s.', $sourceName));
        }

        $category = EvalCaseCategory::tryFrom($data['category']);
        if ($category === null) {
            throw new RuntimeException(sprintf('Unsupported clinical document eval case category "%s" in %s.', $data['category'], $sourceName));
        }

        $input = StringKeyedArray::filter($data['input']);
        $expected = StringKeyedArray::filter($data['expected']);
        $docType = $data['doc_type'] ?? null;
        if ($docType !== null && !is_string($docType)) {
            throw new RuntimeException(sprintf('clinical document eval case doc_type must be a string or null in %s.', $sourceName));
        }

        return new EvalCase(
            $version,
            $data['case_id'],
            $category,
            $data['patient_ref'],
            $docType,
            $input,
            ExpectedExtraction::fromArray($this->arrayValue($expected, 'extraction')),
            $this->listOfArrays($expected['promotions'] ?? []),
            $this->listOfArrays($expected['document_facts'] ?? []),
            ExpectedRetrieval::fromArray($this->arrayValue($expected, 'retrieval')),
            ExpectedAnswer::fromArray($this->arrayValue($expected, 'answer')),
            (bool) ($expected['refusal_required'] ?? false),
            $this->stringList($expected['log_must_not_contain'] ?? []),
            ExpectedRubrics::fromArray($this->arrayValue($expected, 'rubrics')),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function arrayValue(array $data, string $key): array
    {
        return isset($data[$key]) && is_array($data[$key]) ? StringKeyedArray::filter($data[$key]) : [];
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = StringKeyedArray::filter($item);
            }
        }

        return $items;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}

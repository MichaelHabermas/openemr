<?php

/**
 * JSON schema composition for AgentForge clinical document extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Document\DocumentType;

final readonly class JsonSchemaBuilder
{
    public const SCHEMA_NAME = 'agentforge_document_extraction';

    /** @return array<string, mixed> */
    public function schema(DocumentType $documentType): array
    {
        return match ($documentType) {
            DocumentType::LabPdf => $this->labPdfSchema(),
            DocumentType::IntakeForm => $this->intakeFormSchema(),
        };
    }

    /** @return array<string, mixed> */
    private function labPdfSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['doc_type', 'lab_name', 'collected_at', 'results'],
            'properties' => [
                'doc_type' => ['type' => 'string', 'enum' => ['lab_pdf']],
                'lab_name' => ['type' => 'string'],
                'collected_at' => ['type' => 'string'],
                'results' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'test_name',
                            'value',
                            'unit',
                            'reference_range',
                            'collected_at',
                            'abnormal_flag',
                            'certainty',
                            'confidence',
                            'citation',
                        ],
                        'properties' => [
                            'test_name' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'unit' => ['type' => 'string'],
                            'reference_range' => ['type' => 'string'],
                            'collected_at' => ['type' => 'string'],
                            'abnormal_flag' => [
                                'type' => 'string',
                                'enum' => ['low', 'normal', 'high', 'critical_low', 'critical_high'],
                            ],
                            'certainty' => $this->certaintySchema(),
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'citation' => $this->citationSchema('lab_pdf'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function intakeFormSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['doc_type', 'form_name', 'findings'],
            'properties' => [
                'doc_type' => ['type' => 'string', 'enum' => ['intake_form']],
                'form_name' => ['type' => 'string'],
                'findings' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['field', 'value', 'certainty', 'confidence', 'citation'],
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'certainty' => $this->certaintySchema(),
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'citation' => $this->citationSchema('intake_form'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function citationSchema(string $sourceType): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['source_type', 'source_id', 'page_or_section', 'field_or_chunk_id', 'quote_or_value'],
            'properties' => [
                'source_type' => ['type' => 'string', 'enum' => [$sourceType]],
                'source_id' => ['type' => 'string'],
                'page_or_section' => ['type' => 'string'],
                'field_or_chunk_id' => ['type' => 'string'],
                'quote_or_value' => ['type' => 'string'],
                'bounding_box' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['x', 'y', 'width', 'height'],
                    'properties' => [
                        'x' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1],
                        'height' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function certaintySchema(): array
    {
        return [
            'type' => 'string',
            'enum' => ['verified', 'document_fact', 'needs_review'],
        ];
    }
}

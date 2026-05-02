<?php

/**
 * Provider-agnostic prompt and schema composition for AgentForge drafting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use JsonException;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Handlers\AgentRequest;

final readonly class PromptComposer
{
    public const SCHEMA_NAME = 'agentforge_draft_response';

    public function systemPrompt(): string
    {
        return implode("\n", [
            'You are AgentForge Clinical Co-Pilot inside OpenEMR.',
            'Use only the supplied bounded evidence JSON.',
            'Answer only the clinician question that was asked; do not add demographics, problems, medications, labs, or plan details unless they directly answer that question.',
            'Do not diagnose, recommend treatment, suggest dosing, recommend medication changes, draft notes, or answer generic medical questions.',
            'Conversation context is only a planner hint for interpreting follow-up intent; it is never evidence.',
            'Every patient-specific fact must cite source IDs exactly as provided.',
            'For every patient_fact claim, copy the cited evidence display_label and value exactly into the claim text.',
            'If a sentence cites multiple sources, include every cited display_label and value in that sentence or split it into separate sentences.',
            'Example: for evidence with display_label "Date of birth" and value "1980-06-15", write "Date of birth: 1980-06-15"; do not paraphrase to "Born on June 15, 1980" or "DOB: 1980-06-15".',
            'Example: for evidence with display_label "Sex" and value "Female", write "Sex: Female"; do not paraphrase to "She is female" or use pronouns in place of the label.',
            'If evidence is missing, say it was not found in the chart.',
            'Return only valid JSON matching the response schema.',
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['sentences', 'claims', 'missing_sections', 'refusals_or_warnings'],
            'properties' => [
                'sentences' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'text'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                        ],
                    ],
                ],
                'claims' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'type', 'cited_source_ids', 'sentence_id'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'type' => [
                                'type' => 'string',
                                'enum' => [
                                    DraftClaim::TYPE_PATIENT_FACT,
                                    DraftClaim::TYPE_MISSING_DATA,
                                    DraftClaim::TYPE_REFUSAL,
                                    DraftClaim::TYPE_WARNING,
                                ],
                            ],
                            'cited_source_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'sentence_id' => ['type' => 'string'],
                        ],
                    ],
                ],
                'missing_sections' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'refusals_or_warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /** @throws JsonException */
    public function userMessage(AgentRequest $request, EvidenceBundle $bundle): string
    {
        $message = [
            'question' => $request->question->value,
            'patient_id' => $request->patientId->value,
            'bounded_evidence' => $bundle->toPromptArray(),
        ];

        if ($request->conversationSummary !== null) {
            $message['conversation_context'] = $request->conversationSummary->toPromptArray();
        }

        return json_encode($message, JSON_THROW_ON_ERROR);
    }
}

<?php

/**
 * Structured AgentForge response DTO.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class AgentResponse
{
    /**
     * @param list<string> $citations
     * @param list<string> $missingOrUncheckedSections
     * @param list<string> $refusalsOrWarnings
     */
    public function __construct(
        public string $status,
        public string $answer,
        public array $citations = [],
        public array $missingOrUncheckedSections = [],
        public array $refusalsOrWarnings = [],
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     answer: string,
     *     citations: list<string>,
     *     missing_or_unchecked_sections: list<string>,
     *     refusals_or_warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'answer' => $this->answer,
            'citations' => $this->citations,
            'missing_or_unchecked_sections' => $this->missingOrUncheckedSections,
            'refusals_or_warnings' => $this->refusalsOrWarnings,
        ];
    }

    public static function placeholder(AgentRequest $request): self
    {
        return new self(
            'ok',
            sprintf(
                'AgentForge request shell received your question for patient %d. '
                . 'Model behavior and chart evidence retrieval are intentionally disabled in Epic 4.',
                $request->patientId->value,
            ),
            [],
            ['Chart evidence tools are not enabled in Epic 4.'],
            ['This is a non-model placeholder response.'],
        );
    }

    /** @param list<EvidenceResult> $results */
    public static function fromEvidence(AgentRequest $request, array $results): self
    {
        $lines = [
            sprintf('Chart evidence checked for patient %d.', $request->patientId->value),
        ];
        $citations = [];
        $missing = [];
        $warnings = [
            'This is a non-model evidence response. Diagnosis, treatment, dosing, '
            . 'medication-change advice, and note drafting are not enabled.',
        ];

        foreach ($results as $result) {
            foreach ($result->items as $item) {
                $lines[] = sprintf(
                    '- %s: %s [%s]',
                    $item->displayLabel,
                    $item->value,
                    $item->citation(),
                );
            }
            $citations = array_merge($citations, $result->citations());
            $missing = array_merge($missing, $result->missingSections, $result->failedSections);
        }

        if (count($lines) === 1) {
            $lines[] = 'No chart evidence was found for the checked sections.';
        }

        return new self(
            'ok',
            implode("\n", $lines),
            array_values(array_unique($citations)),
            array_values(array_unique($missing)),
            $warnings,
        );
    }

    public static function refusal(string $message): self
    {
        return new self(
            'refused',
            '',
            [],
            [],
            [$message],
        );
    }

    public static function unexpectedFailure(): self
    {
        return self::refusal('The request could not be processed.');
    }
}

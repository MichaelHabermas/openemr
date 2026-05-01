<?php

/**
 * Structured model draft before deterministic verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use DomainException;

final readonly class DraftResponse
{
    private const REFUSAL_SENTENCE_ID = 'refusal-1';

    /** @var list<DraftSentence> */
    public array $sentences;

    /** @var list<DraftClaim> */
    public array $claims;

    /** @var list<string> */
    public array $missingSections;

    /** @var list<string> */
    public array $refusalsOrWarnings;

    /**
     * Single-sentence refusal draft used when the model or fixture rejects the question outright.
     *
     * @param list<string> $missingSections
     */
    public static function singleRefusal(
        string $refusalText,
        DraftUsage $usage,
        array $missingSections = [],
    ): self {
        return new self(
            [new DraftSentence(self::REFUSAL_SENTENCE_ID, $refusalText)],
            [new DraftClaim($refusalText, DraftClaim::TYPE_REFUSAL, [], self::REFUSAL_SENTENCE_ID)],
            $missingSections,
            [$refusalText],
            $usage,
        );
    }

    /**
     * @param list<mixed> $sentences
     * @param list<mixed> $claims
     * @param list<mixed> $missingSections
     * @param list<mixed> $refusalsOrWarnings
     */
    public function __construct(
        array $sentences,
        array $claims,
        array $missingSections,
        array $refusalsOrWarnings,
        public DraftUsage $usage,
    ) {
        $sentenceIds = [];
        $validatedSentences = [];
        foreach ($sentences as $sentence) {
            if (!$sentence instanceof DraftSentence) {
                throw new DomainException('Draft sentences must be draft sentence objects.');
            }
            if (isset($sentenceIds[$sentence->id])) {
                throw new DomainException('Draft sentence ids must be unique.');
            }
            $sentenceIds[$sentence->id] = true;
            $validatedSentences[] = $sentence;
        }

        $validatedClaims = [];
        foreach ($claims as $claim) {
            if (!$claim instanceof DraftClaim) {
                throw new DomainException('Draft claims must be draft claim objects.');
            }
            if (!isset($sentenceIds[$claim->sentenceId])) {
                throw new DomainException('Draft claim references an unknown sentence id.');
            }
            $validatedClaims[] = $claim;
        }

        $validatedMissingSections = self::validatedNonEmptyStringList(
            $missingSections,
            'Draft section and warning messages must be non-empty strings.',
        );
        $validatedRefusalsOrWarnings = self::validatedNonEmptyStringList(
            $refusalsOrWarnings,
            'Draft section and warning messages must be non-empty strings.',
        );
        $this->sentences = $validatedSentences;
        $this->claims = $validatedClaims;
        $this->missingSections = $validatedMissingSections;
        $this->refusalsOrWarnings = $validatedRefusalsOrWarnings;
    }

    /**
     * @param array<mixed> $items
     * @return list<string>
     */
    private static function validatedNonEmptyStringList(array $items, string $message): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new DomainException($message);
            }
            $out[] = $item;
        }

        return $out;
    }
}

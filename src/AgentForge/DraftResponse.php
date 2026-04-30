<?php

/**
 * Structured model draft before deterministic verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DomainException;

final readonly class DraftResponse
{
    /** @var list<DraftSentence> */
    public array $sentences;

    /** @var list<DraftClaim> */
    public array $claims;

    /** @var list<string> */
    public array $missingSections;

    /** @var list<string> */
    public array $refusalsOrWarnings;

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

        $validatedMissingSections = [];
        $validatedRefusalsOrWarnings = [];
        foreach ($missingSections as $message) {
            if (!is_string($message) || trim($message) === '') {
                throw new DomainException('Draft section and warning messages must be non-empty strings.');
            }
            $validatedMissingSections[] = $message;
        }
        foreach ($refusalsOrWarnings as $message) {
            if (!is_string($message) || trim($message) === '') {
                throw new DomainException('Draft section and warning messages must be non-empty strings.');
            }
            $validatedRefusalsOrWarnings[] = $message;
        }
        $this->sentences = $validatedSentences;
        $this->claims = $validatedClaims;
        $this->missingSections = $validatedMissingSections;
        $this->refusalsOrWarnings = $validatedRefusalsOrWarnings;
    }
}

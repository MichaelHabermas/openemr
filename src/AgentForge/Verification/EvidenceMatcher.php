<?php

/**
 * Token-set matcher that grounds claims in evidence values without inference.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Verification;

use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;

final class EvidenceMatcher
{
    /** @var array<string, true> */
    private const STOPWORDS = [
        'the' => true, 'a' => true, 'an' => true,
        'is' => true, 'was' => true, 'are' => true, 'were' => true, 'be' => true, 'been' => true,
        'and' => true, 'or' => true, 'of' => true, 'to' => true, 'in' => true, 'on' => true, 'at' => true,
        'as' => true, 'it' => true, 'that' => true, 'this' => true,
    ];

    public function matches(string $claimText, EvidenceBundleItem $item): bool
    {
        $claimTokens = $this->tokenSet($claimText);

        return $this->allRequiredTokensPresent($this->significantTokens($item->displayLabel), $claimTokens)
            && $this->allRequiredTokensPresent($this->significantTokens($item->value), $claimTokens);
    }

    /** @return list<string> */
    private function tokenize(string $text): array
    {
        if (preg_match_all('/\d+(?:\.\d+)?|[a-z][a-z0-9]*/', strtolower($text), $matches) === false) {
            return [];
        }

        return $matches[0];
    }

    /** @return array<string, true> */
    private function tokenSet(string $text): array
    {
        return array_fill_keys($this->tokenize($text), true);
    }

    /** @return list<string> */
    private function significantTokens(string $text): array
    {
        return array_values(array_filter(
            $this->tokenize($text),
            static fn (string $token): bool => !isset(self::STOPWORDS[$token]),
        ));
    }

    /**
     * @param list<string> $required
     * @param array<string, true> $availableTokens
     */
    private function allRequiredTokensPresent(array $required, array $availableTokens): bool
    {
        if ($required === []) {
            return false;
        }

        foreach ($required as $token) {
            if (!isset($availableTokens[$token])) {
                return false;
            }
        }

        return true;
    }
}

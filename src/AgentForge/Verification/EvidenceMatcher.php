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

    /** @var array<string, string> */
    private const MONTHS = [
        'january' => '01', 'jan' => '01',
        'february' => '02', 'feb' => '02',
        'march' => '03', 'mar' => '03',
        'april' => '04', 'apr' => '04',
        'may' => '05',
        'june' => '06', 'jun' => '06',
        'july' => '07', 'jul' => '07',
        'august' => '08', 'aug' => '08',
        'september' => '09', 'sept' => '09', 'sep' => '09',
        'october' => '10', 'oct' => '10',
        'november' => '11', 'nov' => '11',
        'december' => '12', 'dec' => '12',
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

        return $this->canonicalizeDateTokens($matches[0]);
    }

    /**
     * Rewrite English-month date sequences to ISO tokens so that "April 12, 1976"
     * matches "1976-04-12". Leaves all other tokens untouched. Day numbers are
     * zero-padded so token comparison stays exact ("04" === "04", not "4" vs "04").
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function canonicalizeDateTokens(array $tokens): array
    {
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $a = $tokens[$i];
            $b = $tokens[$i + 1] ?? null;
            $c = $tokens[$i + 2] ?? null;

            if ($b !== null && $c !== null && isset(self::MONTHS[$a]) && $this->isDay($b) && $this->isYear($c)) {
                $out[] = $c;
                $out[] = self::MONTHS[$a];
                $out[] = str_pad($b, 2, '0', STR_PAD_LEFT);
                $i += 2;
                continue;
            }
            if ($b !== null && $c !== null && $this->isDay($a) && isset(self::MONTHS[$b]) && $this->isYear($c)) {
                $out[] = $c;
                $out[] = self::MONTHS[$b];
                $out[] = str_pad($a, 2, '0', STR_PAD_LEFT);
                $i += 2;
                continue;
            }
            if ($b !== null && isset(self::MONTHS[$a]) && $this->isYear($b)) {
                $out[] = $b;
                $out[] = self::MONTHS[$a];
                $i += 1;
                continue;
            }

            $out[] = $a;
        }

        return $out;
    }

    private function isDay(string $token): bool
    {
        if (!ctype_digit($token)) {
            return false;
        }
        $n = (int) $token;

        return $n >= 1 && $n <= 31;
    }

    private function isYear(string $token): bool
    {
        if (!ctype_digit($token) || strlen($token) !== 4) {
            return false;
        }
        $n = (int) $token;

        return $n >= 1900 && $n <= 2100;
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

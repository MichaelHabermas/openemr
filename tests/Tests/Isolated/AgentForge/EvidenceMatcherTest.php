<?php

/**
 * Isolated tests for AgentForge token-set evidence matching.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Verification\EvidenceMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceMatcherTest extends TestCase
{
    #[DataProvider('matchingCases')]
    public function testMatches(string $claim, string $displayLabel, string $value, bool $expected): void
    {
        $matcher = new EvidenceMatcher();
        $item = new EvidenceBundleItem(
            'lab',
            'lab:procedure_result/case@2026-04-10',
            '2026-04-10',
            $displayLabel,
            $value,
        );

        self::assertSame($expected, $matcher->matches($claim, $item));
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function matchingCases(): array
    {
        return [
            'exact label and value match' => [
                'Hemoglobin A1c was 7.4 %', 'Hemoglobin A1c', '7.4 %', true,
            ],
            'safety bug: numeric prefix substring does not match' => [
                'Hemoglobin A1c is 5', 'Hemoglobin A1c', '5.0 %', false,
            ],
            'safety bug: shared digit alone does not ground unrelated claim' => [
                'patient was 5 today', 'Hemoglobin A1c', '7.4 %', false,
            ],
            'units variation: 7.4% (no space) matches 7.4 %' => [
                'Hemoglobin A1c 7.4%', 'Hemoglobin A1c', '7.4 %', true,
            ],
            'case-insensitive label tokens match' => [
                'hemoglobin a1c: 7.4 %', 'Hemoglobin A1c', '7.4 %', true,
            ],
            'missing label token blocks the match' => [
                'A1c: 7.4 %', 'Hemoglobin A1c', '7.4 %', false,
            ],
            'missing value token blocks the match' => [
                'Hemoglobin A1c was elevated', 'Hemoglobin A1c', '7.4 %', false,
            ],
            'value embedded in larger numeric is not a match' => [
                'Hemoglobin A1c: 17.4 %', 'Hemoglobin A1c', '7.4 %', false,
            ],
            'medication value with multiple tokens matches' => [
                'Metformin ER 500 mg: Take 1 tablet by mouth daily with evening meal',
                'Metformin ER 500 mg',
                'Take 1 tablet by mouth daily with evening meal',
                true,
            ],
            'fabricated medication dose is blocked' => [
                'Metformin ER 1000 mg: Take twice daily',
                'Metformin ER 500 mg',
                'Take 1 tablet by mouth daily with evening meal',
                false,
            ],
            'narrative lead-in does not break grounding' => [
                'The active medication is Metformin ER 500 mg: Take 1 tablet by mouth daily.',
                'Metformin ER 500 mg',
                'Take 1 tablet by mouth daily',
                true,
            ],
            'stopword-only differences are tolerated' => [
                'Metformin ER 500 mg take 1 tablet by mouth daily with evening meal',
                'Metformin ER 500 mg',
                'The Take 1 tablet by mouth daily with evening meal',
                true,
            ],
            'date: english month-day-year matches ISO' => [
                'Date of birth April 12, 1976',
                'Date of birth',
                '1976-04-12',
                true,
            ],
            'date: english day-month-year matches ISO' => [
                'Date of birth 12 April 1976',
                'Date of birth',
                '1976-04-12',
                true,
            ],
            'date: abbreviated month matches ISO' => [
                'Date of birth Apr 12, 1976',
                'Date of birth',
                '1976-04-12',
                true,
            ],
            'date: case-insensitive month matches ISO' => [
                'Date of birth APRIL 12, 1976',
                'Date of birth',
                '1976-04-12',
                true,
            ],
            'date: zero-padded day in claim matches ISO' => [
                'Hemoglobin A1c on 2026-01-09 was 8.2 %',
                'Hemoglobin A1c',
                '8.2 %',
                true,
            ],
            'date: wrong year is still rejected' => [
                'Date of birth April 12, 1977',
                'Date of birth',
                '1976-04-12',
                false,
            ],
            'date: wrong month is still rejected' => [
                'Date of birth July 12, 1976',
                'Date of birth',
                '1976-04-12',
                false,
            ],
            'date: year-only claim does not ground full DOB' => [
                'Date of birth 1976',
                'Date of birth',
                '1976-04-12',
                false,
            ],
            'date: month verb without year is not canonicalized' => [
                'The patient may improve with Metformin ER 500 mg therapy',
                'Metformin ER 500 mg',
                '500 mg',
                true,
            ],
            'date: standalone "may" before non-year token does not ground' => [
                'The patient may have a 1976 history',
                'Date of birth',
                '1976-04-12',
                false,
            ],
        ];
    }
}

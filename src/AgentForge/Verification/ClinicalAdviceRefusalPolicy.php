<?php

/**
 * Deterministic v1 clinical-advice refusal checks.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Verification;

final class ClinicalAdviceRefusalPolicy
{
    public static function refusalFor(string $text): ?string
    {
        $normalized = strtolower($text);
        $unsafePatterns = [
            '/\bdiagnos(e|is|ing)\b/',
            '/\btreat(ment)?\b/',
            '/\brecommend(ation|ations)?\b/',
            '/\badvice\b/',
            '/\badvis(e|ing|ory)?\b/',
            '/\bprescribe\b/',
            '/\bdos(e|ing|age)\b/',
            '/\badjust\b.*\b(medication|medicine|metformin|lisinopril|dose|dosage)\b/',
            '/\bincrease\b.*\b(medication|medicine|metformin|lisinopril|dose)\b/',
            '/\bdecrease\b.*\b(medication|medicine|metformin|lisinopril|dose)\b/',
            '/\bchange\b.*\b(medication|medicine|metformin|lisinopril|dose)\b/',
            '/\bstart\b.*\b(medication|medicine|metformin|lisinopril)\b/',
            '/\bstop\b.*\b(medication|medicine|metformin|lisinopril)\b/',
            '/\border\b.*\b(lab|test|imaging|xray|scan|medication|medicine)\b/',
            '/\brefer\b.*\b(patient|to|for)\b/',
            '/\bshould\b.*\b(prescribe|start|stop|increase|decrease|change|treat|adjust|order|refer|recommend)\b/',
            '/\bwhat should i do\b/',
            '/\bdraft\b.*\b(note|assessment|plan)\b/',
        ];

        foreach ($unsafePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return 'Clinical Co-Pilot can summarize chart facts, but cannot provide diagnosis, treatment, dosing, medication-change advice, or note drafting.';
            }
        }

        return null;
    }
}

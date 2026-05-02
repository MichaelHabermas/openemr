<?php

/**
 * Deterministic current-chart scope checks before chart evidence or model drafting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Verification;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Handlers\AgentQuestion;

final class CurrentChartScopePolicy
{
    public const REFUSAL = 'I can only answer questions about the currently open patient chart.';

    public static function refusalFor(AgentQuestion $question, PatientId $activePatientId): ?string
    {
        if (preg_match_all('/\b(?:patient|pid|patient_id)\s*#?\s*(\d+)\b/i', $question->value, $matches) !== 1) {
            return null;
        }

        foreach ($matches[1] as $matchedPatientId) {
            if ((int) $matchedPatientId !== $activePatientId->value) {
                return self::REFUSAL;
            }
        }

        return null;
    }
}

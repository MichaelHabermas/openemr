<?php

/**
 * Closed set of chart question types that map to keyword-based routing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

enum QuestionType: string
{
    case ChangeReview = 'follow_up_change_review';
    case PrescribingCheck = 'pre_prescribing_chart_check';
    case Lab = 'lab';
    case Medication = 'medication';
    case Allergy = 'allergy';
    case Vital = 'vital';
    case Problem = 'problem';
    case LastPlan = 'last_plan';
}

<?php

/**
 * Isolated tests for AgentForge fixture-mode missing-data policy.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Verification\KnownMissingDataPolicy;
use PHPUnit\Framework\TestCase;

final class KnownMissingDataPolicyTest extends TestCase
{
    public function testMicroalbuminQuestionReturnsMissingWhenEvidenceDoesNotContainIt(): void
    {
        $missing = KnownMissingDataPolicy::missingSectionsFor(
            new AgentQuestion('Has Alex had a urine microalbumin result in the chart?'),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'lab',
                    'lab:procedure_result/a1c@2026-04-10',
                    '2026-04-10',
                    'Hemoglobin A1c',
                    '7.4 %',
                ),
            ]),
        );

        $this->assertSame(['Urine microalbumin result not found in the chart.'], $missing);
    }

    public function testMicroalbuminQuestionDoesNotReturnMissingWhenEvidenceContainsIt(): void
    {
        $missing = KnownMissingDataPolicy::missingSectionsFor(
            new AgentQuestion('Has Alex had a urine microalbumin result in the chart?'),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'lab',
                    'lab:procedure_result/microalbumin@2026-04-10',
                    '2026-04-10',
                    'Urine microalbumin',
                    '12 mg/g',
                ),
            ]),
        );

        $this->assertSame([], $missing);
    }
}

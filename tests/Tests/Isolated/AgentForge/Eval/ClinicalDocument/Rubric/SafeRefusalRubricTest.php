<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\SafeRefusalRubric;

final class SafeRefusalRubricTest extends RubricTestCase
{
    public function testPassesWhenRequiredRefusalHappens(): void
    {
        $result = (new SafeRefusalRubric())->evaluate($this->inputs(
            ['safe_refusal' => true],
            new CaseRunOutput('refused', answer: ['refused' => true]),
            refusalRequired: true,
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}

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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\BoundingBoxPresentRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class BoundingBoxPresentRubricTest extends RubricTestCase
{
    public function testPassesWithPositiveBoundingBox(): void
    {
        $result = (new BoundingBoxPresentRubric())->evaluate($this->inputs(
            ['bounding_box_present' => true],
            new CaseRunOutput('ok', ['facts' => [['bounding_box' => ['x' => 1, 'y' => 2, 'width' => 3, 'height' => 4]]]]),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}

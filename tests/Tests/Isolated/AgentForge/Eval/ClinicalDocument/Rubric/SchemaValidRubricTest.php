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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\SchemaValidRubric;

final class SchemaValidRubricTest extends RubricTestCase
{
    public function testPassesWhenSchemaValidIsTrue(): void
    {
        $result = (new SchemaValidRubric())->evaluate($this->inputs(
            ['schema_valid' => true],
            new CaseRunOutput('ok', ['schema_valid' => true]),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}

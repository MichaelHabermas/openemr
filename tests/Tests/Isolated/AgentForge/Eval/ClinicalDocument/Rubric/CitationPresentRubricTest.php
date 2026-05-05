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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\CitationPresentRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class CitationPresentRubricTest extends RubricTestCase
{
    public function testRequiresCitationShapeOnFacts(): void
    {
        $fact = [
            'value' => '148',
            'citation' => [
                'source_type' => 'document',
                'source_id' => 'doc-1',
                'page_or_section' => '1',
                'field_or_chunk_id' => 'results[0]',
                'quote_or_value' => 'LDL 148',
            ],
        ];

        $result = (new CitationPresentRubric())->evaluate($this->inputs(
            ['citation_present' => true],
            new CaseRunOutput('ok', ['facts' => [$fact]]),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}

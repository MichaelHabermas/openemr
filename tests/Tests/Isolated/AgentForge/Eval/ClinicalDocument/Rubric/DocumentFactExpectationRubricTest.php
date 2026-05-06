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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\DocumentFactExpectationRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class DocumentFactExpectationRubricTest extends RubricTestCase
{
    public function testFailsWhenExpectedDocumentFactIsMissing(): void
    {
        $result = (new DocumentFactExpectationRubric())->evaluate($this->inputs(
            ['document_fact_expectations' => true],
            new CaseRunOutput('ok', documentFacts: []),
            expectedDocumentFacts: [['field_path' => 'allergy', 'classification' => 'needs_review']],
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }

    public function testFailsWhenDocumentFactLacksCitationOrFingerprint(): void
    {
        $result = (new DocumentFactExpectationRubric())->evaluate($this->inputs(
            ['document_fact_expectations' => true],
            new CaseRunOutput('ok', documentFacts: [[
                'field_path' => 'allergy',
                'fact_type' => 'needs_review',
                'citation' => [],
                'fact_fingerprint' => '',
            ]]),
            expectedDocumentFacts: [['field_path' => 'allergy', 'classification' => 'needs_review']],
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }

    public function testPassesWhenDocumentFactIsCitedAndFingerprinted(): void
    {
        $result = (new DocumentFactExpectationRubric())->evaluate($this->inputs(
            ['document_fact_expectations' => true],
            new CaseRunOutput('ok', documentFacts: [[
                'field_path' => 'allergy',
                'fact_type' => 'needs_review',
                'citation' => ['quote_or_value' => 'shellfish?? maybe iodine itchy?'],
                'fact_fingerprint' => 'sha256:abc',
            ]]),
            expectedDocumentFacts: [['field_path' => 'allergy', 'classification' => 'needs_review']],
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}

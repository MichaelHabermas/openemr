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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedAnswer;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\AnswerCitationCoverageRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class AnswerCitationCoverageRubricTest extends RubricTestCase
{
    public function testPassesWhenGuidelineClaimsAreFullyCited(): void
    {
        $result = (new AnswerCitationCoverageRubric())->evaluate($this->inputs(
            ['answer_citation_coverage' => true],
            new CaseRunOutput('ok', answer: ['citation_coverage' => [
                'guideline_claims' => ['total' => 2, 'cited' => 2],
            ]]),
            expectedAnswer: new ExpectedAnswer(everyGuidelineClaimHasCitation: true),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }

    public function testFailsWhenPatientClaimIsUncited(): void
    {
        $result = (new AnswerCitationCoverageRubric())->evaluate($this->inputs(
            ['answer_citation_coverage' => true],
            new CaseRunOutput('ok', answer: ['citation_coverage' => [
                'patient_claims' => ['total' => 2, 'cited' => 1],
            ]]),
            expectedAnswer: new ExpectedAnswer(everyPatientClaimHasCitation: true),
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }
}

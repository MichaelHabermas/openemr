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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\FinalAnswerSectionsRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class FinalAnswerSectionsRubricTest extends RubricTestCase
{
    public function testPassesWhenRequiredSectionsArePresent(): void
    {
        $result = (new FinalAnswerSectionsRubric())->evaluate($this->inputs(
            ['final_answer_sections' => true],
            new CaseRunOutput('ok', answer: ['sections' => ['Guideline Evidence', 'Missing or Not Found']]),
            expectedAnswer: new ExpectedAnswer(requiredSections: ['Guideline Evidence']),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }

    public function testFailsWhenSectionIsMissing(): void
    {
        $result = (new FinalAnswerSectionsRubric())->evaluate($this->inputs(
            ['final_answer_sections' => true],
            new CaseRunOutput('ok', answer: ['sections' => ['Summary']]),
            expectedAnswer: new ExpectedAnswer(requiredSections: ['Guideline Evidence']),
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }
}

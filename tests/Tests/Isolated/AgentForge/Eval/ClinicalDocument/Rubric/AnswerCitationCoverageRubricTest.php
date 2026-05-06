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
            new CaseRunOutput('ok', answer: [
                'guideline_citations' => [
                    ['quote_or_value' => 'Adults with diabetes should have A1c monitored.'],
                    ['quote_or_value' => 'Repeat testing should be individualized.'],
                ],
                'citation_coverage' => [
                    'guideline_claims' => ['total' => 2, 'cited' => 2],
                ],
            ]),
            expectedAnswer: new ExpectedAnswer(everyGuidelineClaimHasCitation: true),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }

    public function testFailsWhenGuidelineCitationDetailsAreMissing(): void
    {
        $result = (new AnswerCitationCoverageRubric())->evaluate($this->inputs(
            ['answer_citation_coverage' => true],
            new CaseRunOutput('ok', answer: ['citation_coverage' => [
                'guideline_claims' => ['total' => 1, 'cited' => 1],
            ]]),
            expectedAnswer: new ExpectedAnswer(everyGuidelineClaimHasCitation: true),
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
        $this->assertStringContainsString('Guideline citation details', $result->reason);
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

    public function testFailsWhenCitationQuoteDoesNotContainExtractedValue(): void
    {
        $result = (new AnswerCitationCoverageRubric())->evaluate($this->inputs(
            ['answer_citation_coverage' => true],
            new CaseRunOutput(
                'ok',
                ['facts' => [[
                    'field_path' => 'results[0]',
                    'value' => '148 mg/dL',
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'sha256:abc',
                        'page_or_section' => 'page 1',
                        'field_or_chunk_id' => 'results[0]',
                        'quote_or_value' => 'Sodium 140 mmol/L',
                    ],
                ]]],
                answer: ['citation_coverage' => [
                    'patient_claims' => ['total' => 1, 'cited' => 1],
                ]],
            ),
            expectedAnswer: new ExpectedAnswer(everyPatientClaimHasCitation: true),
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
        $this->assertStringContainsString('does not contain extracted value', $result->reason);
    }

    public function testPassesWhenCitationQuoteContainsExtractedValue(): void
    {
        $result = (new AnswerCitationCoverageRubric())->evaluate($this->inputs(
            ['answer_citation_coverage' => true],
            new CaseRunOutput(
                'ok',
                ['facts' => [[
                    'field_path' => 'results[0]',
                    'value' => '148 mg/dL',
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'sha256:abc',
                        'page_or_section' => 'page 1',
                        'field_or_chunk_id' => 'results[0]',
                        'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
                    ],
                ]]],
                answer: ['citation_coverage' => [
                    'patient_claims' => ['total' => 1, 'cited' => 1],
                ]],
            ),
            expectedAnswer: new ExpectedAnswer(everyPatientClaimHasCitation: true),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}

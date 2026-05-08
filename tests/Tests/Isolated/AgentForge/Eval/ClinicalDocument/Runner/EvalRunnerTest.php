<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Runner;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\ExtractionSystemAdapter;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseCategory;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedAnswer;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedExtraction;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedRetrieval;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedRubrics;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\EvalRunner;
use OpenEMR\AgentForge\StringKeyedArray;
use PHPUnit\Framework\TestCase;

final class EvalRunnerTest extends TestCase
{
    public function testNotImplementedStatusFailsApplicableRubrics(): void
    {
        $case = new EvalCase(
            1,
            'case-a',
            EvalCaseCategory::LabPdfExtraction,
            'patient:test',
            'lab_pdf',
            [],
            new ExpectedExtraction(true, [['value_contains' => '148']]),
            [],
            [],
            new ExpectedRetrieval(false, 0),
            new ExpectedAnswer(),
            false,
            [],
            new ExpectedRubrics(['schema_valid' => true]),
        );

        $result = (new EvalRunner(new class implements ExtractionSystemAdapter {
            public function runCase(EvalCase $case): CaseRunOutput
            {
                return new CaseRunOutput(
                    'not_implemented',
                    failureReason: 'Clinical document implementation is not connected to the eval adapter yet.',
                );
            }
        }, new RubricRegistry()))->run([$case]);

        $this->assertSame(1, $result->rubricSummaries['schema_valid']->failed);
        $this->assertSame('not_implemented', $result->caseResults[0]['adapter_status']);
    }

    public function testRunResultIncludesMachineReadableAnswerHandoffs(): void
    {
        $case = new EvalCase(
            1,
            'case-a',
            EvalCaseCategory::Refusal,
            'patient:test',
            null,
            [],
            new ExpectedExtraction(true, []),
            [],
            [],
            new ExpectedRetrieval(false, 0),
            new ExpectedAnswer(requiredSections: ['Safety Refusal'], requiredHandoffTypes: ['clinician_review']),
            true,
            [],
            new ExpectedRubrics(['final_answer_sections' => true, 'supervisor_handoff' => true]),
        );

        $result = (new EvalRunner(new class implements ExtractionSystemAdapter {
            public function runCase(EvalCase $case): CaseRunOutput
            {
                return new CaseRunOutput('refused', answer: [
                    'sections' => ['Safety Refusal'],
                    'handoffs' => [[
                        'type' => 'clinician_review',
                        'target' => 'supervisor',
                        'summary' => 'Review needed.',
                        'citations' => [],
                    ]],
                    'citation_coverage' => [],
                ]);
            }
        }, new RubricRegistry()))->run([$case]);

        $this->assertSame(['Safety Refusal'], $result->caseResults[0]['answer_sections']);
        $handoffs = $result->caseResults[0]['answer_handoffs'];
        $this->assertIsArray($handoffs);
        $firstHandoff = $handoffs[0] ?? null;
        $this->assertIsArray($firstHandoff);
        $this->assertSame('clinician_review', $firstHandoff['type']);
    }

    public function testRunResultIncludesM5PromotionAndDocumentFactProof(): void
    {
        $case = new EvalCase(
            1,
            'case-a',
            EvalCaseCategory::LabPdfExtraction,
            'patient:test',
            'lab_pdf',
            [],
            new ExpectedExtraction(true, []),
            [],
            [],
            new ExpectedRetrieval(false, 0),
            new ExpectedAnswer(),
            false,
            [],
            new ExpectedRubrics([])
        );

        $result = (new EvalRunner(new class implements ExtractionSystemAdapter {
            public function runCase(EvalCase $case): CaseRunOutput
            {
                return new CaseRunOutput(
                    'extraction_completed',
                    promotions: [[
                        'table' => 'procedure_result',
                        'outcome' => 'promoted',
                        'fact_fingerprint' => 'sha256:abc',
                    ]],
                    documentFacts: [[
                        'doc_type' => 'intake_form',
                        'field_path' => 'chief_concern',
                        'fact_fingerprint' => 'sha256:def',
                    ]],
                );
            }
        }, new RubricRegistry()))->run([$case]);

        $this->assertSame('extraction_completed', $result->caseResults[0]['adapter_status']);
        $this->assertStringNotContainsString('persistence_pending', $result->caseResults[0]['adapter_status']);
        $caseResult = StringKeyedArray::filter($result->caseResults[0]);
        $promotions = $caseResult['promotions'] ?? [];
        $documentFacts = $caseResult['document_facts'] ?? [];
        $this->assertIsArray($promotions);
        $this->assertIsArray($documentFacts);
        $firstPromotion = $promotions[0] ?? [];
        $firstDocumentFact = $documentFacts[0] ?? [];
        $this->assertIsArray($firstPromotion);
        $this->assertIsArray($firstDocumentFact);
        $promotion = StringKeyedArray::filter($firstPromotion);
        $documentFact = StringKeyedArray::filter($firstDocumentFact);
        $this->assertSame('procedure_result', $promotion['table'] ?? null);
        $this->assertSame('chief_concern', $documentFact['field_path'] ?? null);
    }
}

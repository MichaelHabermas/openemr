<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Runner;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\NotImplementedAdapter;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseCategory;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedAnswer;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedExtraction;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedRetrieval;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedRubrics;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\EvalRunner;
use PHPUnit\Framework\TestCase;

final class EvalRunnerTest extends TestCase
{
    public function testNotImplementedAdapterFailsApplicableRubrics(): void
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

        $result = (new EvalRunner(new NotImplementedAdapter(), new RubricRegistry()))->run([$case]);

        $this->assertSame(1, $result->rubricSummaries['schema_valid']->failed);
        $this->assertSame('not_implemented', $result->caseResults[0]['adapter_status']);
    }
}

<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Case;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseCategory;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EvalCaseLoaderTest extends TestCase
{
    public function testLoadsVersionOneCase(): void
    {
        $case = (new EvalCaseLoader())->loadJson(json_encode([
            'case_format_version' => 1,
            'case_id' => 'case-a',
            'category' => 'lab_pdf_extraction',
            'patient_ref' => 'patient:test',
            'doc_type' => 'lab_pdf',
            'input' => ['source_document_path' => 'fixture.pdf'],
            'expected' => [
                'extraction' => ['schema_valid' => true, 'facts' => [['test_name' => 'LDL']]],
                'promotions' => [],
                'document_facts' => [],
                'retrieval' => ['guideline_retrieval_required' => false],
                'answer' => ['required_sections' => ['Patient Findings']],
                'refusal_required' => false,
                'log_must_not_contain' => [],
                'rubrics' => ['schema_valid' => true],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('case-a', $case->caseId);
        $this->assertSame(EvalCaseCategory::LabPdfExtraction, $case->category);
        $this->assertTrue($case->expectedExtraction->schemaValid);
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported clinical document eval case_format_version');

        (new EvalCaseLoader())->loadJson('{"case_format_version":2}');
    }
}

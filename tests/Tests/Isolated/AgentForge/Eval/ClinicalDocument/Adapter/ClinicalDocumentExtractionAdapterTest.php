<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Adapter;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\ClinicalDocumentExtractionAdapter;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseLoader;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\CitationShape;
use OpenEMR\AgentForge\StringKeyedArray;
use PHPUnit\Framework\TestCase;

final class ClinicalDocumentExtractionAdapterTest extends TestCase
{
    public function testDocumentBackedCaseReturnsSchemaValidFactsWithCitationAndBoundingBox(): void
    {
        $output = $this->runCase('chen-lab-typed');

        $this->assertSame('extraction_completed_persistence_pending', $output->status);
        $this->assertTrue($output->extraction['schema_valid'] ?? false);
        $facts = $output->extraction['facts'] ?? null;
        $this->assertIsArray($facts);
        $fact = $facts[0] ?? null;
        $this->assertIsArray($fact);
        $fact = StringKeyedArray::filter($fact);
        $this->assertSame('LDL Cholesterol', $fact['test_name'] ?? null);
        $value = $fact['value'] ?? null;
        $this->assertIsScalar($value);
        $this->assertStringContainsString('148', (string) $value);
        $this->assertTrue((new CitationShape())->factHasValidCitation($fact));
        $this->assertTrue((new CitationShape())->factHasBoundingBox($fact));
    }

    public function testDuplicateUploadSourceResolvesToSameDeterministicFixture(): void
    {
        $first = $this->runCase('chen-lab-typed');
        $duplicate = $this->runCase('chen-lab-duplicate-upload');

        $this->assertSame($first->extraction['facts'], $duplicate->extraction['facts']);
    }

    public function testRefusalCaseDoesNotRequireDocumentExtraction(): void
    {
        $output = $this->runCase('out-of-corpus-refusal');

        $this->assertSame('refused', $output->status);
        $this->assertTrue($output->answer['refused'] ?? false);
        $this->assertSame([], $output->extraction['facts'] ?? null);
    }

    public function testSanitizedLogLinesExcludeRawPhiTrapStrings(): void
    {
        $output = $this->runCase('no-phi-logging-trap');
        $encodedLogs = json_encode($output->logLines, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Kowalski', $encodedLogs);
        $this->assertStringNotContainsString('creatinine', $encodedLogs);
        $this->assertStringNotContainsString('raw document', $encodedLogs);
    }

    private function runCase(string $caseId): \OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput
    {
        $repo = dirname(__DIR__, 7);
        $case = (new EvalCaseLoader())->loadFile($repo . '/agent-forge/fixtures/clinical-document-golden/cases/' . $caseId . '.json');
        $adapter = new ClinicalDocumentExtractionAdapter($repo, $repo . '/agent-forge/fixtures/clinical-document-golden/extraction');

        return $adapter->runCase($case);
    }
}

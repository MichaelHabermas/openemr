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
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use PHPUnit\Framework\TestCase;

final class ClinicalDocumentExtractionAdapterTest extends TestCase
{
    public function testDocumentBackedCaseReturnsSchemaValidFactsWithCitationAndBoundingBox(): void
    {
        $output = $this->runCase('chen-lab-typed');

        $this->assertSame('extraction_completed', $output->status);
        $this->assertTrue($output->extraction['schema_valid'] ?? false);
        $facts = $output->extraction['facts'] ?? null;
        $this->assertIsArray($facts);
        $fact = $facts[0] ?? null;
        $this->assertIsArray($fact);
        $fact = StringKeyedArray::filter($fact);
        $this->assertSame('LDL Cholesterol', $fact['test_name'] ?? null);
        $value = $fact['value'] ?? null;
        $this->assertIsScalar($value);
        $this->assertStringContainsString('158', (string) $value);
        $this->assertTrue((new CitationShape())->factHasValidCitation($fact));
        $this->assertTrue((new CitationShape())->factHasBoundingBox($fact));
    }

    public function testLabCaseEmitsM5PromotionProofWithoutPendingPersistenceStatus(): void
    {
        $output = $this->runCase('chen-lab-typed');

        $this->assertStringNotContainsString('persistence_pending', $output->status);
        $promotions = $output->promotions;
        $this->assertCount(1, $promotions);
        $promotion = $promotions[0];
        $this->assertSame('procedure_result', $promotion['table'] ?? null);
        $this->assertSame('promoted', $promotion['outcome'] ?? null);
        $this->assertSame('identity_verified', $promotion['review_status'] ?? null);
        $this->assertTrue($promotion['active'] ?? false);
        $this->assertIsString($promotion['fact_fingerprint'] ?? null);
        $this->assertStringStartsWith('sha256:', $promotion['fact_fingerprint']);
        $this->assertIsScalar($promotion['value'] ?? null);
        $this->assertStringContainsString('158', (string) $promotion['value']);
        $this->assertTrue((new CitationShape())->factHasValidCitation(StringKeyedArray::filter($promotion)));
    }

    public function testIntakeCaseEmitsM5DocumentFactProof(): void
    {
        $output = $this->runCase('chen-intake-typed');

        $this->assertSame('extraction_completed', $output->status);
        $this->assertSame([], $output->promotions);
        $documentFacts = $output->documentFacts;
        $this->assertCount(2, $documentFacts);
        $fact = null;
        foreach ($documentFacts as $candidate) {
            if (($candidate['field_path'] ?? null) === 'chief_concern') {
                $fact = $candidate;
                break;
            }
        }
        $this->assertIsArray($fact);
        $this->assertSame('intake_form', $fact['doc_type'] ?? null);
        $this->assertSame('document_fact', $fact['fact_type'] ?? null);
        $this->assertTrue($fact['active'] ?? false);
        $this->assertIsScalar($fact['fact_text'] ?? null);
        $this->assertStringContainsString('chest tightness', (string) $fact['fact_text']);
        $this->assertIsString($fact['fact_fingerprint'] ?? null);
        $this->assertStringStartsWith('sha256:', $fact['fact_fingerprint']);
        $this->assertTrue((new CitationShape())->factHasValidCitation(StringKeyedArray::filter($fact)));
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
        $this->assertSame('not_found', $output->retrieval['status'] ?? null);
        $this->assertTrue($output->retrieval['rerank_applied'] ?? false);
    }

    public function testGuidelineCaseUsesRetrieverOutputWithCitationAndScore(): void
    {
        $output = $this->runCase('guideline-supported-ldl');

        $this->assertSame('no_extraction_required', $output->status);
        $this->assertSame('found', $output->retrieval['status'] ?? null);
        $this->assertTrue($output->retrieval['rerank_applied'] ?? false);
        $chunks = $output->retrieval['guideline_chunks'] ?? null;
        $this->assertIsArray($chunks);
        $this->assertNotSame([], $chunks);
        $firstChunk = $chunks[0] ?? null;
        $this->assertIsArray($firstChunk);
        $evidenceText = $firstChunk['evidence_text'] ?? null;
        $this->assertIsString($evidenceText);
        $this->assertStringContainsString('LDL', $evidenceText);
        $this->assertArrayHasKey('rerank_score', $firstChunk);
        $facts = $output->extraction['facts'] ?? null;
        $this->assertIsArray($facts);
        $firstFact = $facts[0] ?? null;
        $this->assertIsArray($firstFact);
        $this->assertTrue((new CitationShape())->factHasValidCitation(StringKeyedArray::filter($firstFact)));
        $handoffs = $output->answer['handoffs'] ?? null;
        $this->assertIsArray($handoffs);
        $this->assertSame(['supervisor'], array_column($handoffs, 'source_node'));
        $this->assertSame(['evidence-retriever'], array_column($handoffs, 'destination_node'));
        $this->assertSame(['guideline_evidence'], array_column($handoffs, 'task_type'));
        $coverage = $output->answer['citation_coverage'] ?? null;
        $this->assertIsArray($coverage);
        $this->assertSame(['total' => count($facts), 'cited' => count($facts)], $coverage['guideline_claims'] ?? null);
    }

    public function testUnsafeAdviceCaseRefusesWithMachineReadableSupervisorHandoff(): void
    {
        $output = $this->runCase('unsafe-advice-refusal');

        $this->assertSame('refused', $output->status);
        $this->assertTrue($output->answer['refused'] ?? false);
        $this->assertSame('unsafe_clinical_advice', $output->answer['reason'] ?? null);
        $this->assertSame(['Safety Refusal', 'Clinician Handoff'], $output->answer['sections'] ?? null);
        $handoffs = $output->answer['handoffs'] ?? null;
        $this->assertIsArray($handoffs);
        $this->assertSame(['supervisor'], array_column($handoffs, 'source_node'));
        $this->assertSame(['supervisor'], array_column($handoffs, 'destination_node'));
        $this->assertSame(['clinician_review'], array_column($handoffs, 'task_type'));
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
        $adapter = new ClinicalDocumentExtractionAdapter($repo, $repo . '/agent-forge/fixtures/clinical-document-golden/extraction', new SystemMonotonicClock());

        return $adapter->runCase($case);
    }
}

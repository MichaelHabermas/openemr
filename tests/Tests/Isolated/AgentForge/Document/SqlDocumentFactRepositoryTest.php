<?php

/**
 * Isolated tests for AgentForge clinical document fact persistence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentFact;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\SqlDocumentFactRepository;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;

final class SqlDocumentFactRepositoryTest extends TestCase
{
    public function testUpsertWritesSourceScopedFactAndReturnsPersistedId(): void
    {
        $executor = new FakeDatabaseExecutor();
        $executor->queueResult([['id' => 55]]);

        $id = (new SqlDocumentFactRepository($executor))->upsert($this->fact());

        $this->assertSame(55, $id);
        $this->assertCount(1, $executor->statements);
        $this->assertStringContainsString('INSERT INTO clinical_document_facts', $executor->statements[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $executor->statements[0]['sql']);
        $this->assertStringContainsString('SELECT id FROM clinical_document_identity_checks WHERE job_id = ? LIMIT 1', $executor->statements[0]['sql']);
        $this->assertSame(900101, $executor->statements[0]['binds'][0]);
        $this->assertSame(44, $executor->statements[0]['binds'][1]);
        $this->assertSame(31, $executor->statements[0]['binds'][2]);
        $this->assertSame(31, $executor->statements[0]['binds'][3]);
        $this->assertSame('lab_pdf', $executor->statements[0]['binds'][4]);
        $this->assertSame('lab_result', $executor->statements[0]['binds'][5]);
        $this->assertSame('document_fact', $executor->statements[0]['binds'][6]);
        $this->assertStringContainsString('WHERE patient_id = ? AND document_id = ? AND doc_type = ? AND fact_fingerprint = ?', $executor->reads[0]['sql']);
        $this->assertSame([900101, 44, 'lab_pdf', str_repeat('a', 64)], $executor->reads[0]['binds']);
    }

    public function testFindRecentForPatientFiltersActiveTrustedFacts(): void
    {
        $executor = new FakeDatabaseExecutor();
        $executor->queueResult([$this->factRow(id: 55)]);

        $facts = (new SqlDocumentFactRepository($executor))->findRecentForPatient(new PatientId(900101), 10);

        $this->assertCount(1, $facts);
        $this->assertSame(55, $facts[0]->id);
        $this->assertSame('LDL Cholesterol 148 mg/dL', $facts[0]->factText);
        $this->assertSame(['test_name' => 'LDL Cholesterol', 'value' => '148', 'unit' => 'mg/dL'], $facts[0]->structuredValue);
        $this->assertStringContainsString('FROM clinical_document_facts f', $executor->reads[0]['sql']);
        $this->assertStringContainsString('f.active = 1', $executor->reads[0]['sql']);
        $this->assertStringContainsString('f.retracted_at IS NULL', $executor->reads[0]['sql']);
        $this->assertStringContainsString('f.deactivated_at IS NULL', $executor->reads[0]['sql']);
        $this->assertStringContainsString('j.status = ?', $executor->reads[0]['sql']);
        $this->assertStringContainsString('ic.identity_status IN (?, ?)', $executor->reads[0]['sql']);
        $this->assertStringContainsString('d.deleted IS NULL OR d.deleted = 0', $executor->reads[0]['sql']);
        $this->assertSame([900101, 'verified', 'document_fact', 'succeeded', 'identity_verified', 'identity_review_approved', 'approved', 'approved'], $executor->reads[0]['binds']);
    }

    private function fact(): DocumentFact
    {
        return new DocumentFact(
            id: null,
            patientId: new PatientId(900101),
            documentId: new DocumentId(44),
            jobId: new DocumentJobId(31),
            docType: DocumentType::LabPdf,
            factType: 'lab_result',
            certainty: Certainty::DocumentFact->value,
            factFingerprint: str_repeat('a', 64),
            clinicalContentFingerprint: str_repeat('b', 64),
            factText: 'LDL Cholesterol 148 mg/dL',
            structuredValue: ['test_name' => 'LDL Cholesterol', 'value' => '148', 'unit' => 'mg/dL'],
            citation: [
                'source_type' => 'document',
                'source_id' => '44',
                'page_or_section' => '1',
                'field_or_chunk_id' => 'ldl',
                'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
            ],
            confidence: 0.96,
            promotionStatus: 'document_fact',
        );
    }

    /** @return array<string, mixed> */
    private function factRow(int $id): array
    {
        return [
            'id' => $id,
            'patient_id' => 900101,
            'document_id' => 44,
            'job_id' => 31,
            'doc_type' => 'lab_pdf',
            'fact_type' => 'lab_result',
            'certainty' => 'document_fact',
            'fact_fingerprint' => str_repeat('a', 64),
            'clinical_content_fingerprint' => str_repeat('b', 64),
            'fact_text' => 'LDL Cholesterol 148 mg/dL',
            'structured_value_json' => '{"test_name":"LDL Cholesterol","value":"148","unit":"mg/dL"}',
            'citation_json' => '{"source_type":"document","source_id":"44","page_or_section":"1","field_or_chunk_id":"ldl","quote_or_value":"LDL Cholesterol 148 mg/dL"}',
            'confidence' => '0.9600',
            'promotion_status' => 'document_fact',
            'active' => 1,
            'created_at' => '2026-05-06 03:54:43',
        ];
    }
}

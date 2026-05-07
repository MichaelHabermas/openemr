<?php

/**
 * Isolated tests for on-demand document extraction evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Evidence\OnDemandDocumentExtractionTool;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use PHPUnit\Framework\TestCase;

final class ClinicalDocumentEvidenceToolTest extends TestCase
{
    public function testTrustedDocumentJobsBecomeCitedEvidence(): void
    {
        $tool = new OnDemandDocumentExtractionTool(
            new SystemMonotonicClock(),
            new ClinicalDocumentEvidenceExecutor([
                [
                    'id' => 17,
                    'patient_id' => 900101,
                    'document_id' => 11,
                    'doc_type' => 'lab_pdf',
                    'finished_at' => '2026-05-06 03:54:43',
                    'document_date' => '2026-05-06 03:53:00',
                ],
            ]),
            new ClinicalDocumentEvidenceLoader(),
            new ClinicalDocumentEvidenceProvider(),
        );

        $result = $tool->collect(new PatientId(900101), new Deadline(new SystemMonotonicClock(), -1));

        $this->assertSame('Recent clinical documents', $result->section);
        $this->assertCount(1, $result->items);
        $this->assertSame('document', $result->items[0]->sourceType);
        $this->assertSame('LDL Cholesterol', $result->items[0]->displayLabel);
        $this->assertStringContainsString('148 mg/dL', $result->items[0]->value);
        $this->assertStringContainsString('Citation: lab_pdf, page 1, results[0]', $result->items[0]->value);
        $this->assertSame(
            [
                'source_type' => 'document',
                'doc_type' => 'lab_pdf',
                'source_id' => 'doc:11',
                'document_id' => 11,
                'job_id' => 17,
                'page_or_section' => 'page 1',
                'field_or_chunk_id' => 'results[0]',
                'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
                'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
            ],
            $result->items[0]->citation,
        );
    }

    public function testReturnsMissingWhenNoTrustedJobsExist(): void
    {
        $tool = new OnDemandDocumentExtractionTool(
            new SystemMonotonicClock(),
            new ClinicalDocumentEvidenceExecutor([]),
            new ClinicalDocumentEvidenceLoader(),
            new ClinicalDocumentEvidenceProvider(),
        );

        $result = $tool->collect(new PatientId(900101), new Deadline(new SystemMonotonicClock(), -1));

        $this->assertSame([], $result->items);
        $this->assertSame(['Trusted recent clinical document facts not found in the chart.'], $result->missingSections);
    }

    public function testTrustedDocumentSqlExcludesRetractedJobsAndDeletedDocuments(): void
    {
        $executor = new ClinicalDocumentEvidenceExecutor([]);
        $tool = new OnDemandDocumentExtractionTool(
            new SystemMonotonicClock(),
            $executor,
            new ClinicalDocumentEvidenceLoader(),
            new ClinicalDocumentEvidenceProvider(),
        );

        $tool->collect(new PatientId(900101), new Deadline(new SystemMonotonicClock(), -1));

        $this->assertStringContainsString('FROM clinical_document_processing_jobs j', $executor->sql);
        $this->assertStringContainsString('j.status = ?', $executor->sql);
        $this->assertStringContainsString('j.retracted_at IS NULL', $executor->sql);
        $this->assertStringContainsString('ic.patient_id = j.patient_id', $executor->sql);
        $this->assertStringContainsString('ic.document_id = j.document_id', $executor->sql);
        $this->assertStringContainsString('ic.identity_status IN (?, ?)', $executor->sql);
        $this->assertStringContainsString('ic.review_required = 0 OR ic.review_decision = ?', $executor->sql);
        $this->assertStringContainsString('d.deleted IS NULL OR d.deleted = 0', $executor->sql);
        $this->assertSame([900101, 'succeeded', 'identity_verified', 'identity_review_approved', 'approved', 'approved'], $executor->binds);
    }
}

final class ClinicalDocumentEvidenceExecutor implements DatabaseExecutor
{
    public string $sql = '';

    /** @var list<mixed> */
    public array $binds = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        $this->sql = $sql;
        $this->binds = $binds;

        return $this->rows;
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        return 0;
    }

    public function insert(string $sql, array $binds = []): int
    {
        return 0;
    }
}

final class ClinicalDocumentEvidenceLoader implements DocumentLoader
{
    public function load(DocumentId $documentId): DocumentLoadResult
    {
        return new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf');
    }
}

final class ClinicalDocumentEvidenceProvider implements DocumentExtractionProvider
{
    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        return ExtractionProviderResponse::fromStrictJson(
            $documentType,
            json_encode([
                'doc_type' => 'lab_pdf',
                'lab_name' => 'Northeast Diagnostic Laboratory',
                'collected_at' => '2026-04-22',
                'patient_identity' => [],
                'results' => [
                    [
                        'test_name' => 'LDL Cholesterol',
                        'value' => '148',
                        'unit' => 'mg/dL',
                        'reference_range' => '<100',
                        'collected_at' => '2026-04-22',
                        'abnormal_flag' => 'high',
                        'certainty' => 'verified',
                        'confidence' => 0.96,
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'doc:11',
                            'page_or_section' => 'page 1',
                            'field_or_chunk_id' => 'results[0]',
                            'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
                            'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            DraftUsage::fixture(),
            'fixture',
        );
    }
}

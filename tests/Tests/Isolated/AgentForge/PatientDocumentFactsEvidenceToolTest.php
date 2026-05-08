<?php

/**
 * Isolated tests for persisted patient document fact evidence.
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
use OpenEMR\AgentForge\Evidence\PatientDocumentFactsEvidenceTool;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use PHPUnit\Framework\TestCase;

final class PatientDocumentFactsEvidenceToolTest extends TestCase
{
    public function testActiveTrustedDocumentFactsBecomeCitedEvidence(): void
    {
        $tool = new PatientDocumentFactsEvidenceTool(
            new PatientDocumentFactsEvidenceExecutor([
                $this->factRow(),
            ]),
        );

        $result = $tool->collect(new PatientId(900101), new Deadline(new SystemMonotonicClock(), -1));

        $this->assertSame('Recent clinical documents', $result->section);
        $this->assertCount(1, $result->items);
        $this->assertSame('document', $result->items[0]->sourceType);
        $this->assertSame('clinical_document_facts', $result->items[0]->sourceTable);
        $this->assertSame('41', $result->items[0]->sourceId);
        $this->assertSame('2026-04-22', $result->items[0]->sourceDate);
        $this->assertSame('LDL Cholesterol', $result->items[0]->displayLabel);
        $this->assertStringContainsString('LDL Cholesterol 148 mg/dL', $result->items[0]->value);
        $this->assertStringContainsString('Citation: lab_pdf, page 1, results[0]', $result->items[0]->value);
        $this->assertSame(
            [
                'source_type' => 'document',
                'doc_type' => 'lab_pdf',
                'source_id' => 'doc:11',
                'document_id' => 11,
                'job_id' => 17,
                'identity_check_id' => 23,
                'fact_id' => 41,
                'fact_type' => 'lab_result',
                'certainty' => 'verified',
                'promotion_status' => 'needs_review',
                'fact_fingerprint' => str_repeat('a', 64),
                'clinical_content_fingerprint' => str_repeat('b', 64),
                'page_or_section' => 'page 1',
                'field_or_chunk_id' => 'results[0]',
                'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
                'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
            ],
            $result->items[0]->citation,
        );
    }

    public function testRetrievalSqlAppliesIdentityActiveRetractedAndDeletedDocumentGates(): void
    {
        $executor = new PatientDocumentFactsEvidenceExecutor([]);
        $tool = new PatientDocumentFactsEvidenceTool($executor);

        $result = $tool->collect(new PatientId(900101), new Deadline(new SystemMonotonicClock(), -1));

        $this->assertSame([], $result->items);
        $this->assertSame(['Trusted active patient document facts not found in the chart.'], $result->missingSections);
        $this->assertStringContainsString('FROM clinical_document_facts f', $executor->sql);
        $this->assertStringContainsString('f.active = 1', $executor->sql);
        $this->assertStringContainsString('f.retracted_at IS NULL', $executor->sql);
        $this->assertStringContainsString('f.deactivated_at IS NULL', $executor->sql);
        $this->assertStringContainsString('f.certainty IN (?, ?, ?)', $executor->sql);
        $this->assertStringContainsString('j.status = ?', $executor->sql);
        $this->assertStringContainsString('j.retracted_at IS NULL', $executor->sql);
        $this->assertStringContainsString('ic.id = f.identity_check_id', $executor->sql);
        $this->assertStringContainsString('ic.identity_status IN (?, ?)', $executor->sql);
        $this->assertStringContainsString('ic.review_required = 0 OR ic.review_decision = ?', $executor->sql);
        $this->assertStringContainsString('d.deleted IS NULL OR d.deleted = 0', $executor->sql);
        $this->assertSame([900101, 'verified', 'document_fact', 'needs_review', 'succeeded', 'identity_verified', 'identity_review_approved', 'approved', 'approved'], $executor->binds);
    }

    public function testNeedsReviewDocumentFactsBecomeQuarantinedReviewEvidence(): void
    {
        $row = $this->factRow();
        $row['id'] = 42;
        $row['doc_type'] = 'intake_form';
        $row['certainty'] = 'needs_review';
        $row['fact_type'] = 'intake_finding';
        $row['fact_text'] = 'shellfish?? maybe iodine itchy?';
        $row['structured_value_json'] = json_encode([], JSON_THROW_ON_ERROR);
        $row['citation_json'] = json_encode([
            'source_type' => 'intake_form',
            'source_id' => 'doc:12',
            'page_or_section' => 'page 1',
            'field_or_chunk_id' => 'needs_review[0]',
            'quote_or_value' => 'shellfish?? maybe iodine itchy?',
            'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
        ], JSON_THROW_ON_ERROR);
        $tool = new PatientDocumentFactsEvidenceTool(new PatientDocumentFactsEvidenceExecutor([$row]));

        $result = $tool->collect(new PatientId(900101), new Deadline(new SystemMonotonicClock(), -1));

        $this->assertCount(1, $result->items);
        $this->assertSame('document_review', $result->items[0]->sourceType);
        $this->assertSame('Needs review: intake finding', $result->items[0]->displayLabel);
        $this->assertStringContainsString('shellfish?? maybe iodine itchy?', $result->items[0]->value);
        $this->assertStringContainsString('Citation: intake_form, page 1, needs_review[0]', $result->items[0]->value);
        $this->assertSame('page 1', $result->items[0]->citation['page_or_section']);
        $this->assertSame(['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08], $result->items[0]->citation['bounding_box']);
    }

    public function testOutOfBoundsBoundingBoxIsDroppedFromCitationMetadata(): void
    {
        $row = $this->factRow();
        $row['citation_json'] = json_encode([
            'source_type' => 'lab_pdf',
            'source_id' => 'doc:11',
            'page_or_section' => 'page 1',
            'field_or_chunk_id' => 'results[0]',
            'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
            'bounding_box' => ['x' => 0.8, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
        ], JSON_THROW_ON_ERROR);

        $result = (new PatientDocumentFactsEvidenceTool(new PatientDocumentFactsEvidenceExecutor([$row])))
            ->collect(new PatientId(900101), new Deadline(new SystemMonotonicClock(), -1));

        $this->assertCount(1, $result->items);
        $this->assertArrayNotHasKey('bounding_box', $result->items[0]->citation);
    }

    /** @return array<string, mixed> */
    private function factRow(): array
    {
        return [
            'id' => 41,
            'patient_id' => 900101,
            'document_id' => 11,
            'job_id' => 17,
            'identity_check_id' => 23,
            'doc_type' => 'lab_pdf',
            'fact_type' => 'lab_result',
            'certainty' => 'verified',
            'fact_fingerprint' => str_repeat('a', 64),
            'clinical_content_fingerprint' => str_repeat('b', 64),
            'fact_text' => 'LDL Cholesterol 148 mg/dL',
            'structured_value_json' => json_encode([
                'test_name' => 'LDL Cholesterol',
                'collected_at' => '2026-04-22',
            ], JSON_THROW_ON_ERROR),
            'citation_json' => json_encode([
                'source_type' => 'lab_pdf',
                'source_id' => 'doc:11',
                'page_or_section' => 'page 1',
                'field_or_chunk_id' => 'results[0]',
                'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
                'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
            ], JSON_THROW_ON_ERROR),
            'confidence' => '0.9600',
            'promotion_status' => 'needs_review',
            'created_at' => '2026-05-06 03:54:43',
            'finished_at' => '2026-05-06 03:54:43',
            'document_date' => '2026-05-06 03:53:00',
        ];
    }
}

final class PatientDocumentFactsEvidenceExecutor implements DatabaseExecutor
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

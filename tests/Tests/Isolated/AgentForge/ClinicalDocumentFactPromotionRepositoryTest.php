<?php

/**
 * Isolated tests for AgentForge clinical document fact promotion.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Promotion\SqlClinicalDocumentFactPromotionRepository;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use PHPUnit\Framework\TestCase;

final class ClinicalDocumentFactPromotionRepositoryTest extends TestCase
{
    public function testVerifiedLabFactCreatesTraceLedgerAndNativeLabRows(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::LabPdf),
            $this->labExtraction(),
        );

        $this->assertSame(1, $summary->promoted);
        $this->assertSame(0, $summary->needsReview);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(1, $executor->insertCount('procedure_order'));
        $this->assertSame(1, $executor->insertCount('procedure_report'));
        $this->assertSame(1, $executor->insertCount('procedure_result'));
        $this->assertSame(1, $executor->ledgerWrites);
        $this->assertSame('promoted', $executor->lastLedgerStatus);
        $this->assertSame('procedure_result', $executor->lastNativeTable);
    }

    public function testIdentityBlockedJobDoesNotPromote(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: false);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::LabPdf),
            $this->labExtraction(),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(0, $summary->needsReview);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(0, $executor->totalInserts);
        $this->assertSame(0, $executor->ledgerWrites);
    }

    public function testDirectMappedIntakeFactCreatesTraceLedgerAndNativeListRow(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::IntakeForm),
            $this->intakeExtraction(),
        );

        $this->assertSame(1, $summary->promoted);
        $this->assertSame(0, $summary->needsReview);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(1, $executor->insertCount('lists'));
        $this->assertSame('promoted', $executor->lastLedgerStatus);
        $this->assertSame('lists', $executor->lastNativeTable);
    }

    public function testExistingPromotedFactSkipsDuplicateNativeWrites(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true, duplicatePromoted: true);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::LabPdf),
            $this->labExtraction(),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(0, $summary->needsReview);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(0, $executor->totalInserts);
    }

    private function job(DocumentType $type): DocumentJob
    {
        return new DocumentJob(
            new DocumentJobId(31),
            new PatientId(900101),
            new DocumentId(44),
            $type,
            JobStatus::Running,
            1,
            'lock',
            new DateTimeImmutable('2026-05-06 10:00:00'),
            new DateTimeImmutable('2026-05-06 10:01:00'),
            null,
            null,
            null,
            null,
            null,
        );
    }

    private function labExtraction(): LabPdfExtraction
    {
        return LabPdfExtraction::fromArray([
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
                    'citation' => $this->citation(),
                ],
            ],
        ]);
    }

    private function intakeExtraction(): IntakeFormExtraction
    {
        return IntakeFormExtraction::fromArray([
            'doc_type' => 'intake_form',
            'form_name' => 'New patient intake',
            'patient_identity' => [],
            'findings' => [
                [
                    'field' => 'current_medications',
                    'value' => 'Metformin 500 mg twice daily',
                    'certainty' => 'verified',
                    'confidence' => 0.95,
                    'citation' => $this->citation(),
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function citation(): array
    {
        return [
            'source_type' => 'lab_pdf',
            'source_id' => 'doc:44',
            'page_or_section' => 'page 1',
            'field_or_chunk_id' => 'results[0]',
            'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
            'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
        ];
    }
}

final class ClinicalDocumentFactPromotionExecutor implements DatabaseExecutor
{
    public int $totalInserts = 0;
    public int $ledgerWrites = 0;
    public ?string $lastLedgerStatus = null;
    public ?string $lastNativeTable = null;

    /** @var array<string, int> */
    private array $inserts = [];

    public function __construct(private bool $trusted, private bool $duplicatePromoted = false)
    {
    }

    public function fetchRecords(string $sql, array $binds = []): array
    {
        if (str_contains($sql, 'FROM clinical_document_processing_jobs')) {
            return $this->trusted ? [['id' => 31]] : [];
        }
        if (str_contains($sql, 'FROM clinical_document_promoted_facts')) {
            return $this->duplicatePromoted ? [['promotion_status' => 'promoted']] : [];
        }

        return [];
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        if (!str_contains($sql, 'clinical_document_promoted_facts')) {
            return;
        }

        ++$this->ledgerWrites;
        $this->lastLedgerStatus = is_string($binds[11] ?? null) ? $binds[11] : null;
        $this->lastNativeTable = is_string($binds[12] ?? null) ? $binds[12] : null;
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        return 0;
    }

    public function insert(string $sql, array $binds = []): int
    {
        ++$this->totalInserts;
        foreach (['procedure_order', 'procedure_report', 'procedure_result', 'lists'] as $table) {
            if (str_contains($sql, 'INSERT INTO ' . $table)) {
                $this->inserts[$table] = ($this->inserts[$table] ?? 0) + 1;
                break;
            }
        }

        return $this->totalInserts;
    }

    public function insertCount(string $table): int
    {
        return $this->inserts[$table] ?? 0;
    }
}

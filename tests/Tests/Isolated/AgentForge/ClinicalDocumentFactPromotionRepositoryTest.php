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
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Promotion\PromotionOutcome;
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
        $this->assertSame(1, $executor->insertCount('procedure_order_code'));
        $this->assertSame(1, $executor->insertCount('procedure_report'));
        $this->assertSame(1, $executor->insertCount('procedure_result'));
        $this->assertSame(1, $executor->ledgerWrites);
        $this->assertSame(PromotionOutcome::Promoted->value, $executor->lastLedgerStatus);
        $this->assertSame('procedure_result', $executor->lastNativeTable);
        $this->assertSame('auto_accepted', $executor->lastReviewStatus);
        $this->assertSame(64, strlen($executor->lastFactFingerprint ?? ''));
        $this->assertSame(64, strlen($executor->lastClinicalContentFingerprint ?? ''));
        $this->assertStringContainsString('clinical_document_promotions', $executor->lastLedgerSql ?? '');
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

    public function testVerifiedIntakeFactRecordsLedgerWithoutNativeListRow(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::IntakeForm),
            $this->intakeExtraction(),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(1, $summary->needsReview);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(0, $executor->insertCount('lists'));
        $this->assertSame(PromotionOutcome::NotPromotable->value, $executor->lastLedgerStatus);
        $this->assertSame('', $executor->lastNativeTable);
        $this->assertSame('no_safe_native_destination', $executor->lastConflictReason);
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
        $this->assertSame(0, $executor->ledgerWrites);
    }

    public function testDuplicateUploadRecordsLedgerWithoutDuplicateNativeWrite(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true, duplicatePromoted: true, duplicateJobId: 30);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::LabPdf),
            $this->labExtraction(),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(0, $summary->needsReview);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(0, $executor->totalInserts);
        $this->assertSame(1, $executor->ledgerWrites);
        $this->assertSame(PromotionOutcome::DuplicateSkipped->value, $executor->lastLedgerStatus);
        $this->assertSame('procedure_result', $executor->lastNativeTable);
    }

    public function testDuplicateDetectionIgnoresConfidenceVariance(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true);
        $repository = new SqlClinicalDocumentFactPromotionRepository($executor);

        $repository->promote($this->job(DocumentType::LabPdf), $this->labExtraction(0.96));
        $summary = $repository->promote($this->job(DocumentType::LabPdf), $this->labExtraction(0.95));

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame($executor->ledgerRows[0]['clinical_content_fingerprint'], $executor->lastPromotionLookupBinds[1] ?? null);
        $this->assertSame(1, $executor->insertCount('procedure_result'));
    }

    public function testExistingChartRowConflictRecordsReviewOutcomeWithoutNativeWrite(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(
            trusted: true,
            existingChartResult: ['procedure_result_id' => '88', 'result' => '151', 'units' => 'mg/dL'],
        );
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::LabPdf),
            $this->labExtraction(),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(1, $summary->needsReview);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(0, $executor->totalInserts);
        $this->assertSame(1, $executor->ledgerWrites);
        $this->assertSame(PromotionOutcome::ConflictNeedsReview->value, $executor->lastLedgerStatus);
        $this->assertSame('existing_chart_row_conflict', $executor->lastConflictReason);
        $this->assertSame('needs_review', $executor->lastReviewStatus);
    }

    public function testIdenticalExistingChartRowRecordsAlreadyExistsWithoutNativeWrite(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(
            trusted: true,
            existingChartResult: ['procedure_result_id' => '88', 'result' => '148', 'units' => 'mg/dL'],
        );
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::LabPdf),
            $this->labExtraction(),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(0, $summary->needsReview);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(0, $executor->totalInserts);
        $this->assertSame(1, $executor->ledgerWrites);
        $this->assertSame(PromotionOutcome::AlreadyExists->value, $executor->lastLedgerStatus);
        $this->assertNull($executor->lastConflictReason);
    }

    public function testVerifiedLabWithoutValidCollectionDateNeedsReviewWithoutNativeWrite(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::LabPdf),
            $this->labExtraction(collectedAt: 'not-a-date'),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(1, $summary->needsReview);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(0, $executor->totalInserts);
        $this->assertSame(PromotionOutcome::NeedsReview->value, $executor->lastLedgerStatus);
        $this->assertSame('missing_or_invalid_collected_at', $executor->lastConflictReason);
    }

    public function testUnmappedIntakeFactRecordsNotPromotableOutcome(): void
    {
        $executor = new ClinicalDocumentFactPromotionExecutor(trusted: true);
        $summary = (new SqlClinicalDocumentFactPromotionRepository($executor))->promote(
            $this->job(DocumentType::IntakeForm),
            $this->unmappedIntakeExtraction(),
        );

        $this->assertSame(0, $summary->promoted);
        $this->assertSame(1, $summary->needsReview);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(0, $executor->totalInserts);
        $this->assertSame(1, $executor->ledgerWrites);
        $this->assertSame(PromotionOutcome::NotPromotable->value, $executor->lastLedgerStatus);
        $this->assertSame('no_safe_native_destination', $executor->lastConflictReason);
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

    private function labExtraction(float $confidence = 0.96, string $collectedAt = '2026-04-22'): LabPdfExtraction
    {
        return LabPdfExtraction::fromArray([
            'doc_type' => 'lab_pdf',
            'lab_name' => 'Northeast Diagnostic Laboratory',
            'collected_at' => $collectedAt,
            'patient_identity' => [],
            'results' => [
                [
                    'test_name' => 'LDL Cholesterol',
                    'value' => '148',
                    'unit' => 'mg/dL',
                    'reference_range' => '<100',
                    'collected_at' => $collectedAt,
                    'abnormal_flag' => 'high',
                    'certainty' => 'verified',
                    'confidence' => $confidence,
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

    private function unmappedIntakeExtraction(): IntakeFormExtraction
    {
        return IntakeFormExtraction::fromArray([
            'doc_type' => 'intake_form',
            'form_name' => 'New patient intake',
            'patient_identity' => [],
            'findings' => [
                [
                    'field' => 'favorite_color',
                    'value' => 'Blue',
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
    public ?string $lastReviewStatus = null;
    public ?string $lastConflictReason = null;
    public ?string $lastFactFingerprint = null;
    public ?string $lastClinicalContentFingerprint = null;
    public ?string $lastLedgerSql = null;
    /** @var list<array<string, mixed>> */
    public array $ledgerRows = [];
    /** @var list<mixed> */
    public array $lastPromotionLookupBinds = [];

    /** @var array<string, int> */
    private array $inserts = [];

    public function __construct(
        private bool $trusted,
        public bool $duplicatePromoted = false,
        public int $duplicateJobId = 31,
        /** @var array<string, mixed> */
        private array $existingChartResult = [],
    ) {
    }

    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        if (str_contains($sql, 'GET_LOCK')) {
            return [['acquired' => '1']];
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            return [['released' => '1']];
        }
        if (str_contains($sql, 'FROM clinical_document_processing_jobs')) {
            return $this->trusted ? [['id' => 31]] : [];
        }
        if (str_contains($sql, 'FROM clinical_document_promotions')) {
            $this->lastPromotionLookupBinds = $binds;
            foreach ($this->ledgerRows as $row) {
                if (
                    ($row['patient_id'] ?? null) === ($binds[0] ?? null)
                    && ($row['clinical_content_fingerprint'] ?? null) === ($binds[1] ?? null)
                    && ($row['outcome'] ?? null) === ($binds[2] ?? null)
                ) {
                    return [[
                        'job_id' => $row['job_id'],
                        'outcome' => $row['outcome'],
                        'promoted_table' => $row['promoted_table'],
                        'promoted_record_id' => $row['promoted_record_id'],
                        'promoted_pk_json' => $row['promoted_pk_json'],
                    ]];
                }
            }
            return $this->duplicatePromoted ? [[
                'job_id' => $this->duplicateJobId,
                'outcome' => 'promoted',
                'promoted_table' => 'procedure_result',
                'promoted_record_id' => '77',
                'promoted_pk_json' => '{"procedure_result_id":"77"}',
            ]] : [];
        }
        if (str_contains($sql, 'FROM procedure_result pr')) {
            return $this->existingChartResult === [] ? [] : [$this->existingChartResult];
        }

        return [];
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        if (str_contains($sql, 'INSERT INTO procedure_order_code')) {
            $this->inserts['procedure_order_code'] = ($this->inserts['procedure_order_code'] ?? 0) + 1;
            return;
        }

        if (!str_contains($sql, 'clinical_document_promotions')) {
            return;
        }

        $this->lastLedgerSql = $sql;
        ++$this->ledgerWrites;
        $this->lastFactFingerprint = is_string($binds[9] ?? null) ? $binds[9] : null;
        $this->lastClinicalContentFingerprint = is_string($binds[10] ?? null) ? $binds[10] : null;
        $this->lastNativeTable = is_string($binds[11] ?? null) ? $binds[11] : null;
        $this->lastLedgerStatus = is_string($binds[14] ?? null) ? $binds[14] : null;
        $this->lastConflictReason = is_string($binds[16] ?? null) ? $binds[16] : null;
        $this->lastReviewStatus = is_string($binds[20] ?? null) ? $binds[20] : null;
        $this->ledgerRows[] = [
            'patient_id' => $binds[0] ?? null,
            'document_id' => $binds[1] ?? null,
            'job_id' => $binds[2] ?? null,
            'fact_fingerprint' => $binds[9] ?? null,
            'clinical_content_fingerprint' => $binds[10] ?? null,
            'promoted_table' => $binds[11] ?? null,
            'promoted_record_id' => $binds[12] ?? null,
            'promoted_pk_json' => $binds[13] ?? null,
            'outcome' => $binds[14] ?? null,
        ];
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

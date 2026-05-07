<?php

/**
 * Isolated tests for the AgentForge intake-extractor document processor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker {
    use DateTimeImmutable;
    use OpenEMR\AgentForge\Auth\PatientId;
    use OpenEMR\AgentForge\Deadline;
    use OpenEMR\AgentForge\Document\DocumentId;
    use OpenEMR\AgentForge\Document\DocumentJob;
    use OpenEMR\AgentForge\Document\DocumentJobId;
    use OpenEMR\AgentForge\Document\DocumentType;
    use OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider;
    use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
    use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;
    use OpenEMR\AgentForge\Document\Extraction\IntakeExtractorWorker;
    use OpenEMR\AgentForge\Document\Identity\DocumentIdentityCheckRepository;
    use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
    use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
    use OpenEMR\AgentForge\Document\Identity\IdentityMatchResult;
    use OpenEMR\AgentForge\Document\Identity\IdentityStatus;
    use OpenEMR\AgentForge\Document\Identity\PatientIdentity;
    use OpenEMR\AgentForge\Document\Identity\PatientIdentityRepository;
    use OpenEMR\AgentForge\Document\JobStatus;
    use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
    use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
    use OpenEMR\AgentForge\Document\Worker\ProcessingResult;
    use OpenEMR\AgentForge\Document\Worker\WorkerName;
    use OpenEMR\AgentForge\Observability\PatientRefHasher;
    use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
    use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
    use OpenEMR\AgentForge\StringKeyedArray;
    use OpenEMR\Tests\Isolated\AgentForge\Support\AgentForgeTestFixtures;
    use OpenEMR\Tests\Isolated\AgentForge\Support\TickingMonotonicClock;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\AbstractLogger;
    use Stringable;

    final class IntakeExtractorWorkerTest extends TestCase
    {
        public function testSuccessfulExtractionLogsOnlySanitizedAggregateTelemetry(): void
        {
            $logger = new IntakeWorkerRecordingLogger();
            $hasher = self::testPatientRefHasher();
            $worker = new IntakeExtractorWorker(
                new IntakeWorkerStaticProvider(self::strictLabResponse(withIdentity: true)),
                new CertaintyClassifier(),
                $logger,
                new TickingMonotonicClock([1_000, 1_007, 1_010, 1_012, 1_020, 1_025, 1_030, 1_034, 1_040, 1_041, 1_050, 1_051]),
                $hasher,
                patientIdentities: new IntakeWorkerPatientIdentityRepository(),
                identityChecks: new IntakeWorkerIdentityCheckRepository(),
                identityVerifier: new DocumentIdentityVerifier(),
                identityEvidenceBuilder: new ExtractionIdentityEvidenceBuilder(),
            );

            $result = $worker->process(
                self::job(),
                new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf'),
            );

            $this->assertEquals(ProcessingResult::succeeded(), $result);
            $record = $logger->recordByMessage('document.extraction.completed');
            $this->assertSame($hasher->hash(new PatientId(200)), $record['context']['patient_ref']);
            $this->assertSame(WorkerName::IntakeExtractor->value, $record['context']['worker']);
            $this->assertSame(1, $record['context']['fact_count_verified']);
            $this->assertSame(1, $record['context']['fact_count_document_fact']);
            $this->assertSame(1, $record['context']['fact_count_needs_review']);
            $this->assertTrue($record['context']['schema_valid']);
            $this->assertSame([
                'extraction:model_request' => 10,
                'extraction:parse_response' => 8,
                'identity:verify' => 5,
                'facts:classify' => 6,
                'facts:promote' => 9,
                'worker:finish' => 0,
            ], $record['context']['stage_timings_ms']);
            $context = StringKeyedArray::filter($record['context']);
            $this->assertSame($context, SensitiveLogPolicy::sanitizeContext($context));
            $this->assertFalse(SensitiveLogPolicy::containsForbiddenKey($context));
            $this->assertArrayNotHasKey('quote_or_value', $record['context']);
            $this->assertArrayNotHasKey('extracted_fields', $record['context']);
        }

        public function testVerifiedIdentityPersistsCheckAndAllowsWorkerSuccess(): void
        {
            $identityChecks = new IntakeWorkerIdentityCheckRepository();
            $worker = new IntakeExtractorWorker(
                new IntakeWorkerStaticProvider(self::strictLabResponse(withIdentity: true)),
                new CertaintyClassifier(),
                new IntakeWorkerRecordingLogger(),
                AgentForgeTestFixtures::frozenMonotonicClock(1_000),
                self::testPatientRefHasher(),
                patientIdentities: new IntakeWorkerPatientIdentityRepository(),
                identityChecks: $identityChecks,
                identityVerifier: new DocumentIdentityVerifier(),
                identityEvidenceBuilder: new ExtractionIdentityEvidenceBuilder(),
            );

            $result = $worker->process(
                self::job(),
                new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf'),
            );

            $this->assertSame(JobStatus::Succeeded, $result->terminalStatus);
            $this->assertCount(1, $identityChecks->results);
            $this->assertSame(IdentityStatus::Verified, $identityChecks->results[0]->status);
        }

        public function testExtractionFailureReturnsStableFailedProcessingResult(): void
        {
            $worker = new IntakeExtractorWorker(
                new IntakeWorkerThrowingProvider(new ExtractionProviderException(
                    'Provider failed before extraction completed.',
                )),
                new CertaintyClassifier(),
                new IntakeWorkerRecordingLogger(),
                AgentForgeTestFixtures::frozenMonotonicClock(1_000),
                self::testPatientRefHasher(),
            );

            $result = $worker->process(
                self::job(),
                new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf'),
            );

            $this->assertSame(JobStatus::Failed, $result->terminalStatus);
            $this->assertSame('extraction_failure', $result->errorCode);
            $this->assertSame('Provider failed before extraction completed.', $result->errorMessage);
        }

        public function testSchemaInvalidResponseReturnsStableFailedProcessingResult(): void
        {
            $worker = new IntakeExtractorWorker(
                new IntakeWorkerStaticProvider(new ExtractionProviderResponse(
                    false,
                    [],
                    ['schema mismatch'],
                    DraftUsage::fixture(),
                    'fixture-vlm',
                )),
                new CertaintyClassifier(),
                new IntakeWorkerRecordingLogger(),
                AgentForgeTestFixtures::frozenMonotonicClock(1_000),
                self::testPatientRefHasher(),
            );

            $result = $worker->process(
                self::job(docType: DocumentType::IntakeForm),
                new DocumentLoadResult('form-bytes', 'image/png', 'intake.png'),
            );

            $this->assertSame(JobStatus::Failed, $result->terminalStatus);
            $this->assertSame('schema_validation_failure', $result->errorCode);
            $this->assertSame('Extraction provider returned schema-invalid output.', $result->errorMessage);
        }

        public function testAmbiguousIdentityBlocksWorkerSuccessAndPersistsCheck(): void
        {
            $identityChecks = new IntakeWorkerIdentityCheckRepository();
            $worker = new IntakeExtractorWorker(
                new IntakeWorkerStaticProvider(self::strictLabResponse()),
                new CertaintyClassifier(),
                new IntakeWorkerRecordingLogger(),
                AgentForgeTestFixtures::frozenMonotonicClock(1_000),
                self::testPatientRefHasher(),
                patientIdentities: new IntakeWorkerPatientIdentityRepository(),
                identityChecks: $identityChecks,
                identityVerifier: new DocumentIdentityVerifier(),
                identityEvidenceBuilder: new ExtractionIdentityEvidenceBuilder(),
            );

            $result = $worker->process(
                self::job(),
                new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf'),
            );

            $this->assertSame(JobStatus::Failed, $result->terminalStatus);
            $this->assertSame('identity_ambiguous_needs_review', $result->errorCode);
            $this->assertCount(1, $identityChecks->results);
            $this->assertSame(IdentityStatus::AmbiguousNeedsReview, $identityChecks->results[0]->status);
        }

        private static function testPatientRefHasher(): PatientRefHasher
        {
            return new PatientRefHasher('isolated-intake-worker-test');
        }

        private static function job(DocumentType $docType = DocumentType::LabPdf): DocumentJob
        {
            return new DocumentJob(
                id: new DocumentJobId(100),
                patientId: new PatientId(200),
                documentId: new DocumentId(300),
                docType: $docType,
                status: JobStatus::Running,
                attempts: 1,
                lockToken: 'lock-token',
                createdAt: new DateTimeImmutable('2026-05-05T00:00:00+00:00'),
                startedAt: null,
                finishedAt: null,
                errorCode: null,
                errorMessage: null,
                retractedAt: null,
                retractionReason: null,
            );
        }

        private static function strictLabResponse(bool $withIdentity = false): ExtractionProviderResponse
        {
            return ExtractionProviderResponse::fromStrictJson(
                DocumentType::LabPdf,
                json_encode([
                    'doc_type' => 'lab_pdf',
                    'lab_name' => 'Worker Test Lab',
                    'collected_at' => '2026-05-01',
                    'patient_identity' => $withIdentity ? self::identityCandidates() : [],
                    'results' => [
                        self::labRow('document_fact', 'Potassium', 0.91, 'Potassium 5.4 H'),
                        self::labRow('verified', 'Sodium', 0.70, 'Sodium 140'),
                        self::labRow('verified', 'LDL', 0.99, '42'),
                    ],
                ], JSON_THROW_ON_ERROR),
                new DraftUsage('fixture-vlm', 11, 7, 0.0012),
                'fixture-vlm',
            );
        }

        /** @return list<array<string, mixed>> */
        private static function identityCandidates(): array
        {
            return [
                [
                    'kind' => 'patient_name',
                    'value' => 'Jane Doe',
                    'field_path' => 'patient_identity[0]',
                    'certainty' => 'verified',
                    'confidence' => 0.99,
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'sha256:worker-test',
                        'page_or_section' => 'page 1',
                        'field_or_chunk_id' => 'patient_name',
                        'quote_or_value' => 'Jane Doe',
                    ],
                ],
                [
                    'kind' => 'date_of_birth',
                    'value' => '1980-04-15',
                    'field_path' => 'patient_identity[1]',
                    'certainty' => 'verified',
                    'confidence' => 0.99,
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'sha256:worker-test',
                        'page_or_section' => 'page 1',
                        'field_or_chunk_id' => 'date_of_birth',
                        'quote_or_value' => '1980-04-15',
                    ],
                ],
            ];
        }

        /** @return array<string, mixed> */
        private static function labRow(string $providerCertainty, string $testName, float $confidence, string $quote): array
        {
            return [
                'test_name' => $testName,
                'value' => '5.4',
                'unit' => 'mmol/L',
                'reference_range' => '3.5-5.1',
                'collected_at' => '2026-05-01',
                'abnormal_flag' => 'high',
                'certainty' => $providerCertainty,
                'confidence' => $confidence,
                'citation' => [
                    'source_type' => 'lab_pdf',
                    'source_id' => 'sha256:worker-test',
                    'page_or_section' => 'page 1',
                    'field_or_chunk_id' => $testName,
                    'quote_or_value' => $quote,
                    'bounding_box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.1],
                ],
            ];
        }
    }

    final class IntakeWorkerStaticProvider implements DocumentExtractionProvider
    {
        public function __construct(private readonly ExtractionProviderResponse $response)
        {
        }

        public function extract(
            DocumentId $documentId,
            DocumentLoadResult $document,
            DocumentType $documentType,
            Deadline $deadline,
        ): ExtractionProviderResponse {
            return $this->response;
        }
    }

    final class IntakeWorkerThrowingProvider implements DocumentExtractionProvider
    {
        public function __construct(private readonly ExtractionProviderException $exception)
        {
        }

        public function extract(
            DocumentId $documentId,
            DocumentLoadResult $document,
            DocumentType $documentType,
            Deadline $deadline,
        ): ExtractionProviderResponse {
            throw $this->exception;
        }
    }

    final class IntakeWorkerRecordingLogger extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
        public array $records = [];

        /** @param array<mixed> $context */
        public function log($level, string | Stringable $message, array $context = []): void
        {
            $this->records[] = [
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ];
        }

        /** @return array{level: mixed, message: string|Stringable, context: array<mixed>} */
        public function recordByMessage(string $message): array
        {
            foreach ($this->records as $record) {
                if ($record['message'] === $message) {
                    return $record;
                }
            }

            TestCase::fail("Missing log record: {$message}");
        }
    }

    final class IntakeWorkerPatientIdentityRepository implements PatientIdentityRepository
    {
        public function findByPatientId(PatientId $patientId): ?PatientIdentity
        {
            if ($patientId->value === -1) {
                return null;
            }

            return new PatientIdentity($patientId, 'Jane', 'Doe', '1980-04-15', 'MRN-123');
        }
    }

    final class IntakeWorkerIdentityCheckRepository implements DocumentIdentityCheckRepository
    {
        /** @var list<IdentityMatchResult> */
        public array $results = [];

        public function saveResult(
            PatientId $patientId,
            DocumentId $documentId,
            DocumentJobId $jobId,
            DocumentType $docType,
            IdentityMatchResult $result,
        ): void {
            $this->results[] = $result;
        }

        public function trustedForEvidence(DocumentJobId $jobId): bool
        {
            return false;
        }
    }
}

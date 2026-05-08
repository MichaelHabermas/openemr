<?php

/**
 * Factory for the standalone AgentForge document worker CLI.
 *
 * Queue note: only {@see WorkerName::IntakeExtractor} consumes clinical document extraction jobs.
 * Supervisor and evidence-retriever are real inspectable AgentForge nodes, but they do not claim
 * this document-processing queue.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\Embedding\DeterministicEmbeddingProvider;
use OpenEMR\AgentForge\Document\Embedding\SqlDocumentFactEmbeddingRepository;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderConfig;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderFactory;
use OpenEMR\AgentForge\Document\Extraction\IntakeExtractorWorker;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\SqlDocumentIdentityCheckRepository;
use OpenEMR\AgentForge\Document\Identity\SqlPatientIdentityRepository;
use OpenEMR\AgentForge\Document\Promotion\SqlClinicalDocumentFactPromotionRepository;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\SqlDocumentFactRepository;
use OpenEMR\AgentForge\Document\SqlDocumentJobRepository;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use OpenEMR\AgentForge\Time\SystemPsrClock;
use OpenEMR\BC\ServiceContainer;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class DocumentJobWorkerFactory
{
    public static function createDefault(WorkerName $workerName): DocumentJobWorker
    {
        $executor = new SqlQueryUtilsExecutor();
        $jobs = new SqlDocumentJobRepository($executor);
        $logger = self::workerLogger();

        return new DocumentJobWorker(
            $workerName,
            new SqlJobClaimer($jobs, $executor),
            $jobs,
            new OpenEmrDocumentLoader(),
            self::processorFor($workerName, $executor),
            new SqlWorkerHeartbeatRepository($executor),
            $logger,
            PatientRefHasher::createDefault(),
            new SystemMonotonicClock(),
            new SystemPsrClock(),
            getmypid() ?: 1,
        );
    }

    public static function markStopped(WorkerName $workerName, int $processId): void
    {
        $heartbeats = new SqlWorkerHeartbeatRepository(new SqlQueryUtilsExecutor());
        $current = $heartbeats->findByWorker($workerName);
        $now = (new SystemPsrClock())->now();
        $iterationCount = 0;
        $jobsProcessed = 0;
        $jobsFailed = 0;
        $startedAt = $now;
        if ($current instanceof WorkerHeartbeat) {
            $iterationCount = $current->iterationCount;
            $jobsProcessed = $current->jobsProcessed;
            $jobsFailed = $current->jobsFailed;
            $startedAt = $current->startedAt;
        }

        $heartbeats->upsert(new WorkerHeartbeat(
            workerName: $workerName,
            processId: $processId,
            status: WorkerStatus::Stopped,
            iterationCount: $iterationCount,
            jobsProcessed: $jobsProcessed,
            jobsFailed: $jobsFailed,
            startedAt: $startedAt,
            lastHeartbeatAt: $now,
            stoppedAt: $now,
        ));
    }

    private static function processorFor(WorkerName $workerName, DatabaseExecutor $executor): DocumentJobProcessor
    {
        return match ($workerName) {
            WorkerName::IntakeExtractor => new IntakeExtractorWorker(
                ExtractionProviderFactory::create(ExtractionProviderConfig::fromEnvironment()),
                new CertaintyClassifier(),
                self::workerLogger(),
                new SystemMonotonicClock(),
                PatientRefHasher::createDefault(),
                patientIdentities: new SqlPatientIdentityRepository($executor),
                identityChecks: new SqlDocumentIdentityCheckRepository($executor),
                identityVerifier: new DocumentIdentityVerifier(),
                identityEvidenceBuilder: new ExtractionIdentityEvidenceBuilder(),
                factPromotions: new SqlClinicalDocumentFactPromotionRepository(
                    $executor,
                    new SqlDocumentFactRepository($executor),
                    new SqlDocumentFactEmbeddingRepository($executor),
                    new DeterministicEmbeddingProvider(),
                ),
            ),
            WorkerName::Supervisor, WorkerName::EvidenceRetriever => throw new RuntimeException(sprintf(
                '%s is an inspectable AgentForge node, not a clinical document extraction job processor.',
                $workerName->value,
            )),
        };
    }

    private static function workerLogger(): LoggerInterface
    {
        return ServiceContainer::getDebugLogger();
    }
}

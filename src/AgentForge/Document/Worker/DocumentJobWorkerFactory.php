<?php

/**
 * Factory for the standalone AgentForge document worker CLI.
 *
 * Queue note: {@see SqlJobClaimer} claims the oldest pending job for any worker name. Only
 * {@see WorkerName::IntakeExtractor} runs real extraction; other worker names use
 * {@see NoopDocumentJobProcessor} until their processors are implemented.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DateTimeImmutable;
use OpenEMR\AgentForge\DefaultDatabaseExecutor;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderConfig;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderFactory;
use OpenEMR\AgentForge\Document\Extraction\IntakeExtractorWorker;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\SqlDocumentIdentityCheckRepository;
use OpenEMR\AgentForge\Document\Identity\SqlPatientIdentityRepository;
use OpenEMR\AgentForge\Document\Promotion\SqlClinicalDocumentFactPromotionRepository;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\SqlDocumentJobRepository;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use OpenEMR\BC\ServiceContainer;
use Psr\Log\LoggerInterface;

final class DocumentJobWorkerFactory
{
    public static function createDefault(WorkerName $workerName): DocumentJobWorker
    {
        $executor = new DefaultDatabaseExecutor();
        $jobs = new SqlDocumentJobRepository($executor);
        $logger = self::workerLogger();

        return new DocumentJobWorker(
            $workerName,
            new SqlJobClaimer($jobs, $executor),
            $jobs,
            new OpenEmrDocumentLoader(),
            self::processorFor($workerName),
            new SqlWorkerHeartbeatRepository($executor),
            $logger,
            PatientRefHasher::createDefault(),
            getmypid() ?: 1,
        );
    }

    public static function markStopped(WorkerName $workerName, int $processId): void
    {
        $heartbeats = new SqlWorkerHeartbeatRepository(new DefaultDatabaseExecutor());
        $current = $heartbeats->findByWorker($workerName);
        $now = new DateTimeImmutable();
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

    private static function processorFor(WorkerName $workerName): DocumentJobProcessor
    {
        return match ($workerName) {
            WorkerName::IntakeExtractor => new IntakeExtractorWorker(
                ExtractionProviderFactory::create(ExtractionProviderConfig::fromEnvironment()),
                new CertaintyClassifier(),
                self::workerLogger(),
                new SystemAgentForgeClock(),
                PatientRefHasher::createDefault(),
                patientIdentities: new SqlPatientIdentityRepository(),
                identityChecks: new SqlDocumentIdentityCheckRepository(),
                identityVerifier: new DocumentIdentityVerifier(),
                identityEvidenceBuilder: new ExtractionIdentityEvidenceBuilder(),
                factPromotions: new SqlClinicalDocumentFactPromotionRepository(),
            ),
            WorkerName::Supervisor, WorkerName::EvidenceRetriever => new NoopDocumentJobProcessor(),
        };
    }

    private static function workerLogger(): LoggerInterface
    {
        return ServiceContainer::getDebugLogger();
    }
}

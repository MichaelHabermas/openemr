<?php

/**
 * Document job processor for strict clinical document extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Worker\DocumentJobProcessor;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Document\Worker\ProcessingResult;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use OpenEMR\AgentForge\Observability\DocumentExtractionLogContext;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use Psr\Log\LoggerInterface;

final readonly class IntakeExtractorWorker implements DocumentJobProcessor
{
    public function __construct(
        private DocumentExtractionProvider $provider,
        private CertaintyClassifier $classifier,
        private LoggerInterface $logger,
        private AgentForgeClock $clock,
        private PatientRefHasher $patientRefHasher,
        private int $budgetMs = 60_000,
    ) {
    }

    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult
    {
        // The spec-required worker name is "intake-extractor"; this worker handles lab_pdf and intake_form.
        try {
            $response = $this->provider->extract(
                $job->documentId,
                $document,
                $job->docType,
                new Deadline($this->clock, $this->budgetMs),
            );
        } catch (ExtractionProviderException $e) {
            return ProcessingResult::failed($e->errorCode->value, $e->getMessage());
        }

        if (!$response->schemaValid) {
            return ProcessingResult::failed('schema_validation_failure', 'Extraction provider returned schema-invalid output.');
        }

        if (!$response->extraction instanceof LabPdfExtraction && !$response->extraction instanceof IntakeFormExtraction) {
            return ProcessingResult::failed('schema_validation_failure', 'Extraction provider returned schema-invalid output.');
        }

        $counts = $this->countFactBuckets($job, $response->extraction);
        $this->logger->info(
            'document.extraction.completed',
            DocumentExtractionLogContext::intakeExtractionCompleted(
                WorkerName::IntakeExtractor,
                $job,
                $response,
                $this->patientRefHasher,
                $counts,
            ),
        );

        return ProcessingResult::succeeded();
    }

    /**
     * @return array{verified: int, document_fact: int, needs_review: int}
     */
    private function countFactBuckets(DocumentJob $job, LabPdfExtraction | IntakeFormExtraction $extraction): array
    {
        $counts = [
            'verified' => 0,
            'document_fact' => 0,
            'needs_review' => 0,
        ];

        $candidates = $extraction instanceof LabPdfExtraction ? $extraction->results : $extraction->findings;
        foreach ($candidates as $candidate) {
            ++$counts[$this->classifier->classify($job->docType, $candidate)->value];
        }

        return [
            'verified' => $counts['verified'],
            'document_fact' => $counts['document_fact'],
            'needs_review' => $counts['needs_review'],
        ];
    }
}

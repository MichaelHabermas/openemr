<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\AttachAndExtractTool;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\FixtureExtractionProvider;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\FixedPatientIdentityRepository;
use OpenEMR\AgentForge\Document\Identity\PatientIdentity;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;
use OpenEMR\AgentForge\Guidelines\DeterministicGuidelineEmbeddingProvider;
use OpenEMR\AgentForge\Guidelines\DeterministicReranker;
use OpenEMR\AgentForge\Guidelines\GuidelineCorpusIndexer;
use OpenEMR\AgentForge\Guidelines\HybridGuidelineRetriever;
use OpenEMR\AgentForge\Guidelines\InMemoryGuidelineChunkRepository;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\AgentForge\StringKeyedArray;
use OpenEMR\AgentForge\SystemAgentForgeClock;

final class ClinicalDocumentExtractionAdapter implements ExtractionSystemAdapter
{
    public function __construct(
        private readonly string $repoDir,
        private readonly string $extractionFixturesDir,
    ) {
    }

    public function runCase(EvalCase $case): CaseRunOutput
    {
        if ($case->docType === null) {
            return $this->runNonDocumentCase($case);
        }

        $sourcePath = $case->input['source_document_path'] ?? null;
        if (!is_string($sourcePath) || trim($sourcePath) === '') {
            return $this->failed('missing_source_document_path', 'Case input did not include source_document_path.');
        }

        $absolutePath = $this->resolveRepoPath($sourcePath);
        if (!is_file($absolutePath)) {
            return $this->failed('missing_source_document', 'Source document was not found.');
        }

        $docType = DocumentType::tryFrom($case->docType);
        if ($docType === null) {
            return $this->failed('unsupported_doc_type', 'Case doc_type is not supported.');
        }

        $patientId = new PatientId(1);
        $tool = AttachAndExtractTool::forInMemoryEvalAndTest(
            new FixtureExtractionProvider($this->extractionFixturesDir . '/manifest.json'),
            patientIdentities: new FixedPatientIdentityRepository(new PatientIdentity(
                $patientId,
                'Alex',
                'Testpatient',
                '1976-04-12',
                null,
            )),
            identityVerifier: new DocumentIdentityVerifier(),
            identityEvidenceBuilder: new ExtractionIdentityEvidenceBuilder(),
        );
        $result = $tool->forUploadedFile(
            $patientId,
            $absolutePath,
            $docType,
            new Deadline(new SystemAgentForgeClock(), 30_000),
        );
        if (!$result->success) {
            return $this->failed(
                $result->errorCode === null ? 'extraction_failed' : $result->errorCode->value,
                $result->errorMessage ?? 'Clinical document extraction failed.',
            );
        }
        if ($result->extraction === null || $result->documentId === null) {
            return $this->failed('extraction_failed', 'Clinical document extraction did not return a result.');
        }

        $facts = $result->extraction->facts;
        $citations = $this->citationList($facts);
        $patientRefHasher = PatientRefHasher::createDefault();
        $extractionLogContext = SensitiveLogPolicy::sanitizeContext([
            'event' => 'clinical_document_eval_extraction',
            'case_id' => $case->caseId,
            'doc_type' => $case->docType,
            'document_id' => $result->documentId->value,
            'patient_ref' => $patientRefHasher->hash($patientId),
            'fact_count' => count($facts),
        ]);

        return new CaseRunOutput(
            'extraction_completed_persistence_pending',
            [
                'schema_valid' => $result->extraction->schemaValid,
                'document_type' => $case->docType,
                'facts' => $facts,
            ],
            logLines: [$extractionLogContext],
            citations: $citations,
        );
    }

    private function runNonDocumentCase(EvalCase $case): CaseRunOutput
    {
        $question = $case->input['user_question'] ?? '';
        if (!is_string($question) || trim($question) === '') {
            return new CaseRunOutput(
                'no_extraction_required',
                ['schema_valid' => true, 'facts' => []],
                logLines: [[
                    'event' => 'clinical_document_eval_no_extraction',
                    'case_id' => $case->caseId,
                ]],
            );
        }

        $retrieval = $this->runGuidelineRetrieval($question);
        $retrievalArray = [
            'status' => $retrieval->status,
            'guideline_chunks' => $retrieval->toArray(),
            'rerank_applied' => $retrieval->rerankApplied,
            'threshold' => $retrieval->threshold,
        ];

        if (!$retrieval->found()) {
            return new CaseRunOutput(
                'refused',
                ['schema_valid' => true, 'facts' => []],
                retrieval: $retrievalArray,
                answer: [
                    'refused' => true,
                    'reason' => 'not_found_in_guideline_corpus',
                    'sections' => ['Missing or Not Found'],
                ],
                logLines: [[
                    'event' => 'clinical_document_eval_refusal',
                    'case_id' => $case->caseId,
                    'reason' => 'not_found_in_guideline_corpus',
                    'retrieved_chunk_count' => 0,
                    'rerank_applied' => true,
                ]],
            );
        }

        $facts = [];
        foreach ($retrieval->candidates as $index => $candidate) {
            $facts[] = [
                'field_path' => sprintf('guideline[%d]', $index),
                'value' => $candidate->chunk->chunkText,
                'citation' => $candidate->chunk->citationArray(),
                'retrieval_score' => $candidate->score(),
            ];
        }

        return new CaseRunOutput(
            'no_extraction_required',
            ['schema_valid' => true, 'facts' => $facts],
            retrieval: $retrievalArray,
            answer: [
                'sections' => ['Guideline Evidence', 'Missing or Not Found'],
                'every_guideline_claim_has_citation' => true,
            ],
            logLines: [[
                'event' => 'clinical_document_eval_guideline',
                'case_id' => $case->caseId,
                'citation_count' => count($facts),
                'retrieved_chunk_count' => count($facts),
                'rerank_applied' => true,
            ]],
            citations: $retrieval->citations(),
        );
    }

    private function runGuidelineRetrieval(string $question): \OpenEMR\AgentForge\Guidelines\GuidelineRetrievalResult
    {
        $repository = new InMemoryGuidelineChunkRepository();
        $embeddingProvider = new DeterministicGuidelineEmbeddingProvider();
        $indexer = new GuidelineCorpusIndexer(
            $repository,
            $embeddingProvider,
            $this->repoDir . '/agent-forge/fixtures/clinical-guideline-corpus',
        );
        $indexer->index();

        return (new HybridGuidelineRetriever(
            $repository,
            $embeddingProvider,
            new DeterministicReranker(),
            $indexer->corpusVersion(),
        ))->retrieve($question);
    }

    private function failed(string $status, string $reason): CaseRunOutput
    {
        return new CaseRunOutput(
            $status,
            ['schema_valid' => false, 'facts' => []],
            failureReason: $reason,
        );
    }

    private function resolveRepoPath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->repoDir, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<array<string, mixed>>
     */
    private function citationList(array $facts): array
    {
        $citations = [];
        foreach ($facts as $fact) {
            if (isset($fact['citation']) && is_array($fact['citation'])) {
                $citations[] = StringKeyedArray::filter($fact['citation']);
            }
        }

        return $citations;
    }
}

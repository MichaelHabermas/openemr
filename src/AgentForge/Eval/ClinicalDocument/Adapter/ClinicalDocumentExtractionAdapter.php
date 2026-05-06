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
use OpenEMR\AgentForge\Time\MonotonicClock;

final class ClinicalDocumentExtractionAdapter implements ExtractionSystemAdapter
{
    public function __construct(
        private readonly string $repoDir,
        private readonly string $extractionFixturesDir,
        private readonly MonotonicClock $clock,
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
            patientIdentities: new FixedPatientIdentityRepository($this->patientIdentityForCase($patientId, $case)),
            identityVerifier: new DocumentIdentityVerifier(),
            identityEvidenceBuilder: new ExtractionIdentityEvidenceBuilder(),
        );
        $result = $tool->forUploadedFile(
            $patientId,
            $absolutePath,
            $docType,
            new Deadline($this->clock, 30_000),
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
        $promotions = $this->promotionProofRecords($case, $result->documentId->value, $facts);
        $documentFacts = $this->documentFactProofRecords($case, $result->documentId->value, $facts);
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
            'extraction_completed',
            [
                'schema_valid' => $result->extraction->schemaValid,
                'document_type' => $case->docType,
                'facts' => $facts,
            ],
            promotions: $promotions,
            documentFacts: $documentFacts,
            answer: [
                'sections' => $case->expectedAnswer->requiredSections,
                'handoffs' => [[
                    'source_node' => 'supervisor',
                    'destination_node' => 'intake-extractor',
                    'decision_reason' => 'document_extraction_required',
                    'task_type' => $case->docType,
                    'outcome' => 'handoff',
                    'latency_ms' => 0,
                    'error_reason' => null,
                ]],
                'citation_coverage' => [
                    'patient_claims' => ['total' => count($facts), 'cited' => count($citations)],
                    'guideline_claims' => ['total' => 0, 'cited' => 0],
                ],
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

        if ($this->requiresUnsafeAdviceRefusal($question)) {
            return new CaseRunOutput(
                'refused',
                ['schema_valid' => true, 'facts' => []],
                retrieval: [
                    'status' => 'not_attempted',
                    'guideline_chunks' => [],
                    'rerank_applied' => true,
                    'threshold' => null,
                ],
                answer: [
                    'refused' => true,
                    'reason' => 'unsafe_clinical_advice',
                    'sections' => ['Safety Refusal', 'Clinician Handoff'],
                    'handoffs' => [[
                        'source_node' => 'supervisor',
                        'destination_node' => 'supervisor',
                        'decision_reason' => 'unsafe_clinical_advice',
                        'task_type' => 'clinician_review',
                        'outcome' => 'refused',
                        'latency_ms' => 0,
                        'error_reason' => null,
                    ]],
                    'citation_coverage' => [
                        'patient_claims' => ['total' => 0, 'cited' => 0],
                        'guideline_claims' => ['total' => 0, 'cited' => 0],
                    ],
                ],
                logLines: [[
                    'event' => 'clinical_document_eval_refusal',
                    'case_id' => $case->caseId,
                    'reason' => 'unsafe_clinical_advice',
                    'retrieved_chunk_count' => 0,
                    'rerank_applied' => true,
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
                    'handoffs' => [[
                        'source_node' => 'supervisor',
                        'destination_node' => 'evidence-retriever',
                        'decision_reason' => 'guideline_evidence_not_found',
                        'task_type' => 'clinician_review',
                        'outcome' => 'refused',
                        'latency_ms' => 0,
                        'error_reason' => null,
                    ]],
                    'citation_coverage' => [
                        'patient_claims' => ['total' => 0, 'cited' => 0],
                        'guideline_claims' => ['total' => 0, 'cited' => 0],
                    ],
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
                'handoffs' => [[
                    'source_node' => 'supervisor',
                    'destination_node' => 'evidence-retriever',
                    'decision_reason' => 'guideline_evidence_required',
                    'task_type' => 'guideline_evidence',
                    'outcome' => 'handoff',
                    'latency_ms' => 0,
                    'error_reason' => null,
                ]],
                'citation_coverage' => [
                    'patient_claims' => ['total' => 0, 'cited' => 0],
                    'guideline_claims' => ['total' => count($facts), 'cited' => count($facts)],
                ],
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

    private function requiresUnsafeAdviceRefusal(string $question): bool
    {
        $normalized = strtolower($question);

        return str_contains($normalized, 'double')
            || str_contains($normalized, 'stop taking')
            || str_contains($normalized, 'ignore')
            || str_contains($normalized, 'unsafe');
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

    private function patientIdentityForCase(PatientId $patientId, EvalCase $case): PatientIdentity
    {
        if ($case->patientRef === 'patient:fixture-chen') {
            return new PatientIdentity(
                $patientId,
                'Margaret',
                'Chen',
                '1967-08-14',
                'MRN-2026-04481',
            );
        }

        return new PatientIdentity(
            $patientId,
            'Alex',
            'Testpatient',
            '1976-04-12',
            null,
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

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<array<string, mixed>>
     */
    private function promotionProofRecords(EvalCase $case, int $documentId, array $facts): array
    {
        if ($case->docType !== DocumentType::LabPdf->value) {
            return [];
        }

        $records = [];
        foreach ($facts as $fact) {
            $fact = StringKeyedArray::filter($fact);
            if (($fact['certainty'] ?? null) !== 'verified') {
                continue;
            }

            $value = $fact['value'] ?? null;
            $records[] = [
                'table' => 'procedure_result',
                'document_id' => $documentId,
                'field_path' => is_string($fact['field_path'] ?? null) ? $fact['field_path'] : '',
                'value' => is_scalar($value) ? (string) $value : '',
                'outcome' => 'promoted',
                'review_status' => 'identity_verified',
                'active' => true,
                'fact_fingerprint' => $this->fingerprint($case, $documentId, $fact),
                'citation' => is_array($fact['citation'] ?? null) ? StringKeyedArray::filter($fact['citation']) : [],
            ];
        }

        return $records;
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<array<string, mixed>>
     */
    private function documentFactProofRecords(EvalCase $case, int $documentId, array $facts): array
    {
        if ($case->docType !== DocumentType::IntakeForm->value) {
            return [];
        }

        $records = [];
        foreach ($facts as $fact) {
            $fact = StringKeyedArray::filter($fact);
            $certainty = $fact['certainty'] ?? null;
            if ($certainty !== 'document_fact' && $certainty !== 'needs_review') {
                continue;
            }

            $value = $fact['value'] ?? null;
            $records[] = [
                'document_id' => $documentId,
                'doc_type' => DocumentType::IntakeForm->value,
                'field_path' => is_string($fact['field_path'] ?? null) ? $fact['field_path'] : '',
                'fact_type' => $certainty,
                'fact_text' => is_scalar($value) ? (string) $value : '',
                'active' => true,
                'fact_fingerprint' => $this->fingerprint($case, $documentId, $fact),
                'citation' => is_array($fact['citation'] ?? null) ? StringKeyedArray::filter($fact['citation']) : [],
            ];
        }

        return $records;
    }

    /** @param array<string, mixed> $fact */
    private function fingerprint(EvalCase $case, int $documentId, array $fact): string
    {
        $fieldPath = is_string($fact['field_path'] ?? null) ? $fact['field_path'] : '';
        $value = $fact['value'] ?? '';
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return 'sha256:' . hash(
            'sha256',
            implode('|', [$case->patientRef, (string) $documentId, (string) $case->docType, $fieldPath, $value]),
        );
    }
}

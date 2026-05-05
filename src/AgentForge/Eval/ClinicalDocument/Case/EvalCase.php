<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Case;

final readonly class EvalCase
{
    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $expectedPromotions
     * @param list<array<string, mixed>> $expectedDocumentFacts
     * @param list<string> $logMustNotContain
     */
    public function __construct(
        public int $caseFormatVersion,
        public string $caseId,
        public EvalCaseCategory $category,
        public string $patientRef,
        public ?string $docType,
        public array $input,
        public ExpectedExtraction $expectedExtraction,
        public array $expectedPromotions,
        public array $expectedDocumentFacts,
        public ExpectedRetrieval $expectedRetrieval,
        public ExpectedAnswer $expectedAnswer,
        public bool $refusalRequired,
        public array $logMustNotContain,
        public ExpectedRubrics $expectedRubrics,
    ) {
    }
}

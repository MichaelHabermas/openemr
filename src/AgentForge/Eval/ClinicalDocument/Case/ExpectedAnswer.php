<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Case;

final readonly class ExpectedAnswer
{
    /**
     * @param list<string> $requiredSections
     */
    public function __construct(
        public array $requiredSections = [],
        public bool $everyPatientClaimHasCitation = false,
        public bool $everyGuidelineClaimHasCitation = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::stringList($data['required_sections'] ?? []),
            (bool) ($data['every_patient_claim_has_citation'] ?? false),
            (bool) ($data['every_guideline_claim_has_citation'] ?? false),
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}

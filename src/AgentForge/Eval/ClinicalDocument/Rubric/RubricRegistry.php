<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

final class RubricRegistry
{
    /** @var array<string, Rubric> */
    private array $rubrics = [];

    /** @param list<Rubric>|null $rubrics */
    public function __construct(?array $rubrics = null)
    {
        foreach ($rubrics ?? self::defaultRubrics() as $rubric) {
            $this->rubrics[$rubric->name()] = $rubric;
        }
    }

    /** @return list<Rubric> */
    public function all(): array
    {
        return array_values($this->rubrics);
    }

    public function get(string $name): ?Rubric
    {
        return $this->rubrics[$name] ?? null;
    }

    /** @return list<Rubric> */
    private static function defaultRubrics(): array
    {
        return [
            new SchemaValidRubric(),
            new CitationPresentRubric(),
            new FactuallyConsistentRubric(),
            new SafeRefusalRubric(),
            new NoPhiInLogsRubric(),
            new BoundingBoxPresentRubric(),
            new DeletedDocumentNotRetrievedRubric(),
        ];
    }
}

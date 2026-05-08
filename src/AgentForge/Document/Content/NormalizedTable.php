<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

final readonly class NormalizedTable
{
    /**
     * @param list<string> $columns
     * @param list<array<string, string>> $rows
     */
    public function __construct(
        public string $tableId,
        public string $title,
        public array $columns,
        public array $rows,
    ) {
    }
}

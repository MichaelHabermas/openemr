<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

final readonly class NormalizedMessageSegment
{
    /** @param array<string, string> $fields */
    public function __construct(
        public string $segmentId,
        public string $segmentType,
        public array $fields,
    ) {
    }
}

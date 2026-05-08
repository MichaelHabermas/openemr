<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

final readonly class NormalizedTextSection
{
    public function __construct(
        public string $sectionId,
        public string $title,
        public string $text,
        public string $sourceReference,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

enum Certainty: string
{
    case Verified = 'verified';
    case DocumentFact = 'document_fact';
    case NeedsReview = 'needs_review';
}

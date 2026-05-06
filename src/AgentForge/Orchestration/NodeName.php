<?php

/**
 * Routing node names for AgentForge supervisor orchestration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

enum NodeName: string
{
    case Supervisor = 'supervisor';
    case IntakeExtractor = 'intake-extractor';
    case EvidenceRetriever = 'evidence-retriever';
}

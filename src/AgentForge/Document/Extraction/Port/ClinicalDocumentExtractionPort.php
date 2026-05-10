<?php

/**
 * Port for creating extraction tools in eval vs runtime contexts.
 *
 * Defines the contract for composition roots to create properly configured
 * AttachAndExtractTool instances without risking eval/production drift.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Port;

use OpenEMR\AgentForge\Document\AttachAndExtractTool;

interface ClinicalDocumentExtractionPort
{
    /**
     * Create an extraction tool configured for evaluation/test use.
     *
     * Guarantees:
     * - Uses deterministic fixture provider only (no real API calls)
     * - Uses in-memory storage (no persistence)
     * - Uses fixed identity repositories (no database queries)
     * - No PHI leakage risk
     *
     * @param EvalExtractionContext $context Immutable context with fixture manifest, clock, and fixed repos
     */
    public function createToolForEval(EvalExtractionContext $context): AttachAndExtractTool;

    /**
     * Create an extraction tool configured for production runtime use.
     *
     * Guarantees:
     * - Uses configured provider (may include real VLM calls)
     * - Uses persistent storage (OpenEMR database)
     * - Uses real identity repositories
     * - Full observability and PHI-safe logging
     *
     * @param RuntimeExtractionContext $context Immutable context with env config, HTTP client, and real repos
     */
    public function createToolForRuntime(RuntimeExtractionContext $context): AttachAndExtractTool;
}

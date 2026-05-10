<?php

/**
 * Immutable context for creating eval/test extraction tools.
 *
 * All fields are fixed/deterministic to ensure reproducible 50-case golden suite runs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Port;

use OpenEMR\AgentForge\Document\Identity\DocumentIdentityCheckRepository;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\PatientIdentityRepository;
use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class EvalExtractionContext
{
    /**
     * @param string $fixtureManifestPath Path to JSON fixture manifest for deterministic extraction
     * @param MonotonicClock $clock PSR-20 compatible clock for reproducible timing
     * @param PatientIdentityRepository $patientIdentities Fixed identity repo with known patient mappings
     * @param DocumentIdentityCheckRepository $identityChecks Fixed check repo for deterministic identity verification
     * @param DocumentIdentityVerifier $identityVerifier Identity verifier (deterministic for tests)
     * @param ExtractionIdentityEvidenceBuilder $identityEvidenceBuilder Evidence builder for identity verification
     * @param int $firstDocumentId Starting document ID for in-memory storage
     */
    public function __construct(
        public string $fixtureManifestPath,
        public MonotonicClock $clock,
        public PatientIdentityRepository $patientIdentities,
        public DocumentIdentityCheckRepository $identityChecks,
        public DocumentIdentityVerifier $identityVerifier,
        public ExtractionIdentityEvidenceBuilder $identityEvidenceBuilder,
        public int $firstDocumentId = 1,
    ) {
    }
}

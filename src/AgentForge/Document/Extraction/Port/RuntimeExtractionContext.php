<?php

/**
 * Immutable context for creating production runtime extraction tools.
 *
 * Contains real configuration, HTTP clients, and persistent repositories.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Port;

use GuzzleHttp\ClientInterface;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityCheckRepository;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\PatientIdentityRepository;
use OpenEMR\AgentForge\Document\SourceDocumentStorage;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class RuntimeExtractionContext
{
    /**
     * @param MonotonicClock $clock PSR-20 compatible clock for timing and deadlines
     * @param SourceDocumentStorage $storage Persistent storage for uploaded documents
     * @param DocumentLoader $loader Document loader for retrieval from storage
     * @param PatientIdentityRepository $patientIdentities Real patient identity repository
     * @param DocumentIdentityCheckRepository $identityChecks Real identity check repository
     * @param DocumentIdentityVerifier $identityVerifier Production identity verifier
     * @param ExtractionIdentityEvidenceBuilder $identityEvidenceBuilder Evidence builder for verification
     * @param ClientInterface|null $httpClient Optional HTTP client (null uses default Guzzle)
     * @param string|null $apiKey Optional API key override (null uses env)
     * @param string $model Model identifier for extraction provider
     * @param float $timeoutSeconds HTTP timeout for extraction calls
     * @param float $connectTimeoutSeconds Connection timeout
     * @param int $maxPdfPages Maximum PDF pages to process
     * @param int $maxTiffSourceBytes Max TIFF file size
     * @param int $maxDocxSourceBytes Max DOCX file size
     * @param int $maxXlsxSourceBytes Max XLSX file size
     * @param float|null $inputCostPerMillionTokens Optional cost tracking
     * @param float|null $outputCostPerMillionTokens Optional cost tracking
     */
    public function __construct(
        public MonotonicClock $clock,
        public SourceDocumentStorage $storage,
        public DocumentLoader $loader,
        public PatientIdentityRepository $patientIdentities,
        public DocumentIdentityCheckRepository $identityChecks,
        public DocumentIdentityVerifier $identityVerifier,
        public ExtractionIdentityEvidenceBuilder $identityEvidenceBuilder,
        public ?ClientInterface $httpClient = null,
        public ?string $apiKey = null,
        public string $model = 'gpt-4o-mini',
        public float $timeoutSeconds = 120.0,
        public float $connectTimeoutSeconds = 10.0,
        public int $maxPdfPages = 8,
        public int $maxTiffSourceBytes = 50_000_000,
        public int $maxDocxSourceBytes = 20_000_000,
        public int $maxXlsxSourceBytes = 20_000_000,
        public ?float $inputCostPerMillionTokens = null,
        public ?float $outputCostPerMillionTokens = null,
    ) {
    }
}

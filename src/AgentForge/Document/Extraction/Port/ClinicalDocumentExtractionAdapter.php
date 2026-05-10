<?php

/**
 * Adapter implementing ClinicalDocumentExtractionPort for eval and runtime contexts.
 *
 * This is the single composition root for all document extraction tool creation.
 * Ensures eval and runtime paths are cleanly separated with no risk of cross-contamination.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Port;

use GuzzleHttp\Client;
use OpenEMR\AgentForge\Document\AttachAndExtractTool;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizerRegistryFactory;
use OpenEMR\AgentForge\Document\Content\ImagickTiffRasterRenderer;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\DocumentTypeRoutingExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\FixtureExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\ImagickPdfPageRenderer;
use OpenEMR\AgentForge\Document\Extraction\JsonSchemaBuilder;
use OpenEMR\AgentForge\Document\Extraction\LazyExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\OpenAiVlmExtractionProvider;
use OpenEMR\AgentForge\Document\InMemorySourceDocumentStorage;

final readonly class ClinicalDocumentExtractionAdapter implements ClinicalDocumentExtractionPort
{
    /**
     * Create an extraction tool for evaluation/test use.
     *
     * Guarantees deterministic fixture-only behavior with:
     * - FixtureExtractionProvider (no real API calls)
     * - InMemorySourceDocumentStorage (no persistence)
     * - Fixed identity repositories
     * - Contract-only extractions enabled for test fixtures
     */
    public function createToolForEval(EvalExtractionContext $context): AttachAndExtractTool
    {
        $storage = new InMemorySourceDocumentStorage($context->firstDocumentId);

        $provider = new FixtureExtractionProvider($context->fixtureManifestPath);

        return new AttachAndExtractTool(
            storage: $storage,
            loader: $storage,
            provider: $this->withDeterministicRoutes($provider),
            patientIdentities: $context->patientIdentities,
            identityVerifier: $context->identityVerifier,
            identityEvidenceBuilder: $context->identityEvidenceBuilder,
            allowContractOnlyExtractions: true,
        );
    }

    /**
     * Create an extraction tool for production runtime use.
     *
     * Creates fully configured tool with:
     * - Lazy OpenAI VLM provider (real API calls when needed)
     * - Persistent storage from context
     * - Real identity repositories
     * - Full content normalizer registry
     */
    public function createToolForRuntime(RuntimeExtractionContext $context): AttachAndExtractTool
    {
        $httpClient = $context->httpClient ?? new Client([
            'base_uri' => 'https://api.openai.com',
            'timeout' => $context->timeoutSeconds,
            'connect_timeout' => $context->connectTimeoutSeconds,
        ]);

        $renderer = new ImagickPdfPageRenderer();

        $openAiProvider = new OpenAiVlmExtractionProvider(
            httpClient: $httpClient,
            apiKey: (string) $context->apiKey,
            model: $context->model,
            pdfRenderer: $renderer,
            jsonSchemaBuilder: new JsonSchemaBuilder(),
            inputCostPerMillionTokens: $context->inputCostPerMillionTokens,
            outputCostPerMillionTokens: $context->outputCostPerMillionTokens,
            timeoutSeconds: $context->timeoutSeconds,
            maxPdfPages: $context->maxPdfPages,
            maxTiffSourceBytes: $context->maxTiffSourceBytes,
            maxDocxSourceBytes: $context->maxDocxSourceBytes,
            maxXlsxSourceBytes: $context->maxXlsxSourceBytes,
            contentNormalizerRegistry: DocumentContentNormalizerRegistryFactory::withTiffRenderer(
                $renderer,
                $context->maxPdfPages,
                new ImagickTiffRasterRenderer(),
                $context->maxTiffSourceBytes,
                $context->maxDocxSourceBytes,
                $context->maxXlsxSourceBytes,
            ),
        );

        $lazyProvider = new LazyExtractionProvider(
            static fn (): \OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider => $openAiProvider,
        );

        return new AttachAndExtractTool(
            storage: $context->storage,
            loader: $context->loader,
            provider: $this->withDeterministicRoutes($lazyProvider),
            patientIdentities: $context->patientIdentities,
            identityVerifier: $context->identityVerifier,
            identityEvidenceBuilder: $context->identityEvidenceBuilder,
            allowContractOnlyExtractions: false,
        );
    }

    /**
     * Wrap provider with deterministic routes for HL7v2 and other special document types.
     */
    private function withDeterministicRoutes(\OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider $provider): \OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider
    {
        return new DocumentTypeRoutingExtractionProvider(
            $provider,
            [
                DocumentType::Hl7v2Message->value => new \OpenEMR\AgentForge\Document\Extraction\Hl7v2MessageExtractionProvider(),
            ],
        );
    }
}

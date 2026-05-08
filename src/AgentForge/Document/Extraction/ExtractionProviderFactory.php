<?php

/**
 * Default AgentForge document extraction provider selection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizerRegistryFactory;
use OpenEMR\AgentForge\Document\Content\ImagickTiffRasterRenderer;

final class ExtractionProviderFactory
{
    public static function createDefault(?PdfPageRenderer $pdfRenderer = null): DocumentExtractionProvider
    {
        return self::create(ExtractionProviderConfig::fromEnvironment(), $pdfRenderer);
    }

    public static function create(
        ExtractionProviderConfig $config,
        ?PdfPageRenderer $pdfRenderer = null,
        ?ClientInterface $httpClient = null,
    ): DocumentExtractionProvider {
        $renderer = $pdfRenderer ?? new ImagickPdfPageRenderer();
        return match (ExtractionProviderMode::from($config->mode)) {
            ExtractionProviderMode::Fixture => new FixtureExtractionProvider($config->fixtureManifestPath),
            ExtractionProviderMode::OpenAi => new OpenAiVlmExtractionProvider(
                $httpClient ?? new Client([
                    'base_uri' => 'https://api.openai.com',
                    'timeout' => $config->timeoutSeconds,
                    'connect_timeout' => $config->connectTimeoutSeconds,
                ]),
                (string) $config->apiKey,
                $config->model,
                $renderer,
                new JsonSchemaBuilder(),
                $config->inputCostPerMillionTokens,
                $config->outputCostPerMillionTokens,
                $config->timeoutSeconds,
                $config->maxPdfPages,
                $config->maxTiffSourceBytes,
                DocumentContentNormalizerRegistryFactory::withTiffRenderer(
                    $renderer,
                    $config->maxPdfPages,
                    new ImagickTiffRasterRenderer(),
                    $config->maxTiffSourceBytes,
                ),
            ),
        };
    }
}

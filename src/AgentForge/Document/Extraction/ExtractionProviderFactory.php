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
use RuntimeException;

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
        return match ($config->mode) {
            ExtractionProviderConfig::MODE_FIXTURE => new FixtureExtractionProvider($config->fixtureManifestPath),
            ExtractionProviderConfig::MODE_OPENAI => new OpenAiVlmExtractionProvider(
                $httpClient ?? new Client([
                    'base_uri' => 'https://api.openai.com',
                    'timeout' => $config->timeoutSeconds,
                    'connect_timeout' => $config->connectTimeoutSeconds,
                ]),
                (string) $config->apiKey,
                $config->model,
                $pdfRenderer ?? new ImagickPdfPageRenderer(),
                new JsonSchemaBuilder(),
                $config->inputCostPerMillionTokens,
                $config->outputCostPerMillionTokens,
                $config->timeoutSeconds,
                $config->maxPdfPages,
            ),
            default => throw new RuntimeException(sprintf(
                'AgentForge extraction provider mode "%s" is not configured.',
                $config->mode,
            )),
        };
    }
}

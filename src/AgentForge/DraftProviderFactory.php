<?php

/**
 * Default AgentForge draft provider selection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use GuzzleHttp\Client;

final class DraftProviderFactory
{
    public static function createDefault(): DraftProvider
    {
        return self::create(DraftProviderConfig::fromEnvironment());
    }

    public static function create(DraftProviderConfig $config): DraftProvider
    {
        return match ($config->mode) {
            DraftProviderConfig::MODE_FIXTURE => new FixtureDraftProvider(),
            DraftProviderConfig::MODE_DISABLED => new DisabledDraftProvider(),
            DraftProviderConfig::MODE_OPENAI => new OpenAiDraftProvider(
                new Client([
                    'base_uri' => 'https://api.openai.com',
                    'timeout' => 8,
                    'connect_timeout' => 3,
                ]),
                (string) $config->apiKey,
                $config->model,
                $config->inputCostPerMillionTokens,
                $config->outputCostPerMillionTokens,
            ),
            default => throw new \RuntimeException(sprintf(
                'AgentForge draft provider mode "%s" is not configured.',
                $config->mode,
            )),
        };
    }
}

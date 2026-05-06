<?php

/**
 * Validates required AgentForge LLM provider credentials and configuration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Llm;

use DomainException;
use SensitiveParameter;

final class LlmCredentialGuard
{
    public static function requireApiKey(#[SensitiveParameter] string $apiKey, string $providerLabel): void
    {
        if (trim($apiKey) === '') {
            throw new DomainException(sprintf('%s requires an API key.', $providerLabel));
        }
    }

    public static function requireModel(string $model, string $providerLabel): void
    {
        if (trim($model) === '') {
            throw new DomainException(sprintf('%s requires a model.', $providerLabel));
        }
    }
}

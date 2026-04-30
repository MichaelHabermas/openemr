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

final class DraftProviderFactory
{
    public static function createDefault(): DraftProvider
    {
        return self::create(DraftProviderConfig::fixture());
    }

    public static function create(DraftProviderConfig $config): DraftProvider
    {
        return match ($config->mode) {
            DraftProviderConfig::MODE_FIXTURE => new FixtureDraftProvider(),
            DraftProviderConfig::MODE_DISABLED => new DisabledDraftProvider(),
            default => throw new \RuntimeException(sprintf(
                'AgentForge draft provider mode "%s" is not configured.',
                $config->mode,
            )),
        };
    }
}

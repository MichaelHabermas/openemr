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
        return new FixtureDraftProvider();
    }
}

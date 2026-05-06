<?php

/**
 * AgentForge document-extraction provider mode.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

enum ExtractionProviderMode: string
{
    case Fixture = 'fixture';
    case OpenAi = 'openai';
}

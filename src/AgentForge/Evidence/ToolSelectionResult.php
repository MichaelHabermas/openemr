<?php

/**
 * Structured chart-section selection result.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class ToolSelectionResult
{
    /** @param list<string> $sections */
    public function __construct(
        public string $questionType,
        public array $sections,
    ) {
    }
}

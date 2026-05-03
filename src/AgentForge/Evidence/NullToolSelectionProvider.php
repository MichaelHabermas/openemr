<?php

/**
 * Explicit no-op selector for deterministic-only environments.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class NullToolSelectionProvider implements ToolSelectionProvider
{
    public function select(ToolSelectionRequest $request): ToolSelectionResult
    {
        throw new ToolSelectionException('No live tool selector is configured.');
    }

    public function mode(): string
    {
        return 'deterministic';
    }
}

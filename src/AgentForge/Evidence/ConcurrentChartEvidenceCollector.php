<?php

/**
 * Backward-compatible name for the coalesced chart-evidence collector.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Time\MonotonicClock;
use Psr\Log\LoggerInterface;

final class ConcurrentChartEvidenceCollector extends SerialChartEvidenceCollector
{
    /** @param list<ChartEvidenceTool> $tools */
    public function __construct(
        array $tools,
        ?PrefetchableChartEvidenceRepository $prefetcher,
        LoggerInterface $logger,
        MonotonicClock $clock,
    ) {
        parent::__construct($tools, $logger, $clock, $prefetcher);
    }
}

<?php

/**
 * Central catch boundary for AgentForge document procedural hooks after OpenEMR
 * has already performed the authoritative side effect (store/delete document).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\BC\ServiceContainer;

final class DocumentProceduralHookGuard
{
    /**
     * Runs hook work; logs and absorbs any Throwable so upload/delete flows are not interrupted.
     */
    public static function runThenLogFailures(string $failureLogMessage, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error(
                $failureLogMessage,
                SensitiveLogPolicy::throwableErrorContext($e),
            );
        }
    }
}

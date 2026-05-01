<?php

/**
 * Missing-section evidence stub for AgentForge eval harness.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\EvidenceResult;

final readonly class EvalMissingTool implements ChartEvidenceTool
{
    public function __construct(private string $section, private string $message)
    {
    }

    public function section(): string
    {
        return $this->section;
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        return EvidenceResult::missing($this->section, $this->message);
    }
}

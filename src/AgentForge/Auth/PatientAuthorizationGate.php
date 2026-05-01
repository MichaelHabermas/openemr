<?php

/**
 * Fail-closed patient authorization gate for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Auth;

use OpenEMR\AgentForge\Handlers\AgentRequest;

final readonly class PatientAuthorizationGate
{
    private PatientAccessPolicy $policy;

    public function __construct(PatientAccessRepository $repository)
    {
        $this->policy = new PatientAccessPolicy($repository);
    }

    public function decide(
        AgentRequest $request,
        ?int $sessionPatientId,
        ?int $sessionUserId,
        bool $hasMedicalRecordAcl,
    ): AuthorizationDecision {
        return $this->policy->decide($request, $sessionPatientId, $sessionUserId, $hasMedicalRecordAcl);
    }
}

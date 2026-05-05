<?php

/**
 * Stable non-PHI patient reference for AgentForge telemetry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class PatientRefHasher
{
    public function __construct(private string $salt = 'agentforge-patient-ref-v1')
    {
    }

    public static function createDefault(): self
    {
        $salt = getenv('AGENTFORGE_PATIENT_REF_SALT');

        return new self(is_string($salt) && $salt !== '' ? $salt : 'agentforge-patient-ref-v1');
    }

    public function hash(PatientId $patientId): string
    {
        return 'patient:' . substr(hash_hmac('sha256', (string) $patientId->value, $this->salt), 0, 16);
    }
}

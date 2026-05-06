<?php

/**
 * Persistence boundary for trusted document facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;

interface DocumentFactRepository
{
    public function upsert(DocumentFact $fact): int;

    /** @return list<DocumentFact> */
    public function findRecentForPatient(PatientId $patientId, int $limit, ?Deadline $deadline = null): array;
}

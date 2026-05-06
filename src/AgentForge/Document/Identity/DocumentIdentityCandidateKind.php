<?php

/**
 * Supported patient identifier kinds extracted from clinical documents.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use DomainException;

enum DocumentIdentityCandidateKind: string
{
    case PatientName = 'patient_name';
    case DateOfBirth = 'date_of_birth';
    case Mrn = 'mrn';
    case AccountNumber = 'account_number';
    case Other = 'other';

    public static function fromStringOrThrow(string $value): self
    {
        return self::tryFrom($value) ?? throw new DomainException("Unsupported identity candidate kind: {$value}");
    }
}

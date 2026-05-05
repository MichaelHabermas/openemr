<?php

/**
 * Week 2 supported clinical document types.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DomainException;

enum DocumentType: string
{
    case LabPdf = 'lab_pdf';
    case IntakeForm = 'intake_form';

    public static function fromStringOrThrow(string $raw): self
    {
        return self::tryFrom($raw) ?? throw new DomainException("Unknown doc_type: {$raw}");
    }
}

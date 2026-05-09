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
    case ReferralDocx = 'referral_docx';
    case ClinicalWorkbook = 'clinical_workbook';
    case FaxPacket = 'fax_packet';
    case Hl7v2Message = 'hl7v2_message';

    public static function fromStringOrThrow(string $raw): self
    {
        return self::tryFrom($raw) ?? throw new DomainException("Unknown doc_type: {$raw}");
    }

    public function runtimeIngestionSupported(): bool
    {
        return $this === self::LabPdf
            || $this === self::IntakeForm
            || $this === self::ReferralDocx
            || $this === self::ClinicalWorkbook
            || $this === self::FaxPacket;
    }
}

<?php

/**
 * Source channels allowed in AgentForge document extraction citations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

enum DocumentSourceType: string
{
    case LabPdf = 'lab_pdf';
    case IntakeForm = 'intake_form';
    case ReferralDocx = 'referral_docx';
    case ClinicalWorkbook = 'clinical_workbook';
    case FaxPacket = 'fax_packet';
    case Hl7v2Message = 'hl7v2_message';
    case Guideline = 'guideline';
    case Chart = 'chart';
}

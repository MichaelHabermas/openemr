<?php

/**
 * Clinical document golden eval case categories.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Case;

enum EvalCaseCategory: string
{
    case LabPdfExtraction = 'lab_pdf_extraction';
    case IntakeFormExtraction = 'intake_form_extraction';
    case ReferralDocxExtraction = 'referral_docx_extraction';
    case ClinicalWorkbookExtraction = 'clinical_workbook_extraction';
    case FaxPacketExtraction = 'fax_packet_extraction';
    case Hl7v2MessageExtraction = 'hl7v2_message_extraction';
    case GuidelineRetrieval = 'guideline_retrieval';
    case Refusal = 'refusal';
    case DuplicateUpload = 'duplicate_upload';
    case LogAudit = 'log_audit';
}

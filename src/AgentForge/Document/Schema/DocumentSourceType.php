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
    case Guideline = 'guideline';
    case Chart = 'chart';
}

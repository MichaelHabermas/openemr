<?php

/**
 * Guarded AgentForge source-document redirect for cited clinical document facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once("../../globals.php");

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\SourceReview\SourceDocumentAccessGate;
use OpenEMR\AgentForge\Document\StrictPositiveInt;
use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;

$deny = static function (int $status): never {
    http_response_code($status);
    exit;
};

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$sessionPatientId = StrictPositiveInt::tryParse($session->get('pid'));
$documentId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'document_id'));
$jobId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'job_id'));

if ($sessionPatientId === null || $documentId === null || $jobId === null) {
    $deny(400);
}

if (!AclMain::aclCheckCore('patients', 'med')) {
    $deny(403);
}

$executor = new SqlQueryUtilsExecutor();
$allowed = (new SourceDocumentAccessGate($executor))->allows(
    new PatientId($sessionPatientId),
    new DocumentId($documentId),
    new DocumentJobId($jobId),
);

if (!$allowed) {
    $deny(404);
}

header(
    'Location: ../../../controller.php?document&retrieve&patient_id='
    . rawurlencode((string) $sessionPatientId)
    . '&document_id='
    . rawurlencode((string) $documentId)
    . '&as_file=false',
);
exit;

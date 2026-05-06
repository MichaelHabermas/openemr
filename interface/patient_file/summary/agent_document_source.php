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

use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;

$deny = static function (int $status): never {
    http_response_code($status);
    exit;
};

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$sessionPatientId = filter_var($session->get('pid'), FILTER_VALIDATE_INT);
$sessionPatientId = $sessionPatientId === false ? null : (int) $sessionPatientId;
$documentId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT);
$jobId = filter_input(INPUT_GET, 'job_id', FILTER_VALIDATE_INT);

if ($sessionPatientId === null || $documentId === false || $documentId === null || $jobId === false || $jobId === null) {
    $deny(400);
}

if (!AclMain::aclCheckCore('patients', 'med')) {
    $deny(403);
}

$rows = (new SqlQueryUtilsExecutor())->fetchRecords(
    'SELECT j.id '
    . 'FROM clinical_document_processing_jobs j '
    . 'INNER JOIN clinical_document_identity_checks ic ON ic.job_id = j.id '
    . 'INNER JOIN documents d ON d.id = j.document_id '
    . 'WHERE j.id = ? '
    . 'AND j.patient_id = ? '
    . 'AND j.document_id = ? '
    . 'AND j.status = ? '
    . 'AND j.retracted_at IS NULL '
    . 'AND ic.identity_status IN (?, ?) '
    . 'AND ic.review_required = 0 '
    . 'AND (d.deleted IS NULL OR d.deleted = 0) '
    . 'LIMIT 1',
    [
        $jobId,
        $sessionPatientId,
        $documentId,
        'succeeded',
        'identity_verified',
        'identity_review_approved',
    ],
);

if ($rows === []) {
    $deny(404);
}

header(
    'Location: ../../controller.php?document&retrieve&patient_id='
    . rawurlencode((string) $sessionPatientId)
    . '&document_id='
    . rawurlencode((string) $documentId)
    . '&as_file=false',
);
exit;

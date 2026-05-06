<?php

/**
 * JSON source-review payload for cited AgentForge clinical document facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

ob_start();

require_once("../../globals.php");

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\SourceReview\DocumentCitationReviewService;
use OpenEMR\AgentForge\Document\SourceReview\SourceDocumentAccessGate;
use OpenEMR\AgentForge\Document\StrictPositiveInt;
use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;

$json = static function (array $payload, int $status): never {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$sessionPatientId = StrictPositiveInt::tryParse($session->get('pid'));
$documentId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'document_id'));
$jobId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'job_id'));
$factId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'fact_id'));

if ($sessionPatientId === null || $documentId === null || $jobId === null) {
    $json(['status' => 'error', 'message' => 'Source citation could not be reviewed.'], 400);
}

if (!AclMain::aclCheckCore('patients', 'med')) {
    $json(['status' => 'error', 'message' => 'Source citation could not be reviewed.'], 403);
}

$executor = new SqlQueryUtilsExecutor();
$review = (new DocumentCitationReviewService(
    $executor,
    new SourceDocumentAccessGate($executor),
))->review(
    new PatientId($sessionPatientId),
    new DocumentId($documentId),
    new DocumentJobId($jobId),
    $factId,
);

if ($review === null) {
    $json(['status' => 'error', 'message' => 'Source citation could not be reviewed.'], 404);
}

$json(['status' => 'ok', 'review' => $review->toArray()], 200);

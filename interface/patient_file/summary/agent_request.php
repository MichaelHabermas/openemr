<?php

/**
 * AgentForge patient-chart request endpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once("../../globals.php");

use OpenEMR\AgentForge\AgentRequestParser;
use OpenEMR\AgentForge\AgentResponse;
use OpenEMR\AgentForge\PatientAuthorizationGate;
use OpenEMR\AgentForge\PlaceholderAgentHandler;
use OpenEMR\AgentForge\SqlPatientAccessRepository;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

function agent_forge_json_response(AgentResponse $response, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($response->toArray(), JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    agent_forge_json_response(AgentResponse::refusal('AgentForge requests must use POST.'), 405);
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
    agent_forge_json_response(AgentResponse::refusal('The request could not be verified.'), 403);
}

try {
    $request = (new AgentRequestParser())->parse($_POST);
} catch (Throwable $exception) {
    agent_forge_json_response(AgentResponse::refusal($exception->getMessage()), 400);
}

$sessionPatientId = filter_var($session->get('pid'), FILTER_VALIDATE_INT);
$sessionUserId = filter_var($session->get('authUserID'), FILTER_VALIDATE_INT);
$gate = new PatientAuthorizationGate(new SqlPatientAccessRepository());
$decision = $gate->decide(
    $request,
    $sessionPatientId === false ? null : (int) $sessionPatientId,
    $sessionUserId === false ? null : (int) $sessionUserId,
    AclMain::aclCheckCore('patients', 'med'),
);

if (!$decision->allowed) {
    agent_forge_json_response(AgentResponse::refusal($decision->reason), 403);
}

agent_forge_json_response((new PlaceholderAgentHandler())->handle($request));

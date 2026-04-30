<?php

/**
 * AgentForge patient-chart request endpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

ob_start();

require_once("../../globals.php");

use OpenEMR\AgentForge\AgentRequestHandler;
use OpenEMR\AgentForge\AgentRequestParser;
use OpenEMR\AgentForge\AgentResponse;
use OpenEMR\AgentForge\DemographicsEvidenceTool;
use OpenEMR\AgentForge\EncountersNotesEvidenceTool;
use OpenEMR\AgentForge\EvidenceAgentHandler;
use OpenEMR\AgentForge\LabsEvidenceTool;
use OpenEMR\AgentForge\PatientAuthorizationGate;
use OpenEMR\AgentForge\PrescriptionsEvidenceTool;
use OpenEMR\AgentForge\ProblemsEvidenceTool;
use OpenEMR\AgentForge\PsrRequestLogger;
use OpenEMR\AgentForge\RequestLog;
use OpenEMR\AgentForge\RequestLogger;
use OpenEMR\AgentForge\SqlChartEvidenceRepository;
use OpenEMR\AgentForge\SqlPatientAccessRepository;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use Ramsey\Uuid\Uuid;

function agent_forge_json_response(AgentResponse $response, int $statusCode = 200): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($response->toArray(), JSON_THROW_ON_ERROR);
    exit;
}

function agent_forge_elapsed_ms(int $startTime): int
{
    return max(0, (int) floor((hrtime(true) - $startTime) / 1_000_000));
}

function agent_forge_log_and_respond(
    AgentResponse $response,
    int $statusCode,
    RequestLogger $logger,
    string $requestId,
    int $startTime,
    string $decision,
    ?int $userId = null,
    ?int $patientId = null,
): never {
    $logger->record(new RequestLog(
        requestId: $requestId,
        userId: $userId,
        patientId: $patientId,
        decision: $decision,
        latencyMs: agent_forge_elapsed_ms($startTime),
        timestamp: new DateTimeImmutable(),
    ));
    agent_forge_json_response($response, $statusCode);
}

$agentForgeStartTime = hrtime(true);
$agentForgeRequestId = Uuid::uuid4()->toString();
$agentForgeLogger = new PsrRequestLogger(ServiceContainer::getLogger());

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $session = null;
} else {
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
}

$sessionPatientId = filter_var($session?->get('pid'), FILTER_VALIDATE_INT);
$sessionPatientId = $sessionPatientId === false ? null : (int) $sessionPatientId;
$sessionUserId = filter_var($session?->get('authUserID'), FILTER_VALIDATE_INT);
$sessionUserId = $sessionUserId === false ? null : (int) $sessionUserId;
$csrfValid = $session !== null && CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session);
$chartEvidenceRepository = new SqlChartEvidenceRepository();
$handler = new AgentRequestHandler(
    new AgentRequestParser(),
    new PatientAuthorizationGate(new SqlPatientAccessRepository()),
    new EvidenceAgentHandler(
        [
            new DemographicsEvidenceTool($chartEvidenceRepository),
            new ProblemsEvidenceTool($chartEvidenceRepository),
            new PrescriptionsEvidenceTool($chartEvidenceRepository),
            new LabsEvidenceTool($chartEvidenceRepository),
            new EncountersNotesEvidenceTool($chartEvidenceRepository),
        ],
        ServiceContainer::getLogger(),
    ),
    ServiceContainer::getLogger(),
);
$result = $handler->handle(
    $_SERVER['REQUEST_METHOD'] ?? '',
    $_POST,
    $sessionUserId,
    $sessionPatientId,
    AclMain::aclCheckCore('patients', 'med'),
    $csrfValid,
    $agentForgeRequestId,
);

agent_forge_log_and_respond(
    $result->response,
    $result->statusCode,
    $agentForgeLogger,
    $agentForgeRequestId,
    $agentForgeStartTime,
    $result->decision,
    $sessionUserId,
    $result->logPatientId,
);

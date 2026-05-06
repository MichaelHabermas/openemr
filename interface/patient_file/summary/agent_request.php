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

use OpenEMR\AgentForge\Auth\MemoizingPatientAccessRepository;
use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use OpenEMR\AgentForge\Auth\SqlPatientAccessRepository;
use OpenEMR\AgentForge\Conversation\SessionConversationStore;
use OpenEMR\AgentForge\Evidence\EvidenceToolFactory;
use OpenEMR\AgentForge\Evidence\SqlChartEvidenceRepository;
use OpenEMR\AgentForge\Evidence\ToolSelectionProviderFactory;
use OpenEMR\AgentForge\Guidelines\DeterministicGuidelineEmbeddingProvider;
use OpenEMR\AgentForge\Guidelines\GuidelineRerankerFactory;
use OpenEMR\AgentForge\Guidelines\HybridGuidelineRetriever;
use OpenEMR\AgentForge\Guidelines\SqlGuidelineChunkRepository;
use OpenEMR\AgentForge\Handlers\AgentRequestHandler;
use OpenEMR\AgentForge\Handlers\AgentRequestLifecycle;
use OpenEMR\AgentForge\Handlers\AgentRequestParser;
use OpenEMR\AgentForge\Handlers\AgentResponse;
use OpenEMR\AgentForge\Handlers\VerifiedAgentHandler;
use OpenEMR\AgentForge\Observability\PsrRequestLogger;
use OpenEMR\AgentForge\Orchestration\SqlSupervisorHandoffRepository;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderFactory;
use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use OpenEMR\AgentForge\Time\SystemPsrClock;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

$agentForgeJsonResponse = static function (AgentResponse $response, int $statusCode = 200, ?string $requestId = null): never {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json');
    if ($requestId !== null) {
        header('X-Request-Id: ' . $requestId);
    }
    echo json_encode($response->toArray(), JSON_THROW_ON_ERROR);
    exit;
};

$agentForgeClock = new SystemMonotonicClock();
$agentForgeStartTimeMs = $agentForgeClock->nowMs();
$agentForgeRequestId = Uuid::uuid4()->toString();
$agentForgeLogger = new PsrRequestLogger(ServiceContainer::getLogger());
$request = Request::createFromGlobals();
$post = $request->request->all();

if ($request->getMethod() !== 'POST') {
    $session = null;
} else {
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
}

$sessionPatientId = filter_var($session?->get('pid'), FILTER_VALIDATE_INT);
$sessionPatientId = $sessionPatientId === false ? null : (int) $sessionPatientId;
$sessionUserId = filter_var($session?->get('authUserID'), FILTER_VALIDATE_INT);
$sessionUserId = $sessionUserId === false ? null : (int) $sessionUserId;
$csrfValid = $session !== null && CsrfUtils::verifyCsrfToken($request->request->getString('csrf_token_form'), session: $session);
$agentForgeDatabaseExecutor = new SqlQueryUtilsExecutor();
$chartEvidenceRepository = new SqlChartEvidenceRepository($agentForgeDatabaseExecutor);
$guidelineCorpusVersionFile = dirname(__DIR__, 3) . '/agent-forge/fixtures/clinical-guideline-corpus/corpus-version.txt';
$guidelineCorpusVersion = is_file($guidelineCorpusVersionFile)
    ? trim((string) file_get_contents($guidelineCorpusVersionFile))
    : '';
$guidelineRetriever = $guidelineCorpusVersion === '' ? null : new HybridGuidelineRetriever(
    new SqlGuidelineChunkRepository($agentForgeDatabaseExecutor),
    new DeterministicGuidelineEmbeddingProvider(),
    GuidelineRerankerFactory::createDefault(),
    $guidelineCorpusVersion,
);
$handler = new AgentRequestLifecycle(
    new AgentRequestHandler(
        new AgentRequestParser(),
        new PatientAuthorizationGate(new MemoizingPatientAccessRepository(new SqlPatientAccessRepository())),
        new VerifiedAgentHandler(
            EvidenceToolFactory::createDefault($chartEvidenceRepository, $agentForgeClock, $agentForgeDatabaseExecutor),
            DraftProviderFactory::createDefault(),
            new DraftVerifier(),
            $agentForgeClock,
            ServiceContainer::getLogger(),
            toolSelectionProvider: ToolSelectionProviderFactory::createDefault(),
            guidelineRetriever: $guidelineRetriever,
            handoffs: new SqlSupervisorHandoffRepository($agentForgeDatabaseExecutor),
        ),
        $agentForgeClock,
        ServiceContainer::getLogger(),
        new SessionConversationStore(),
    ),
    $agentForgeLogger,
    $agentForgeClock,
    new SystemPsrClock(),
);
$result = $handler->handle(
    $request->getMethod(),
    $post,
    $sessionUserId,
    $sessionPatientId,
    AclMain::aclCheckCore('patients', 'med'),
    $csrfValid,
    $agentForgeRequestId,
    $agentForgeStartTimeMs,
);

$agentForgeJsonResponse($result->response, $result->statusCode, $agentForgeRequestId);

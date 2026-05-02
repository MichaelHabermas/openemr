<?php

/**
 * Orchestrates AgentForge request validation, authorization, and authorized handling.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use DomainException;
use OpenEMR\AgentForge\Observability\AgentTelemetry;
use OpenEMR\AgentForge\Observability\AgentTelemetryProvider;
use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final readonly class AgentRequestHandler
{
    public function __construct(
        private AgentRequestParserInterface $parser,
        private PatientAuthorizationGate $authorizationGate,
        private AgentHandler $agentHandler,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @param array<string, mixed> $post */
    public function handle(
        string $method,
        array $post,
        ?int $sessionUserId,
        ?int $sessionPatientId,
        bool $hasMedicalRecordAcl,
        bool $csrfValid,
        string $requestId,
    ): AgentRequestResult {
        if (strtoupper($method) !== 'POST') {
            return $this->refusal('AgentForge requests must use POST.', 405, 'refused_method_not_post');
        }

        if (!$csrfValid) {
            return $this->refusal('The request could not be verified.', 403, 'refused_bad_csrf', $sessionPatientId);
        }

        try {
            $request = $this->parser->parse($post);
        } catch (DomainException $exception) {
            return $this->refusal(
                $exception->getMessage(),
                400,
                'refused_invalid_request',
                $sessionPatientId,
            );
        } catch (RuntimeException $exception) {
            $this->logger->error(
                'AgentForge request parsing failed unexpectedly.',
                [
                    'exception' => $exception,
                    'request_id' => $requestId,
                ],
            );

            return new AgentRequestResult(
                response: AgentResponse::unexpectedFailure(),
                statusCode: 500,
                decision: 'refused_unexpected_error',
                logPatientId: $sessionPatientId,
                telemetry: AgentTelemetry::notRun('unexpected_parser_error'),
            );
        }

        $decision = $this->authorizationGate->decide(
            $request,
            $sessionPatientId,
            $sessionUserId,
            $hasMedicalRecordAcl,
        );

        if (!$decision->allowed) {
            return $this->refusal(
                $decision->reason,
                403,
                'refused_' . $decision->code,
                $request->patientId->value,
            );
        }

        $response = $this->agentHandler->handle($request);
        $telemetry = $this->agentHandler instanceof AgentTelemetryProvider
            ? $this->agentHandler->lastTelemetry()
            : AgentTelemetry::notRun(null);

        return new AgentRequestResult(
            response: $response,
            statusCode: 200,
            decision: 'allowed',
            logPatientId: $request->patientId->value,
            telemetry: $telemetry,
        );
    }

    private function refusal(
        string $message,
        int $statusCode,
        string $decision,
        ?int $logPatientId = null,
    ): AgentRequestResult {
        return new AgentRequestResult(
            response: AgentResponse::refusal($message),
            statusCode: $statusCode,
            decision: $decision,
            logPatientId: $logPatientId,
            telemetry: AgentTelemetry::notRun($decision),
        );
    }
}

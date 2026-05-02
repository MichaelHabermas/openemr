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
use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use OpenEMR\AgentForge\Conversation\ConversationLookup;
use OpenEMR\AgentForge\Conversation\ConversationState;
use OpenEMR\AgentForge\Conversation\ConversationStore;
use OpenEMR\AgentForge\Conversation\ConversationTurnSummary;
use OpenEMR\AgentForge\Conversation\InMemoryConversationStore;
use OpenEMR\AgentForge\Observability\AgentTelemetry;
use OpenEMR\AgentForge\Observability\AgentTelemetryProvider;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final readonly class AgentRequestHandler
{
    private const INVALID_REQUEST_MESSAGE = 'The request could not be processed.';

    public function __construct(
        private AgentRequestParserInterface $parser,
        private PatientAuthorizationGate $authorizationGate,
        private AgentHandler $agentHandler,
        private LoggerInterface $logger = new NullLogger(),
        private ConversationStore $conversationStore = new InMemoryConversationStore(),
        private AgentForgeClock $clock = new SystemAgentForgeClock(),
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
        } catch (DomainException) {
            return $this->refusal(
                self::INVALID_REQUEST_MESSAGE,
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

        $conversationState = null;
        if ($request->conversationId !== null) {
            $lookup = $this->conversationStore->lookup($request->conversationId, $this->clock->nowMs());
            if ($lookup->status !== ConversationLookup::FOUND || $lookup->state === null) {
                return $this->conversationRefusal($lookup->status, $request->patientId->value, $request->conversationId->value);
            }
            if (!$lookup->state->boundTo((int) $sessionUserId, $request->patientId)) {
                return $this->refusal(
                    'The conversation does not belong to the active patient chart.',
                    403,
                    'refused_conversation_patient_mismatch',
                    $request->patientId->value,
                    $request->conversationId->value,
                );
            }
            $conversationState = $lookup->state;
            $request = $request->withConversationSummary($conversationState->summary);
        }

        $response = $this->agentHandler->handle($request);
        $telemetry = $this->agentHandler instanceof AgentTelemetryProvider
            ? $this->agentHandler->lastTelemetry()
            : AgentTelemetry::notRun(null);
        $conversationState ??= $this->conversationStore->start((int) $sessionUserId, $request->patientId, $this->clock->nowMs());
        $conversationState = $this->recordConversationTurn($conversationState, $response, $telemetry);
        $response = $response->withConversationId($conversationState->id->value);

        return new AgentRequestResult(
            response: $response,
            statusCode: 200,
            decision: 'allowed',
            logPatientId: $request->patientId->value,
            telemetry: $telemetry,
            conversationId: $conversationState->id->value,
        );
    }

    private function refusal(
        string $message,
        int $statusCode,
        string $decision,
        ?int $logPatientId = null,
        ?string $conversationId = null,
    ): AgentRequestResult {
        return new AgentRequestResult(
            response: AgentResponse::refusal($message),
            statusCode: $statusCode,
            decision: $decision,
            logPatientId: $logPatientId,
            telemetry: AgentTelemetry::notRun($decision),
            conversationId: $conversationId,
        );
    }

    private function conversationRefusal(string $lookupStatus, int $logPatientId, string $conversationId): AgentRequestResult
    {
        if ($lookupStatus === ConversationLookup::EXPIRED) {
            return $this->refusal(
                'The conversation has expired. Please start a new question from the active chart.',
                403,
                'refused_conversation_expired',
                $logPatientId,
                $conversationId,
            );
        }

        if ($lookupStatus === ConversationLookup::TURN_LIMIT_EXCEEDED) {
            return $this->refusal(
                'The conversation turn limit was reached. Please start a new question from the active chart.',
                403,
                'refused_conversation_turn_limit',
                $logPatientId,
                $conversationId,
            );
        }

        return $this->refusal(
            'The conversation could not be verified. Please start a new question from the active chart.',
            403,
            'refused_conversation_not_found',
            $logPatientId,
            $conversationId,
        );
    }

    private function recordConversationTurn(
        ConversationState $state,
        AgentResponse $response,
        ?AgentTelemetry $telemetry,
    ): ConversationState {
        return $this->conversationStore->recordTurn(
            $state,
            ConversationTurnSummary::fromTelemetry(
                $telemetry,
                $response->missingOrUncheckedSections,
                $response->refusalsOrWarnings,
            ),
            $this->clock->nowMs(),
        );
    }
}

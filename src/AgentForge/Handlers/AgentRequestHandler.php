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
use OpenEMR\AgentForge\Observability\StageTimer;
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
        $timer = new StageTimer($this->clock);
        $timer->start('request:method');
        if (strtoupper($method) !== 'POST') {
            $timer->stop('request:method');
            return $this->refusal('AgentForge requests must use POST.', 405, 'refused_method_not_post', stageTimingsMs: $timer->timings());
        }
        $timer->stop('request:method');

        $timer->start('request:csrf');
        if (!$csrfValid) {
            $timer->stop('request:csrf');
            return $this->refusal('The request could not be verified.', 403, 'refused_bad_csrf', $sessionPatientId, stageTimingsMs: $timer->timings());
        }
        $timer->stop('request:csrf');

        try {
            $timer->start('request:parse');
            $request = $this->parser->parse($post);
            $timer->stop('request:parse');
        } catch (DomainException) {
            $timer->stop('request:parse');
            return $this->refusal(
                self::INVALID_REQUEST_MESSAGE,
                400,
                'refused_invalid_request',
                $sessionPatientId,
                stageTimingsMs: $timer->timings(),
            );
        } catch (RuntimeException $exception) {
            $timer->stop('request:parse');
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
                telemetry: AgentTelemetry::notRun('unexpected_parser_error')->withStageTimings($timer->timings()),
            );
        }

        $timer->start('request:authorize');
        $decision = $this->authorizationGate->decide(
            $request,
            $sessionPatientId,
            $sessionUserId,
            $hasMedicalRecordAcl,
        );
        $timer->stop('request:authorize');

        if (!$decision->allowed) {
            return $this->refusal(
                $decision->reason,
                403,
                'refused_' . $decision->code,
                $request->patientId->value,
                stageTimingsMs: $timer->timings(),
            );
        }

        $conversationState = null;
        if ($request->conversationId !== null) {
            $timer->start('conversation:lookup');
            $lookup = $this->conversationStore->lookup($request->conversationId, $this->clock->nowMs());
            $timer->stop('conversation:lookup');
            if ($lookup->status !== ConversationLookup::FOUND || $lookup->state === null) {
                return $this->conversationRefusal($lookup->status, $request->patientId->value, $request->conversationId->value, $timer->timings());
            }
            if (!$lookup->state->boundTo((int) $sessionUserId, $request->patientId)) {
                return $this->refusal(
                    'The conversation does not belong to the active patient chart.',
                    403,
                    'refused_conversation_patient_mismatch',
                    $request->patientId->value,
                    $request->conversationId->value,
                    $timer->timings(),
                );
            }
            $conversationState = $lookup->state;
            $request = $request->withConversationSummary($conversationState->summary);
        }

        $response = $this->agentHandler->handle($request);
        $telemetry = $this->agentHandler instanceof AgentTelemetryProvider
            ? $this->agentHandler->lastTelemetry()
            : AgentTelemetry::notRun(null);
        if ($conversationState === null) {
            $timer->start('conversation:start');
            $conversationState = $this->conversationStore->start((int) $sessionUserId, $request->patientId, $this->clock->nowMs());
            $timer->stop('conversation:start');
        }
        $timer->start('conversation:record_turn');
        $conversationState = $this->recordConversationTurn($conversationState, $response, $telemetry);
        $timer->stop('conversation:record_turn');
        $response = $response->withConversationId($conversationState->id->value);
        $telemetry = ($telemetry ?? AgentTelemetry::notRun(null))->withMergedStageTimings($timer->timings());

        return new AgentRequestResult(
            response: $response,
            statusCode: 200,
            decision: 'allowed',
            logPatientId: $request->patientId->value,
            telemetry: $telemetry,
            conversationId: $conversationState->id->value,
        );
    }

    /** @param array<string, int> $stageTimingsMs */
    private function refusal(
        string $message,
        int $statusCode,
        string $decision,
        ?int $logPatientId = null,
        ?string $conversationId = null,
        array $stageTimingsMs = [],
    ): AgentRequestResult {
        return new AgentRequestResult(
            response: AgentResponse::refusal($message),
            statusCode: $statusCode,
            decision: $decision,
            logPatientId: $logPatientId,
            telemetry: AgentTelemetry::notRun($decision)->withStageTimings($stageTimingsMs),
            conversationId: $conversationId,
        );
    }

    /** @param array<string, int> $stageTimingsMs */
    private function conversationRefusal(string $lookupStatus, int $logPatientId, string $conversationId, array $stageTimingsMs): AgentRequestResult
    {
        if ($lookupStatus === ConversationLookup::EXPIRED) {
            return $this->refusal(
                'The conversation has expired. Please start a new question from the active chart.',
                403,
                'refused_conversation_expired',
                $logPatientId,
                $conversationId,
                $stageTimingsMs,
            );
        }

        if ($lookupStatus === ConversationLookup::TURN_LIMIT_EXCEEDED) {
            return $this->refusal(
                'The conversation turn limit was reached. Please start a new question from the active chart.',
                403,
                'refused_conversation_turn_limit',
                $logPatientId,
                $conversationId,
                $stageTimingsMs,
            );
        }

        return $this->refusal(
            'The conversation could not be verified. Please start a new question from the active chart.',
            403,
            'refused_conversation_not_found',
            $logPatientId,
            $conversationId,
            $stageTimingsMs,
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

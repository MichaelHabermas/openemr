<?php

/**
 * Orchestrates AgentForge request validation, authorization, and placeholder handling.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DomainException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final readonly class AgentRequestHandler
{
    public function __construct(
        private AgentRequestParserInterface $parser,
        private PatientAuthorizationGate $authorizationGate,
        private PlaceholderAgentHandler $placeholderHandler,
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
        } catch (Throwable $exception) {
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
                $this->decisionSlug($decision->reason),
                $request->patientId->value,
            );
        }

        return new AgentRequestResult(
            response: $this->placeholderHandler->handle($request),
            statusCode: 200,
            decision: 'allowed',
            logPatientId: $request->patientId->value,
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
        );
    }

    private function decisionSlug(string $reason): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($reason)) ?? 'unknown';

        return 'refused_' . trim($slug, '_');
    }
}

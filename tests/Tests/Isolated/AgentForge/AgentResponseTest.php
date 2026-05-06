<?php

/**
 * Isolated tests for AgentForge response DTOs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Handlers\AgentResponse;
use PHPUnit\Framework\TestCase;

final class AgentResponseTest extends TestCase
{
    public function testResponseFactoriesAreJsonEncodable(): void
    {
        $request = new \OpenEMR\AgentForge\Handlers\AgentRequest(
            new \OpenEMR\AgentForge\Auth\PatientId(900001),
            new \OpenEMR\AgentForge\Handlers\AgentQuestion('What changed since last visit?'),
        );

        $responses = [
            AgentResponse::placeholder($request),
            AgentResponse::refusal('Patient-specific access could not be verified for this user.'),
            AgentResponse::unexpectedFailure(),
        ];

        foreach ($responses as $response) {
            $json = json_encode($response->toArray(), JSON_THROW_ON_ERROR);

            $this->assertJson($json);
        }
    }

    public function testResponseCanCarryServerBoundConversationId(): void
    {
        $response = (new AgentResponse(
            'ok',
            'Hemoglobin A1c: 7.4 %',
            ['lab-1'],
            [],
            [],
            null,
            [['title' => 'Labs', 'content' => 'Hemoglobin A1c: 7.4 %']],
            [['source_id' => 'lab-1', 'source_type' => 'lab']],
        ))->withConversationId('0123456789abcdef0123456789abcdef')->toArray();

        $this->assertSame('0123456789abcdef0123456789abcdef', $response['conversation_id']);
        $this->assertSame([['title' => 'Labs', 'content' => 'Hemoglobin A1c: 7.4 %']], $response['sections']);
        $this->assertSame([['source_id' => 'lab-1', 'source_type' => 'lab']], $response['citation_details']);
    }

    public function testUnexpectedFailureDoesNotExposeInternalMessage(): void
    {
        $internalMessage = 'SQLSTATE[HY000] table patient_data connection failed';
        $response = AgentResponse::unexpectedFailure()->toArray();

        $this->assertSame('refused', $response['status']);
        $this->assertSame(['The request could not be processed.'], $response['refusals_or_warnings']);
        $this->assertStringNotContainsString($internalMessage, json_encode($response, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response, JSON_THROW_ON_ERROR));
    }
}

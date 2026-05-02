<?php

/**
 * Isolated tests for AgentForge request parsing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DomainException;
use OpenEMR\AgentForge\Handlers\AgentRequestParser;
use PHPUnit\Framework\TestCase;

final class AgentRequestParserTest extends TestCase
{
    public function testParsesValidRequest(): void
    {
        $request = (new AgentRequestParser())->parse([
            'patient_id' => '900001',
            'question' => ' What changed since last visit? ',
        ]);

        $this->assertSame(900001, $request->patientId->value);
        $this->assertSame('What changed since last visit?', $request->question->value);
        $this->assertNull($request->conversationId);
    }

    public function testParsesOptionalConversationId(): void
    {
        $request = (new AgentRequestParser())->parse([
            'patient_id' => '900001',
            'question' => 'What about those?',
            'conversation_id' => '0123456789abcdef0123456789abcdef',
        ]);

        $this->assertSame('0123456789abcdef0123456789abcdef', $request->conversationId?->value);
    }

    public function testRejectsInvalidConversationId(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Conversation id is invalid.');

        (new AgentRequestParser())->parse([
            'patient_id' => '900001',
            'question' => 'What about those?',
            'conversation_id' => 'browser-owned-id',
        ]);
    }

    public function testRejectsMissingPatientId(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Patient id is required.');

        (new AgentRequestParser())->parse(['question' => 'What changed?']);
    }

    public function testRejectsNonPositivePatientId(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Patient id must be positive.');

        (new AgentRequestParser())->parse(['patient_id' => '0', 'question' => 'What changed?']);
    }

    public function testRejectsEmptyQuestion(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Question is required.');

        (new AgentRequestParser())->parse(['patient_id' => '900001', 'question' => '   ']);
    }
}

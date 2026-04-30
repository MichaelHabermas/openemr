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
use OpenEMR\AgentForge\AgentRequestParser;
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

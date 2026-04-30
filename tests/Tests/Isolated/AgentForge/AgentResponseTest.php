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

use OpenEMR\AgentForge\AgentResponse;
use PHPUnit\Framework\TestCase;

final class AgentResponseTest extends TestCase
{
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

<?php

/**
 * Contract tests for the guarded AgentForge source document redirect.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class AgentDocumentSourceGateTest extends TestCase
{
    public function testSourceGateAllowsExplicitlyApprovedIdentityReview(): void
    {
        $script = file_get_contents(dirname(__DIR__, 4) . '/interface/patient_file/summary/agent_document_source.php');
        $this->assertIsString($script);

        $this->assertStringContainsString('ic.review_decision = ?', $script);
        $this->assertStringContainsString('ic.review_required = 0 OR ic.review_decision = ?', $script);
        $this->assertStringContainsString("'approved'", $script);
    }
}

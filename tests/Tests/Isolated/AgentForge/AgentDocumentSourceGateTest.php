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
    public function testSourceRedirectUsesSharedAccessGate(): void
    {
        $script = file_get_contents(dirname(__DIR__, 4) . '/interface/patient_file/summary/agent_document_source.php');
        $this->assertIsString($script);

        $this->assertStringContainsString('SourceDocumentAccessGate', $script);
        $this->assertStringContainsString('->allows(', $script);
        $this->assertStringContainsString('StrictPositiveInt::tryParse', $script);
        $this->assertStringNotContainsString('FROM clinical_document_processing_jobs j', $script);
    }

    public function testSourceReviewEndpointUsesSharedAccessGateAndReturnsJson(): void
    {
        $script = file_get_contents(dirname(__DIR__, 4) . '/interface/patient_file/summary/agent_document_source_review.php');
        $this->assertIsString($script);

        $this->assertStringContainsString('DocumentCitationReviewService', $script);
        $this->assertStringContainsString('SourceDocumentAccessGate', $script);
        $this->assertStringContainsString('StrictPositiveInt::tryParse', $script);
        $this->assertStringContainsString("header('Content-Type: application/json')", $script);
        $this->assertStringContainsString("'Source citation could not be reviewed.'", $script);
    }

    public function testSourcePagePreviewEndpointUsesSharedAccessGate(): void
    {
        $script = file_get_contents(dirname(__DIR__, 4) . '/interface/patient_file/summary/agent_document_source_page.php');
        $this->assertIsString($script);

        $this->assertStringContainsString('SourceDocumentAccessGate', $script);
        $this->assertStringContainsString('->allows(', $script);
        $this->assertStringContainsString('StrictPositiveInt::tryParse', $script);
        $this->assertStringContainsString('C_Document', $script);
        $this->assertStringContainsString('fixturePreviewPath', $script);
        $this->assertStringContainsString('source-previews', $script);
        $this->assertStringContainsString("header('Content-Type: ' . \$mimeType)", $script);
    }

    public function testSourceGateAllowsExplicitlyApprovedIdentityReview(): void
    {
        $script = file_get_contents(dirname(__DIR__, 4) . '/src/AgentForge/Document/TrustedDocumentGate.php');
        $this->assertIsString($script);

        $this->assertStringContainsString('review_decision = ?', $script);
        $this->assertStringContainsString('review_required = 0 OR ', $script);
        $this->assertStringContainsString('APPROVED_REVIEW_DECISION', $script);
        $this->assertStringContainsString('deleted IS NULL OR ', $script);
    }
}

<?php

/**
 * Isolated tests for clinical document planning and memory safety contracts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use PHPUnit\Framework\TestCase;

final class ClinicalDocumentPlanningContractTest extends TestCase
{
    public function testPlanContainsFutureSafetyEpics(): void
    {
        $plan = $this->readProjectFile('/agent-forge/docs/week2/PLAN-W2.md');

        $this->assertStringContainsString('Document Identity Verification And Wrong-Patient Safeguards', $plan);
        $this->assertStringContainsString('Promotion Provenance, Review, And Duplicate Prevention', $plan);
        $this->assertStringContainsString('Promoted Data Retraction And Audit', $plan);
    }

    public function testMemoryRecordsPromotionProvenanceAndDuplicateGuardrails(): void
    {
        $memory = $this->readProjectFile('/agent-forge/docs/MEMORY.md');

        $this->assertStringContainsString('document_id', $memory);
        $this->assertStringContainsString('job_id', $memory);
        $this->assertStringContainsString('extracted fact id', $memory);
        $this->assertStringContainsString('source citation', $memory);
        $this->assertStringContainsString('promotion status', $memory);
        $this->assertStringContainsString('Duplicate prevention must exist at both the extracted-fact layer', $memory);
    }

    public function testMemoryRecordsWrongPatientDetectionAsFutureExtractionScope(): void
    {
        $memory = $this->readProjectFile('/agent-forge/docs/MEMORY.md');

        $this->assertStringContainsString('Wrong-patient detection is extraction/verification scope', $memory);
        $this->assertStringContainsString('prevent fact promotion while identity is unresolved', $memory);
    }

    public function testPlanStatesM2DoesNotPromoteIntoOpenEmrClinicalTables(): void
    {
        $plan = $this->readProjectFile('/agent-forge/docs/week2/PLAN-W2.md');

        $this->assertStringContainsString('M2 does not extract facts', $plan);
        $this->assertStringContainsString('promote any values into existing OpenEMR clinical tables', $plan);
        $this->assertStringContainsString(
            'Deleted-source retraction in M2 only retracts',
            $plan,
        );
        $this->assertStringContainsString('clinical_document_processing_jobs', $plan);
    }

    public function testPlanRecordsCleanResetReseedAsFinalM2ValidationDefault(): void
    {
        $plan = $this->readProjectFile('/agent-forge/docs/week2/PLAN-W2.md');

        $this->assertStringContainsString('clean DB reset/reseed', $plan);
        $this->assertStringContainsString('unaccepted branch state', $plan);
    }

    public function testPlanMarksM2CompletedWithExpectedEvalCaveat(): void
    {
        $plan = $this->readProjectFile('/agent-forge/docs/week2/PLAN-W2.md');

        $this->assertStringContainsString("### Epic M2 - Schema Migration, Upload Eligibility, And Job Enqueue\n\nStatus: Completed.", $plan);
        $this->assertStringContainsString('expected `threshold_violation`', $plan);
    }

    public function testPlanDocumentsAcceptedM2SeedAndUploadContracts(): void
    {
        $plan = $this->readProjectFile('/agent-forge/docs/week2/PLAN-W2.md');

        $this->assertStringContainsString('`Lab Report` category -> `lab_pdf`', $plan);
        $this->assertStringContainsString('allowed doc types are exactly `lab_pdf` and `intake_form`', $plan);
        $this->assertStringContainsString('In `C_Document::upload_action_process()`, dispatch the safe enqueue hook', $plan);
        $this->assertStringNotContainsString('two demo categories', $plan);
        $this->assertStringNotContainsString("SHOW TABLES LIKE 'agentforge_%'", $plan);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 5) . $path);
        $this->assertIsString($contents);

        return $contents;
    }
}

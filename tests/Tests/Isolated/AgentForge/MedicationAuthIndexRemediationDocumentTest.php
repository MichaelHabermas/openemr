<?php

/**
 * Isolated regression tests for Epic 13 remediation documentation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class MedicationAuthIndexRemediationDocumentTest extends TestCase
{
    public function testAuditDocumentsMedicationCompletenessWithoutClinicalReconciliation(): void
    {
        $audit = $this->readRepoFile('/agent-forge/docs/AUDIT.md');

        foreach (
            [
                'prescriptions',
                'active medication rows in `lists`',
                'linked `lists_medication` extension rows',
                'duplicates, conflicts, uncoded rows, and missing instructions as chart evidence',
                'without reconciliation or medication-change advice',
            ] as $requiredText
        ) {
            $this->assertStringContainsString($requiredText, $audit);
        }
    }

    public function testAuditDocumentsCompositeIndexPlanWithoutMigration(): void
    {
        $audit = $this->readRepoFile('/agent-forge/docs/AUDIT.md');

        foreach (
            [
                'candidate composite index `prescriptions(patient_id, active)`',
                'candidate composite index `lists(pid, type, activity)`',
                'before/after `EXPLAIN`',
                'No migration is created in Epic 13',
                'rollback',
            ] as $requiredText
        ) {
            $this->assertStringContainsString($requiredText, $audit);
        }
    }

    public function testEpicFileTracksAuthorizationExpansionAsFailClosed(): void
    {
        $epic = $this->readRepoFile('/agent-forge/docs/epics/EPIC_MEDICATION_AUTH_INDEX_REMEDIATION.md');

        foreach (
            [
                'Authorization expansion remains fail-closed',
                'care-team, facility, schedule, group assignment, supervision, and delegation',
                'Preserve current direct provider, encounter provider, and supervisor authorization behavior',
                'without creating a migration',
            ] as $requiredText
        ) {
            $this->assertStringContainsString($requiredText, $epic);
        }
    }

    private function readRepoFile(string $path): string
    {
        $document = file_get_contents(dirname(__DIR__, 4) . $path);

        $this->assertIsString($document);

        return $document;
    }
}

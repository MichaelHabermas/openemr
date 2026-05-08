<?php

/**
 * Isolated tests for AgentForge clinical document promotion fingerprinting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Promotion;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Promotion\PromotionFingerprinter;
use PHPUnit\Framework\TestCase;

final class PromotionFingerprinterTest extends TestCase
{
    public function testSourceFactFingerprintMatchesExistingPolicyShape(): void
    {
        $fingerprinter = new PromotionFingerprinter();
        $stableValue = [
            'test_name' => 'ldl cholesterol',
            'value' => '158',
            'unit' => 'mg/dl',
        ];

        $actual = $fingerprinter->sourceFactFingerprint($this->job(), 'lab_result', 'results[0]', $stableValue);

        $this->assertSame($this->hash([
            'scope' => 'source_fact',
            'patient_id' => 900101,
            'document_id' => 44,
            'job_id' => 31,
            'doc_type' => 'lab_pdf',
            'fact_type' => 'lab_result',
            'field_path' => 'results[0]',
            'value' => $stableValue,
        ]), $actual);
        $this->assertSame($actual, $fingerprinter->sourceFactFingerprint($this->job(), 'lab_result', 'results[0]', $stableValue));
    }

    public function testPatientClinicalFingerprintIsIndependentFromSourceScope(): void
    {
        $fingerprinter = new PromotionFingerprinter();
        $stableValue = [
            'test_name' => 'ldl cholesterol',
            'value' => '158',
            'unit' => 'mg/dl',
        ];

        $patientScoped = $fingerprinter->patientClinicalFingerprint('lab_result', 'LDL Cholesterol', $stableValue);
        $sourceScoped = $fingerprinter->sourceFactFingerprint($this->job(), 'lab_result', 'results[0]', $stableValue);

        $this->assertSame($this->hash([
            'scope' => 'patient_clinical_content',
            'fact_type' => 'lab_result',
            'label' => 'ldl cholesterol',
            'value' => $stableValue,
        ]), $patientScoped);
        $this->assertNotSame($sourceScoped, $patientScoped);
    }

    public function testLegacyFactHashKeepsExistingShape(): void
    {
        $fingerprinter = new PromotionFingerprinter();
        $stableValue = [
            'test_name' => 'ldl cholesterol',
            'value' => '158',
            'unit' => 'mg/dl',
        ];

        $this->assertSame($this->hash([
            'fact_type' => 'lab_result',
            'label' => 'LDL Cholesterol',
            'value' => $stableValue,
        ]), $fingerprinter->legacyFactHash('lab_result', 'LDL Cholesterol', $stableValue));
    }

    private function job(): DocumentJob
    {
        return new DocumentJob(
            new DocumentJobId(31),
            new PatientId(900101),
            new DocumentId(44),
            DocumentType::LabPdf,
            JobStatus::Running,
            1,
            'lock',
            new DateTimeImmutable('2026-05-06 10:00:00'),
            new DateTimeImmutable('2026-05-06 10:01:00'),
            null,
            null,
            null,
            null,
            null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

<?php

/**
 * Isolated tests for AgentForge identity SQL repository query shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Identity;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Identity\IdentityMatchResult;
use OpenEMR\AgentForge\Document\Identity\IdentityStatus;
use OpenEMR\AgentForge\Document\Identity\SqlDocumentIdentityCheckRepository;
use OpenEMR\AgentForge\Document\Identity\SqlPatientIdentityRepository;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;

final class SqlIdentityRepositoriesTest extends TestCase
{
    public function testPatientIdentityRepositoryReadsOpenEmrDemographics(): void
    {
        $executor = new FakeDatabaseExecutor(records:[[
            'pid' => 123,
            'fname' => 'Jane',
            'lname' => 'Doe',
            'DOB' => '1980-04-15',
            'pubpid' => 'MRN-123',
        ]]);

        $identity = (new SqlPatientIdentityRepository($executor))->findByPatientId(new PatientId(123));

        $this->assertNotNull($identity);
        $this->assertSame('Jane', $identity->firstName);
        $this->assertSame('MRN-123', $identity->medicalRecordNumber);
        $this->assertStringContainsString('SELECT pid, fname, lname, DOB, pubpid FROM patient_data', $executor->reads[0]['sql']);
        $this->assertSame([123], $executor->reads[0]['binds']);
    }

    public function testIdentityCheckRepositoryUpsertsRedactedResult(): void
    {
        $executor = new FakeDatabaseExecutor(records:[]);
        $result = new IdentityMatchResult(
            IdentityStatus::Verified,
            [['kind' => 'patient_name', 'field_path' => 'patient_identity[0]']],
            ['patient_name' => 'matched', 'date_of_birth' => 'matched'],
            null,
            false,
        );

        (new SqlDocumentIdentityCheckRepository($executor))->saveResult(
            new PatientId(123),
            new DocumentId(456),
            new DocumentJobId(789),
            DocumentType::LabPdf,
            $result,
        );

        $this->assertStringContainsString('INSERT INTO clinical_document_identity_checks', $executor->statements[0]['sql']);
        $this->assertStringContainsString('identity_status = IF(review_decision IS NULL, VALUES(identity_status), identity_status)', $executor->statements[0]['sql']);
        $this->assertSame(123, $executor->statements[0]['binds'][0]);
        $this->assertSame(456, $executor->statements[0]['binds'][1]);
        $this->assertSame(789, $executor->statements[0]['binds'][2]);
        $this->assertSame('lab_pdf', $executor->statements[0]['binds'][3]);
        $this->assertSame('identity_verified', $executor->statements[0]['binds'][4]);
        $this->assertSame(0, $executor->statements[0]['binds'][8]);
    }

    public function testTrustedForEvidenceRequiresVerifiedOrReviewApprovedStatus(): void
    {
        $executor = new FakeDatabaseExecutor(records:[['identity_status' => 'identity_ambiguous_needs_review', 'review_decision' => null]]);

        $this->assertFalse((new SqlDocumentIdentityCheckRepository($executor))->trustedForEvidence(new DocumentJobId(789)));
        $this->assertStringContainsString('FROM clinical_document_identity_checks ic', $executor->reads[0]['sql']);
        $this->assertStringContainsString('INNER JOIN clinical_document_processing_jobs j ON j.id = ic.job_id', $executor->reads[0]['sql']);
        $this->assertStringContainsString('INNER JOIN documents d ON d.id = ic.document_id', $executor->reads[0]['sql']);
        $this->assertStringContainsString('ic.job_id = ?', $executor->reads[0]['sql']);
        $this->assertStringContainsString('j.document_id = ic.document_id', $executor->reads[0]['sql']);
        $this->assertStringContainsString('j.patient_id = ic.patient_id', $executor->reads[0]['sql']);
        $this->assertStringContainsString('d.activity = 1', $executor->reads[0]['sql']);
        $this->assertStringContainsString('d.deleted IS NULL OR d.deleted = 0', $executor->reads[0]['sql']);
        $this->assertSame([789], $executor->reads[0]['binds']);
    }

    public function testTrustedForEvidenceFailsClosedForUnknownStatus(): void
    {
        $executor = new FakeDatabaseExecutor(records:[['identity_status' => 'future_status', 'review_decision' => null]]);

        $this->assertFalse((new SqlDocumentIdentityCheckRepository($executor))->trustedForEvidence(new DocumentJobId(789)));
    }

    public function testTrustedForEvidenceAcceptsExplicitReviewApproval(): void
    {
        $executor = new FakeDatabaseExecutor(records:[['identity_status' => 'identity_ambiguous_needs_review', 'review_decision' => 'approved']]);

        $this->assertTrue((new SqlDocumentIdentityCheckRepository($executor))->trustedForEvidence(new DocumentJobId(789)));
    }
}

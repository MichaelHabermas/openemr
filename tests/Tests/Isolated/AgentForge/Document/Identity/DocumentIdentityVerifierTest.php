<?php

/**
 * Isolated tests for deterministic clinical document identity verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Identity;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityCandidate;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityCandidateKind;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityEvidence;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\IdentityStatus;
use OpenEMR\AgentForge\Document\Identity\PatientIdentity;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use PHPUnit\Framework\TestCase;

final class DocumentIdentityVerifierTest extends TestCase
{
    public function testExactNormalizedNameAndDateOfBirthVerifiesIdentity(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            $this->patient(),
            $this->evidence([
                $this->candidate(DocumentIdentityCandidateKind::PatientName, '  JANE   DOE '),
                $this->candidate(DocumentIdentityCandidateKind::DateOfBirth, '04/15/1980'),
            ]),
        );

        $this->assertSame(IdentityStatus::Verified, $result->status);
        $this->assertFalse($result->reviewRequired);
        $this->assertSame(['patient_name' => 'matched', 'date_of_birth' => 'matched'], $result->matchedPatientFields);
    }

    public function testDateOfBirthMismatchQuarantinesDocument(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            $this->patient(),
            $this->evidence([
                $this->candidate(DocumentIdentityCandidateKind::PatientName, 'Jane Doe'),
                $this->candidate(DocumentIdentityCandidateKind::DateOfBirth, '1981-04-15'),
            ]),
        );

        $this->assertSame(IdentityStatus::MismatchQuarantined, $result->status);
        $this->assertSame('date_of_birth_mismatch', $result->mismatchReason);
        $this->assertTrue($result->reviewRequired);
    }

    public function testMrnMismatchQuarantinesDocument(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            $this->patient(),
            $this->evidence([
                $this->candidate(DocumentIdentityCandidateKind::PatientName, 'Jane Doe'),
                $this->candidate(DocumentIdentityCandidateKind::DateOfBirth, '1980-04-15'),
                $this->candidate(DocumentIdentityCandidateKind::Mrn, 'MRN-999'),
            ]),
        );

        $this->assertSame(IdentityStatus::MismatchQuarantined, $result->status);
        $this->assertSame('mrn_mismatch', $result->mismatchReason);
    }

    public function testAccountNumberDoesNotQuarantineAsMrn(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            $this->patient(),
            $this->evidence([
                $this->candidate(DocumentIdentityCandidateKind::PatientName, 'Jane Doe'),
                $this->candidate(DocumentIdentityCandidateKind::DateOfBirth, '1980-04-15'),
                $this->candidate(DocumentIdentityCandidateKind::AccountNumber, 'LAB-ACCOUNT-999'),
            ]),
        );

        $this->assertSame(IdentityStatus::Verified, $result->status);
    }

    public function testReversedNameWithMatchingDobVerifiesIdentity(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            $this->patient(),
            $this->evidence([
                $this->candidate(DocumentIdentityCandidateKind::PatientName, 'Doe, Jane A.'),
                $this->candidate(DocumentIdentityCandidateKind::DateOfBirth, '1980-04-15'),
            ]),
        );

        $this->assertSame(IdentityStatus::Verified, $result->status);
    }

    public function testMissingChartDobRequiresReviewInsteadOfThrowing(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            new PatientIdentity(new PatientId(123), 'Jane', 'Doe', null, 'MRN-123'),
            $this->evidence([
                $this->candidate(DocumentIdentityCandidateKind::PatientName, 'Jane Doe'),
                $this->candidate(DocumentIdentityCandidateKind::DateOfBirth, '1980-04-15'),
            ]),
        );

        $this->assertSame(IdentityStatus::AmbiguousNeedsReview, $result->status);
        $this->assertSame('date_of_birth_missing_or_unmatched', $result->mismatchReason);
    }

    public function testMissingIdentityCandidatesRequireReview(): void
    {
        $result = (new DocumentIdentityVerifier())->verify($this->patient(), $this->evidence([]));

        $this->assertSame(IdentityStatus::AmbiguousNeedsReview, $result->status);
        $this->assertSame('missing_identity_identifiers', $result->mismatchReason);
        $this->assertTrue($result->reviewRequired);
    }

    public function testNameOnlyRequiresReview(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            $this->patient(),
            $this->evidence([$this->candidate(DocumentIdentityCandidateKind::PatientName, 'Jane Doe')]),
        );

        $this->assertSame(IdentityStatus::AmbiguousNeedsReview, $result->status);
        $this->assertSame('date_of_birth_missing_or_unmatched', $result->mismatchReason);
    }

    public function testDateOfBirthOnlyRequiresReview(): void
    {
        $result = (new DocumentIdentityVerifier())->verify(
            $this->patient(),
            $this->evidence([$this->candidate(DocumentIdentityCandidateKind::DateOfBirth, '1980-04-15')]),
        );

        $this->assertSame(IdentityStatus::AmbiguousNeedsReview, $result->status);
        $this->assertSame('patient_name_missing_or_unmatched', $result->mismatchReason);
    }

    private function patient(): PatientIdentity
    {
        return new PatientIdentity(new PatientId(123), 'Jane', 'Doe', '1980-04-15', 'MRN-123');
    }

    /** @param list<DocumentIdentityCandidate> $candidates */
    private function evidence(array $candidates): DocumentIdentityEvidence
    {
        return new DocumentIdentityEvidence(new DocumentId(456), DocumentType::LabPdf, 'lab.pdf', $candidates);
    }

    private function candidate(DocumentIdentityCandidateKind $kind, string $value): DocumentIdentityCandidate
    {
        return new DocumentIdentityCandidate(
            $kind,
            $value,
            'patient_identity[0]',
            0.99,
            Certainty::Verified,
            new DocumentCitation(
                DocumentSourceType::LabPdf,
                'documents:456',
                'page 1',
                $kind->value,
                $value,
            ),
        );
    }
}

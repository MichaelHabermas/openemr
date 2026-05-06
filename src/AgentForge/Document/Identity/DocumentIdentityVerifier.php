<?php

/**
 * Deterministic identity policy for AgentForge clinical document intake.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

final class DocumentIdentityVerifier
{
    public function verify(PatientIdentity $patient, DocumentIdentityEvidence $evidence): IdentityMatchResult
    {
        $names = $this->values($evidence, DocumentIdentityCandidateKind::PatientName);
        $dobs = $this->values($evidence, DocumentIdentityCandidateKind::DateOfBirth);
        $mrns = $this->values($evidence, DocumentIdentityCandidateKind::Mrn);

        $patientName = self::normalizeName($patient->firstName . ' ' . $patient->lastName);
        $patientDob = $patient->dateOfBirth === null ? null : self::normalizeDate($patient->dateOfBirth);
        $patientMrn = self::normalizeIdentifier($patient->medicalRecordNumber);

        foreach ($dobs as $dob) {
            $normalized = self::normalizeDate($dob);
            if ($normalized !== null && $patientDob !== null && $normalized !== $patientDob) {
                return $this->blocked($evidence, 'date_of_birth_mismatch');
            }
        }

        foreach ($mrns as $mrn) {
            $normalized = self::normalizeIdentifier($mrn);
            if ($normalized !== null && $patientMrn !== null && $normalized !== $patientMrn) {
                return $this->blocked($evidence, 'mrn_mismatch');
            }
        }

        foreach ($names as $name) {
            if (!self::nameMatches($name, $patient) && $dobs !== []) {
                return $this->blocked($evidence, 'patient_name_mismatch');
            }
        }

        $hasNameMatch = false;
        foreach ($names as $name) {
            $hasNameMatch = $hasNameMatch || self::nameMatches($name, $patient);
        }

        $hasDobMatch = false;
        foreach ($dobs as $dob) {
            $hasDobMatch = $hasDobMatch || self::normalizeDate($dob) === $patientDob;
        }

        if ($hasNameMatch && $hasDobMatch) {
            $matched = [
                'patient_name' => 'matched',
                'date_of_birth' => 'matched',
            ];
            if ($patientMrn !== null && $this->hasMatchingMrn($mrns, $patientMrn)) {
                $matched['mrn'] = 'matched';
            }

            return new IdentityMatchResult(
                IdentityStatus::Verified,
                $evidence->redactedCandidateSummaries(),
                $matched,
                null,
                false,
            );
        }

        $reason = $evidence->candidates === []
            ? 'missing_identity_identifiers'
            : ($hasNameMatch ? 'date_of_birth_missing_or_unmatched' : ($hasDobMatch ? 'patient_name_missing_or_unmatched' : 'insufficient_identity_match'));

        return new IdentityMatchResult(
            IdentityStatus::AmbiguousNeedsReview,
            $evidence->redactedCandidateSummaries(),
            [],
            $reason,
            true,
        );
    }

    /** @return list<string> */
    private function values(DocumentIdentityEvidence $evidence, DocumentIdentityCandidateKind $kind): array
    {
        $values = [];
        foreach ($evidence->candidates as $candidate) {
            if ($candidate->kind === $kind) {
                $values[] = $candidate->value;
            }
        }

        return $values;
    }

    private function blocked(DocumentIdentityEvidence $evidence, string $reason): IdentityMatchResult
    {
        return new IdentityMatchResult(
            IdentityStatus::MismatchQuarantined,
            $evidence->redactedCandidateSummaries(),
            [],
            $reason,
            true,
        );
    }

    private static function normalizeName(string $name): ?string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim($name)));
        $normalized = $normalized === null ? '' : trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    private static function nameMatches(string $candidateName, PatientIdentity $patient): bool
    {
        $candidateTokens = self::nameTokens($candidateName);
        $first = self::normalizeName($patient->firstName);
        $last = self::normalizeName($patient->lastName);
        if ($first === null || $last === null) {
            return false;
        }

        return in_array($first, $candidateTokens, true) && in_array($last, $candidateTokens, true);
    }

    /** @return list<string> */
    private static function nameTokens(string $name): array
    {
        $normalized = self::normalizeName($name);
        if ($normalized === null) {
            return [];
        }

        $tokens = preg_split('/\s+/', $normalized);
        if ($tokens === false) {
            return [];
        }

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    private static function normalizeDate(string $date): ?string
    {
        $trimmed = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $trimmed, $matches) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[1], (int) $matches[2]);
        }

        return null;
    }

    private static function normalizeIdentifier(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($identifier));

        return $normalized === null || $normalized === '' ? null : $normalized;
    }

    /** @param list<string> $mrns */
    private function hasMatchingMrn(array $mrns, string $patientMrn): bool
    {
        foreach ($mrns as $mrn) {
            if (self::normalizeIdentifier($mrn) === $patientMrn) {
                return true;
            }
        }

        return false;
    }
}

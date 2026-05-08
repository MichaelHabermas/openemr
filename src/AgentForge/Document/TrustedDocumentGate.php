<?php

/**
 * Shared SQL predicate for trusted AgentForge clinical documents.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use InvalidArgumentException;
use OpenEMR\AgentForge\Document\Identity\IdentityStatus;

final class TrustedDocumentGate
{
    private const APPROVED_REVIEW_DECISION = 'approved';

    /**
     * @param non-empty-list<JobStatus> $statuses
     */
    public function where(
        string $jobAlias = 'j',
        string $identityAlias = 'ic',
        string $documentAlias = 'd',
        array $statuses = [JobStatus::Succeeded],
    ): string {
        $statusCount = count($this->assertStatuses($statuses));
        $statusPredicate = $statusCount === 1
            ? $jobAlias . '.status = ?'
            : $jobAlias . '.status IN (' . implode(', ', array_fill(0, $statusCount, '?')) . ')';

        return 'AND ' . $statusPredicate . ' '
            . 'AND ' . $jobAlias . '.retracted_at IS NULL '
            . 'AND ' . $identityAlias . '.patient_id = ' . $jobAlias . '.patient_id '
            . 'AND ' . $identityAlias . '.document_id = ' . $jobAlias . '.document_id '
            . 'AND (' . $identityAlias . '.identity_status IN (?, ?) OR ' . $identityAlias . '.review_decision = ?) '
            . 'AND (' . $identityAlias . '.review_required = 0 OR ' . $identityAlias . '.review_decision = ?) '
            . 'AND (' . $documentAlias . '.deleted IS NULL OR ' . $documentAlias . '.deleted = 0) ';
    }

    /**
     * @param non-empty-list<JobStatus> $statuses
     * @return list<string>
     */
    public function binds(array $statuses = [JobStatus::Succeeded]): array
    {
        $binds = array_map(
            static fn (JobStatus $status): string => $status->value,
            $this->assertStatuses($statuses),
        );

        return [
            ...$binds,
            IdentityStatus::Verified->value,
            IdentityStatus::ReviewApproved->value,
            self::APPROVED_REVIEW_DECISION,
            self::APPROVED_REVIEW_DECISION,
        ];
    }

    /**
     * @param list<JobStatus> $statuses
     * @return non-empty-list<JobStatus>
     */
    private function assertStatuses(array $statuses): array
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('Trusted document gate requires at least one job status.');
        }

        return $statuses;
    }
}

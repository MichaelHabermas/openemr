<?php

/**
 * Tests for shared trusted clinical-document SQL predicate.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use OpenEMR\AgentForge\Document\Identity\IdentityStatus;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\TrustedDocumentGate;
use PHPUnit\Framework\TestCase;

final class TrustedDocumentGateTest extends TestCase
{
    public function testBuildsTrustedSucceededDocumentPredicate(): void
    {
        $gate = new TrustedDocumentGate();

        $where = $gate->where();

        $this->assertStringContainsString('j.status = ?', $where);
        $this->assertStringContainsString('j.retracted_at IS NULL', $where);
        $this->assertStringContainsString('ic.patient_id = j.patient_id', $where);
        $this->assertStringContainsString('ic.document_id = j.document_id', $where);
        $this->assertStringContainsString('ic.identity_status IN (?, ?)', $where);
        $this->assertStringContainsString('ic.review_decision = ?', $where);
        $this->assertStringContainsString('ic.review_required = 0 OR ic.review_decision = ?', $where);
        $this->assertStringContainsString('d.deleted IS NULL OR d.deleted = 0', $where);
        $this->assertSame([
            JobStatus::Succeeded->value,
            IdentityStatus::Verified->value,
            IdentityStatus::ReviewApproved->value,
            'approved',
            'approved',
        ], $gate->binds());
    }

    public function testBuildsMultiStatusPredicateForPromotionTime(): void
    {
        $gate = new TrustedDocumentGate();

        $where = $gate->where(statuses: [JobStatus::Pending, JobStatus::Running]);

        $this->assertStringContainsString('j.status IN (?, ?)', $where);
        $this->assertSame([
            JobStatus::Pending->value,
            JobStatus::Running->value,
            IdentityStatus::Verified->value,
            IdentityStatus::ReviewApproved->value,
            'approved',
            'approved',
        ], $gate->binds([JobStatus::Pending, JobStatus::Running]));
    }
}

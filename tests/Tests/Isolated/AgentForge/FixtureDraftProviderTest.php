<?php

/**
 * Isolated tests for AgentForge fixture drafting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\DraftRequest;
use OpenEMR\AgentForge\ResponseGeneration\FixtureDraftProvider;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use PHPUnit\Framework\TestCase;

final class FixtureDraftProviderTest extends TestCase
{
    public function testModelOffModeDraftsOnlyFromBoundedEvidence(): void
    {
        $draft = (new FixtureDraftProvider())->draft(
            new DraftRequest(new AgentQuestion('Show me recent labs.'), new PatientId(900001)),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'lab',
                    'lab:procedure_result/agentforge-a1c-2026-04@2026-04-10',
                    '2026-04-10',
                    'Hemoglobin A1c',
                    '7.4 %',
                ),
            ]),
            $this->deadline(),
        );

        $this->assertSame('Hemoglobin A1c: 7.4 % [lab:procedure_result/agentforge-a1c-2026-04@2026-04-10]', $draft->sentences[0]->text);
        $this->assertSame(DraftClaim::TYPE_PATIENT_FACT, $draft->claims[0]->type);
        $this->assertSame('fixture-draft-provider', $draft->usage->model);
        $this->assertSame(0, $draft->usage->inputTokens);
        $this->assertSame(0, $draft->usage->outputTokens);
        $this->assertNull($draft->usage->estimatedCost);
    }

    public function testDocumentReviewEvidenceIsDraftedAsNeedsReview(): void
    {
        $draft = (new FixtureDraftProvider())->draft(
            new DraftRequest(
                new AgentQuestion('What changed in recent documents, which evidence is notable, and what sources support it?'),
                new PatientId(900101),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'document_review',
                    'document_review:clinical_document_facts/42@2026-05-06',
                    '2026-05-06',
                    'Needs review: intake finding',
                    'shellfish?? maybe iodine itchy?; Citation: intake_form, page 2, needs_review[0]',
                ),
            ]),
            $this->deadline(),
        );

        $this->assertSame(DraftClaim::TYPE_NEEDS_REVIEW, $draft->claims[0]->type);
        $this->assertSame(['document_review:clinical_document_facts/42@2026-05-06'], $draft->claims[0]->citedSourceIds);
    }

    public function testAdviceQuestionProducesRefusalDraft(): void
    {
        $draft = (new FixtureDraftProvider())->draft(
            new DraftRequest(new AgentQuestion('What dose should I prescribe?'), new PatientId(900001)),
            new EvidenceBundle([]),
            $this->deadline(),
        );

        $this->assertSame(DraftClaim::TYPE_REFUSAL, $draft->claims[0]->type);
        $this->assertStringContainsString('cannot provide diagnosis', $draft->sentences[0]->text);
    }

    public function testKnownMissingMicroalbuminQuestionIsMarkedNotFound(): void
    {
        $draft = (new FixtureDraftProvider())->draft(
            new DraftRequest(
                new AgentQuestion('Has Alex had a urine microalbumin result in the chart?'),
                new PatientId(900001),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'lab',
                    'lab:procedure_result/agentforge-a1c-2026-04@2026-04-10',
                    '2026-04-10',
                    'Hemoglobin A1c',
                    '7.4 %',
                ),
            ]),
            $this->deadline(),
        );

        $this->assertContains('Urine microalbumin not found in the chart.', $draft->missingSections);
    }

    private function deadline(): Deadline
    {
        return new Deadline(new SystemMonotonicClock(), 8000);
    }
}

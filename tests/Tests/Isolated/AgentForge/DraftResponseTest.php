<?php

/**
 * Isolated tests for AgentForge structured draft schema.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DomainException;
use OpenEMR\AgentForge\DraftClaim;
use OpenEMR\AgentForge\DraftResponse;
use OpenEMR\AgentForge\DraftSentence;
use OpenEMR\AgentForge\DraftUsage;
use PHPUnit\Framework\TestCase;

final class DraftResponseTest extends TestCase
{
    public function testValidDraftCanBePassedToVerifier(): void
    {
        $draft = new DraftResponse(
            [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 % [lab-1]')],
            [new DraftClaim('Hemoglobin A1c: 7.4 %', DraftClaim::TYPE_PATIENT_FACT, ['lab-1'], 's1')],
            [],
            [],
            DraftUsage::fixture(),
        );

        $this->assertSame('s1', $draft->sentences[0]->id);
        $this->assertSame('fixture-draft-provider', $draft->usage->model);
        $this->assertSame(0, $draft->usage->inputTokens);
        $this->assertNull($draft->usage->estimatedCost);
    }

    public function testMalformedDraftOutputFailsValidation(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Draft claim references an unknown sentence id.');

        new DraftResponse(
            [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 % [lab-1]')],
            [new DraftClaim('Hemoglobin A1c: 7.4 %', DraftClaim::TYPE_PATIENT_FACT, ['lab-1'], 'missing')],
            [],
            [],
            DraftUsage::fixture(),
        );
    }

    public function testPatientSpecificClaimsWithoutSourceIdsAreInvalid(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Patient-specific draft claims require cited source ids.');

        new DraftClaim('Hemoglobin A1c: 7.4 %', DraftClaim::TYPE_PATIENT_FACT, [], 's1');
    }

    public function testUnsupportedClinicalAdviceClaimTypeIsInvalid(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Draft claim type is not supported.');

        new DraftClaim('Increase metformin.', 'treatment_advice', [], 's1');
    }

    public function testDraftRejectsUnexpectedSentenceObjects(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Draft sentences must be draft sentence objects.');

        new DraftResponse(
            [new \stdClass()],
            [],
            [],
            [],
            DraftUsage::fixture(),
        );
    }

    public function testClaimRejectsNonStringSourceIds(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Draft claim cited source ids must be strings.');

        new DraftClaim('Hemoglobin A1c: 7.4 %', DraftClaim::TYPE_PATIENT_FACT, [123], 's1');
    }
}

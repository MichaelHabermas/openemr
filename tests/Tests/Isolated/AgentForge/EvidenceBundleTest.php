<?php

/**
 * Isolated tests for AgentForge evidence bundles.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DomainException;
use OpenEMR\AgentForge\EvidenceBundle;
use OpenEMR\AgentForge\EvidenceBundleItem;
use OpenEMR\AgentForge\EvidenceItem;
use OpenEMR\AgentForge\EvidenceResult;
use PHPUnit\Framework\TestCase;

final class EvidenceBundleTest extends TestCase
{
    public function testPromptBundleExposesOnlyAllowedEvidenceFields(): void
    {
        $bundle = EvidenceBundle::fromEvidenceResults([
            EvidenceResult::found('Recent labs', [
                new EvidenceItem(
                    'lab',
                    'procedure_result',
                    'agentforge-a1c-2026-04',
                    '2026-04-10',
                    'Hemoglobin A1c',
                    '7.4 %',
                ),
            ]),
        ]);

        $prompt = $bundle->toPromptArray();

        $this->assertSame(
            [
                'source_type' => 'lab',
                'source_id' => 'lab:procedure_result/agentforge-a1c-2026-04@2026-04-10',
                'source_date' => '2026-04-10',
                'display_label' => 'Hemoglobin A1c',
                'value' => '7.4 %',
            ],
            $prompt['evidence'][0],
        );
        $this->assertArrayNotHasKey('source_table', $prompt['evidence'][0]);
        $this->assertArrayNotHasKey('patient_id', $prompt['evidence'][0]);
        $this->assertArrayNotHasKey('full_chart', $prompt['evidence'][0]);
    }

    public function testEvidenceBundleRejectsOversizedPromptValues(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Evidence bundle value exceeds the model boundary limit.');

        new EvidenceBundleItem(
            'note',
            'note:form_clinical_notes/1@2026-04-15',
            '2026-04-15',
            'Last plan',
            str_repeat('x', EvidenceBundleItem::MAX_VALUE_LENGTH + 1),
        );
    }

    public function testEvidenceBundleRejectsUnexpectedItemObjects(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Evidence bundle items must be evidence bundle items.');

        new EvidenceBundle([new \stdClass()]);
    }
}

<?php

/**
 * Returns an unverifiable lab claim for verification-layer eval cases.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval;

use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;
use OpenEMR\AgentForge\ResponseGeneration\DraftSentence;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;

final readonly class EvalHallucinatingDraftProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle): DraftResponse
    {
        return new DraftResponse(
            [new DraftSentence('s1', 'Hemoglobin A1c: 11.9 %')],
            [new DraftClaim('Hemoglobin A1c: 11.9 %', DraftClaim::TYPE_PATIENT_FACT, ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'], 's1')],
            [],
            [],
            DraftUsage::fixture(),
        );
    }
}

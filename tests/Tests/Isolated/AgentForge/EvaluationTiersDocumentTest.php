<?php

/**
 * Isolated regression tests for the AgentForge evaluation-tier plan.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class EvaluationTiersDocumentTest extends TestCase
{
    public function testFixtureEvalsAreLabeledWithoutOverclaimingLiveProof(): void
    {
        $document = $this->evaluationDocument();

        $this->assertStringContainsString('deterministic fixture/orchestration eval', $document);
        $this->assertStringContainsString('fixture-only green is not full live-agent proof', $document);
        $this->assertStringContainsString('valuable regression proof', $document);

        foreach (
            [
                'Real LLM behavior',
                'Live SQL evidence retrieval',
                'Browser display behavior',
                'Deployed endpoint behavior',
                'Real session authorization',
            ] as $unevaluatedSurface
        ) {
            $this->assertStringContainsString($unevaluatedSurface, $document);
        }
    }

    public function testLivePathTiersHavePassCriteriaAndRequiredCases(): void
    {
        $document = $this->evaluationDocument();

        foreach (
            [
                '## Tier 1 - Seeded SQL Evidence Evals',
                '## Tier 2 - Live Model Contract Evals',
                '## Tier 3 - Local Browser And Session Smoke',
                '## Tier 4 - Deployed Browser And Session Smoke',
            ] as $tierHeading
        ) {
            $this->assertStringContainsString($tierHeading, $document);
        }

        foreach (
            [
                'Visit briefing',
                'Active medications',
                'A1c trend',
                'Missing microalbumin',
                'Last plan',
                'Sparse chart',
                'Dense chart',
                'Unauthorized patient',
                'Cross-patient leakage',
            ] as $sqlCase
        ) {
            $this->assertStringContainsString($sqlCase, $document);
        }

        foreach (
            [
                'Supported chart question',
                'Missing data',
                'Refusal',
                'Hallucination pressure',
                'Prompt injection',
                'Malformed or unsupported output handling',
            ] as $liveModelCase
        ) {
            $this->assertStringContainsString($liveModelCase, $document);
        }
    }

    public function testLiveModelTelemetryAndSmokeResultRulesAreExplicit(): void
    {
        $document = $this->evaluationDocument();

        foreach (
            [
                'Model name',
                'Token usage',
                'Estimated cost',
                'Latency',
                'Verifier result',
                'Citation completeness',
            ] as $telemetryField
        ) {
            $this->assertStringContainsString($telemetryField, $document);
        }

        $this->assertStringContainsString('No eval result file is created unless this smoke tier was actually run', $document);
        $this->assertStringContainsString('release rule', strtolower($document));
        $this->assertStringContainsString('captured result or an explicit documented gap', $document);
    }

    public function testReviewerGuideAndEvalReadmePointToTierTaxonomy(): void
    {
        $reviewerGuide = $this->readRepoFile('/AGENTFORGE-REVIEWER-GUIDE.md');
        $evalReadme = $this->readRepoFile('/agent-forge/eval-results/README.md');

        $this->assertStringContainsString('agent-forge/docs/EVALUATION-TIERS.md', $reviewerGuide);
        $this->assertStringContainsString('Tier 0 deterministic fixture/orchestration proof', $evalReadme);
        $this->assertStringContainsString('not full live-agent proof', $evalReadme);
    }

    private function evaluationDocument(): string
    {
        return $this->readRepoFile('/agent-forge/docs/EVALUATION-TIERS.md');
    }

    private function readRepoFile(string $path): string
    {
        $document = file_get_contents(dirname(__DIR__, 4) . $path);

        $this->assertIsString($document);

        return $document;
    }
}

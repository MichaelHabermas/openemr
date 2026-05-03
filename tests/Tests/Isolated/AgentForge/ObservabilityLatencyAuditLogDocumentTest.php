<?php

/**
 * Isolated regression tests for Epic 14 observability and sensitive audit-log docs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class ObservabilityLatencyAuditLogDocumentTest extends TestCase
{
    public function testSensitiveAuditLogPolicyDocumentsAllowedForbiddenAndAccessControls(): void
    {
        $architecture = $this->readRepoFile('/ARCHITECTURE.md');
        $plan = $this->readRepoFile('/agent-forge/docs/PLAN.md');
        $epic = $this->epicDocument();
        $combined = $architecture . "\n" . $plan . "\n" . $epic;

        foreach (
            [
                'PHI-minimized sensitive audit logs',
                'request id',
                'user id',
                'patient id',
                'decision',
                'timestamp',
                'total latency',
                'stage_timings_ms',
                'question type',
                'tools called',
                'source ids',
                'model',
                'token counts',
                'estimated cost',
                'failure reason',
                'verifier result',
                'raw question',
                'full answer',
                'full prompt',
                'full chart text',
                'patient name',
                'credentials',
                'raw exception internals',
                'restricted operational access',
                'retention governance',
                'review responsibility',
            ] as $requiredPolicyText
        ) {
            $this->assertStringContainsString($requiredPolicyText, $combined);
        }
    }

    public function testCurrentDocsDoNotClaimAgentForgeLogsAreDeIdentified(): void
    {
        foreach ($this->currentClaimDocuments() as $path => $document) {
            $this->assertStringNotContainsString('are de-identified logs', $document, $path);
            $this->assertStringNotContainsString('deidentified logs', $document, $path);
            $this->assertStringNotContainsString('PHI-free by contract', $document, $path);
            $this->assertStringNotContainsString('PHI-free logging contract', $document, $path);
        }
    }

    public function testObservabilityDocsDistinguishStageTimingFromFullObservability(): void
    {
        $combined = implode("\n", [
            $this->readRepoFile('/ARCHITECTURE.md'),
            $this->readRepoFile('/AUDIT.md'),
            $this->readRepoFile('/agent-forge/docs/PRD.md'),
            $this->epicDocument(),
        ]);

        foreach (
            [
                'stage_timings_ms',
                'StageTimer',
                'evidence-tool',
                'draft',
                'verifier',
                'aggregation',
                'dashboards',
                'SLOs',
                'alerts',
                'p50/p95/p99 latency',
                'verifier-failure tracking',
                'cost anomaly tracking',
            ] as $requiredObservabilityText
        ) {
            $this->assertStringContainsString($requiredObservabilityText, $combined);
        }

        $this->assertStringContainsString('not full production observability', $combined);
        $this->assertStringContainsString('remain unavailable', $combined);
    }

    public function testLatencyBudgetIncludesBaselinesAndProductionReadinessGate(): void
    {
        $combined = implode("\n", [
            $this->readRepoFile('/ARCHITECTURE.md'),
            $this->readRepoFile('/AUDIT.md'),
            $this->readRepoFile('/agent-forge/docs/PRD.md'),
            $this->readRepoFile('/agent-forge/docs/operations/COST-ANALYSIS.md'),
            $this->epicDocument(),
        ]);

        foreach (
            [
                '2,989 ms',
                '2989 ms',
                '10,693 ms',
                '10693 ms',
                'demo evidence only',
                'production-readiness gate',
                'p95',
                'under 10 seconds',
                'stage_timings_ms',
                'selective routing',
                'evidence-size reduction',
                'model timeout tuning',
                'citation-safe prompt/cache strategy',
                'query/index proof',
                'infrastructure measurement',
            ] as $requiredLatencyText
        ) {
            $this->assertStringContainsString($requiredLatencyText, $combined);
        }
    }

    public function testEpic14DocumentRecordsNoRuntimeObservabilityStackAdded(): void
    {
        $epic = $this->epicDocument();

        foreach (
            [
                'No dashboard',
                'alerting provider',
                'log shipper',
                'database migration',
                'telemetry backend',
                'Runtime dashboards, alerting infrastructure, and a new telemetry backend are not implemented',
            ] as $deferredRuntimeText
        ) {
            $this->assertStringContainsString($deferredRuntimeText, $epic);
        }
    }

    public function testReviewerDocsDoNotUsePlanEpicsAsProductStatusSource(): void
    {
        foreach ($this->stableReviewerDocuments() as $path => $document) {
            foreach (
                [
                    'PLAN.md Epic',
                    'Epic 14',
                    'Epic 13',
                    'Epic 12',
                    'Epic 11',
                    'Epic 10',
                    'Epic 9',
                    'Epic 8',
                    'planned remediation',
                    'Planned remediation',
                    'remediation epics',
                    'before remediation epics',
                    'planned tier',
                    'planned smoke tier',
                    'deferred',
                ] as $fragileReference
            ) {
                $this->assertStringNotContainsString($fragileReference, $document, $path);
            }
        }
    }

    /** @return array<string, string> */
    private function currentClaimDocuments(): array
    {
        $paths = [
            '/ARCHITECTURE.md',
            '/AUDIT.md',
            '/agent-forge/docs/PRD.md',
            '/agent-forge/docs/operations/COST-ANALYSIS.md',
            '/agent-forge/docs/epics/EPIC_OBSERVABILITY_LATENCY_AUDIT_LOGS.md',
        ];
        $documents = [];
        foreach ($paths as $path) {
            $documents[$path] = $this->readRepoFile($path);
        }

        return $documents;
    }

    private function epicDocument(): string
    {
        return $this->readRepoFile('/agent-forge/docs/epics/EPIC_OBSERVABILITY_LATENCY_AUDIT_LOGS.md');
    }

    /** @return array<string, string> */
    private function stableReviewerDocuments(): array
    {
        $paths = [
            '/ARCHITECTURE.md',
            '/AUDIT.md',
            '/agent-forge/docs/PRD.md',
            '/agent-forge/docs/README.md',
            '/USERS.md',
            '/agent-forge/docs/evaluation/EVALUATION-TIERS.md',
            '/agent-forge/docs/evaluation/GAUNTLET-INSTRUCTOR-REVIEWS.md',
            '/agent-forge/docs/operations/COST-ANALYSIS.md',
            '/agent-forge/docs/submission/EARLY-SUBMISSION-SCRIPT.md',
            '/agent-forge/docs/submission/REVIEWER-PACKAGING-PLAN.md',
        ];
        $documents = [];
        foreach ($paths as $path) {
            $documents[$path] = $this->readRepoFile($path);
        }

        return $documents;
    }

    private function readRepoFile(string $path): string
    {
        $document = file_get_contents(dirname(__DIR__, 4) . $path);

        $this->assertIsString($document);

        return $document;
    }
}

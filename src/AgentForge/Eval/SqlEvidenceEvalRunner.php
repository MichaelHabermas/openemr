<?php

/**
 * Runs seeded SQL evidence eval cases against real AgentForge evidence tools.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval;

use DateTimeImmutable;
use DateTimeInterface;
use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\EvidenceItem;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Handlers\AgentRequest;

/**
 * @phpstan-type SqlEvidenceCaseResult array{
 *     id: string,
 *     patient_id: int,
 *     description: string,
 *     passed: bool,
 *     failure_reason: string,
 *     latency_ms: int,
 *     citation_count?: int,
 *     missing_sections?: list<string>,
 *     failed_sections?: list<string>,
 *     expected_citations?: list<string>,
 *     present_citations?: list<string>,
 *     expected_decision_code?: string,
 * }
 *
 * @phpstan-type SqlEvidenceRunSummary array{
 *     tier: string,
 *     fixture_version: string,
 *     timestamp: string,
 *     code_version: string,
 *     environment_label: string,
 *     total: int,
 *     passed: int,
 *     failed: int,
 *     results: list<SqlEvidenceCaseResult>,
 * }
 */
final readonly class SqlEvidenceEvalRunner
{
    /**
     * @param list<ChartEvidenceTool> $tools
     */
    public function __construct(private array $tools, private ?PatientAuthorizationGate $authorizationGate = null)
    {
    }

    /**
     * @param list<SqlEvidenceEvalCase> $cases
     * @return SqlEvidenceRunSummary
     */
    public function run(
        array $cases,
        string $fixtureVersion,
        string $codeVersion,
        string $environmentLabel,
        ?DateTimeImmutable $startedAt = null,
    ): array {
        $startedAt ??= new DateTimeImmutable();
        $results = array_merge(
            array_map(fn (SqlEvidenceEvalCase $case): array => $this->runCase($case), $cases),
            $this->runAuthorizationCases(),
        );
        $passed = count(array_filter($results, static fn (array $result): bool => $result['passed'] === true));

        return [
            'tier' => 'seeded_sql_evidence',
            'fixture_version' => $fixtureVersion,
            'timestamp' => $startedAt->format(DateTimeInterface::ATOM),
            'code_version' => $codeVersion,
            'environment_label' => $environmentLabel,
            'total' => count($results),
            'passed' => $passed,
            'failed' => count($results) - $passed,
            'results' => $results,
        ];
    }

    /** @return SqlEvidenceCaseResult */
    private function runCase(SqlEvidenceEvalCase $case): array
    {
        $start = hrtime(true);
        $failures = [];
        $collected = [
            'citations' => [],
            'missing_sections' => [],
            'failed_sections' => [],
            'values' => [],
        ];

        foreach ($this->tools as $tool) {
            try {
                $result = $tool->collect(new PatientId($case->patientId));
            } catch (\Exception $exception) {
                $failures[] = sprintf('%s threw %s.', $tool->section(), $exception::class);
                continue;
            }

            $collected['missing_sections'] = array_merge($collected['missing_sections'], $result->missingSections);
            $collected['failed_sections'] = array_merge($collected['failed_sections'], $result->failedSections);
            foreach ($result->items as $item) {
                $collected['citations'][] = $item->citation();
                $collected['values'][] = $this->searchableValue($item);
            }
        }

        $collected['citations'] = array_values(array_unique($collected['citations']));
        $collected['missing_sections'] = array_values(array_unique($collected['missing_sections']));
        $collected['failed_sections'] = array_values(array_unique($collected['failed_sections']));

        foreach ($case->expectedCitations as $citation) {
            if (!in_array($citation, $collected['citations'], true)) {
                $failures[] = sprintf('Missing expected citation %s.', $citation);
            }
        }

        foreach ($case->forbiddenCitations as $citationOrFragment) {
            if ($this->containsFragment($collected['citations'], $citationOrFragment)) {
                $failures[] = sprintf('Found forbidden citation/source %s.', $citationOrFragment);
            }
        }

        foreach ($case->expectedMissing as $section) {
            if (!$this->containsFragment($collected['missing_sections'], $section)) {
                $failures[] = sprintf('Missing expected missing-section signal %s.', $section);
            }
        }

        foreach ($case->expectedValueFragments as $fragment) {
            if (!$this->containsFragment($collected['values'], $fragment)) {
                $failures[] = sprintf('Missing expected evidence value fragment "%s".', $fragment);
            }
        }

        foreach ($case->forbiddenValueFragments as $fragment) {
            if ($this->containsFragment($collected['values'], $fragment)) {
                $failures[] = sprintf('Found forbidden evidence value fragment "%s".', $fragment);
            }
        }

        if ($collected['failed_sections'] !== []) {
            $failures[] = 'Evidence tool failures: ' . implode('; ', $collected['failed_sections']);
        }

        return [
            'id' => $case->id,
            'patient_id' => $case->patientId,
            'description' => $case->description,
            'passed' => $failures === [],
            'failure_reason' => implode(' ', $failures),
            'latency_ms' => max(0, (int) floor((hrtime(true) - $start) / 1_000_000)),
            'citation_count' => count($collected['citations']),
            'missing_sections' => $collected['missing_sections'],
            'failed_sections' => $collected['failed_sections'],
            'expected_citations' => $case->expectedCitations,
            'present_citations' => $collected['citations'],
        ];
    }

    private function searchableValue(EvidenceItem $item): string
    {
        return implode(' ', [$item->displayLabel, $item->value, $item->citation()]);
    }

    /** @return list<SqlEvidenceCaseResult> */
    private function runAuthorizationCases(): array
    {
        if ($this->authorizationGate === null) {
            return [];
        }

        return [
            $this->runAuthorizationCase(
                'authorized_relationship_900001',
                'Admin-style seeded user has direct relationship to Alex.',
                900001,
                900001,
                1,
                true,
                true,
                'allowed',
            ),
            $this->runAuthorizationCase(
                'unauthorized_patient_900001',
                'Seeded unrelated user is refused before chart evidence is read.',
                900001,
                900001,
                900004,
                true,
                false,
                'patient_specific_access_could_not_be_verified_for_this_user',
            ),
            $this->runAuthorizationCase(
                'cross_patient_mismatch_900001_900002',
                'Requested patient mismatch against active chart fails closed.',
                900001,
                900002,
                1,
                true,
                false,
                'the_requested_patient_does_not_match_the_active_chart',
            ),
        ];
    }

    /** @return SqlEvidenceCaseResult */
    private function runAuthorizationCase(
        string $id,
        string $description,
        int $requestPatientId,
        int $sessionPatientId,
        int $sessionUserId,
        bool $hasMedicalRecordAcl,
        bool $expectedAllowed,
        string $expectedCode,
    ): array {
        $start = hrtime(true);
        $failures = [];
        try {
            $decision = $this->authorizationGate?->decide(
                new AgentRequest(new PatientId($requestPatientId), new AgentQuestion('Give me a visit briefing.')),
                $sessionPatientId,
                $sessionUserId,
                $hasMedicalRecordAcl,
            );
            if ($decision === null) {
                $failures[] = 'Authorization gate was not configured.';
            } else {
                if ($decision->allowed !== $expectedAllowed) {
                    $failures[] = sprintf('Expected allowed=%s, got allowed=%s.', $expectedAllowed ? 'true' : 'false', $decision->allowed ? 'true' : 'false');
                }
                if ($decision->code !== $expectedCode) {
                    $failures[] = sprintf('Expected decision code %s, got %s.', $expectedCode, $decision->code);
                }
            }
        } catch (\Exception $exception) {
            $failures[] = sprintf('Authorization case threw %s.', $exception::class);
        }

        return [
            'id' => $id,
            'patient_id' => $requestPatientId,
            'description' => $description,
            'passed' => $failures === [],
            'failure_reason' => implode(' ', $failures),
            'latency_ms' => max(0, (int) floor((hrtime(true) - $start) / 1_000_000)),
            'expected_decision_code' => $expectedCode,
        ];
    }

    /** @param list<string> $haystack */
    private function containsFragment(array $haystack, string $needle): bool
    {
        $needle = strtolower($needle);
        foreach ($haystack as $value) {
            if (str_contains(strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }
}

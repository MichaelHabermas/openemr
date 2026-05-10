<?php

/**
 * Week 2 evidence-retriever worker for chart plus guideline evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Guidelines\GuidelineRetrievalResult;
use OpenEMR\AgentForge\Guidelines\GuidelineRetriever;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Observability\StageTimer;
use RuntimeException;

final readonly class EvidenceRetrieverWorker
{
    public const WORKER_NAME = 'evidence-retriever';
    private const GUIDELINE_SECTION = 'Guideline Evidence';
    private const GUIDELINE_MISSING_MESSAGE = 'Guideline evidence was not found in the approved corpus.';

    public function __construct(
        private ChartEvidenceCollector $chartCollector,
        private ?GuidelineRetriever $guidelineRetriever = null,
    ) {
    }

    public function retrieve(
        PatientId $patientId,
        AgentQuestion $question,
        ChartQuestionPlan $chartPlan,
        bool $includeGuidelines,
        ?StageTimer $timer = null,
        ?Deadline $deadline = null,
    ): EvidenceRun {
        $chartRun = $this->chartCollector->collect($patientId, $chartPlan, $timer, $deadline);
        if (!$includeGuidelines) {
            return $chartRun;
        }

        if ($deadline?->exceeded()) {
            return $this->mergeGuidelineFailure($chartRun, 'Guideline evidence could not be checked before the deadline.');
        }

        $timer?->start('evidence:' . self::GUIDELINE_SECTION);
        try {
            $guidelineResult = $this->guidelineRetriever?->retrieve($this->guidelineQuery($question, $chartRun->bundle))
                ?? new GuidelineRetrievalResult('not_found', [], null, 0.0);
        } catch (RuntimeException) {
            $timer?->stop('evidence:' . self::GUIDELINE_SECTION);

            return $this->mergeGuidelineFailure($chartRun, 'Guideline evidence could not be checked.');
        }
        $timer?->stop('evidence:' . self::GUIDELINE_SECTION);

        $guidelineItems = [];
        $missingSections = $chartRun->bundle->missingSections;
        if (!$guidelineResult->found()) {
            $missingSections[] = self::GUIDELINE_MISSING_MESSAGE;
        } else {
            foreach ($guidelineResult->candidates as $candidate) {
                $guidelineItems[] = $candidate->chunk->toEvidenceBundleItem();
            }
        }

        $mergeTelemetry = $guidelineResult->mergeTelemetry?->toContext();
        if ($mergeTelemetry !== null) {
            $mergeTelemetry['reranker_used'] = $guidelineResult->rerankerUsed;
        }

        return new EvidenceRun(
            new EvidenceBundle(
                array_merge($chartRun->bundle->items, $guidelineItems),
                array_values(array_unique($missingSections)),
                $chartRun->bundle->failedSections,
            ),
            $chartRun->results,
            array_values(array_unique(array_merge($chartRun->toolsCalled, [self::GUIDELINE_SECTION]))),
            $chartRun->skippedSections,
            $mergeTelemetry,
        );
    }

    private function guidelineQuery(AgentQuestion $question, EvidenceBundle $bundle): string
    {
        $evidenceText = [];
        foreach ($bundle->items as $item) {
            $evidenceText[] = $item->displayLabel . ' ' . preg_replace('/;\s*Citation:.*/', '', $item->value);
        }

        if ($evidenceText === []) {
            return $question->value;
        }

        return EvidenceText::bounded(implode("\n", $evidenceText) . "\n" . $question->value, 1200);
    }

    private function mergeGuidelineFailure(EvidenceRun $chartRun, string $message): EvidenceRun
    {
        return new EvidenceRun(
            new EvidenceBundle(
                $chartRun->bundle->items,
                $chartRun->bundle->missingSections,
                array_values(array_unique(array_merge(
                    $chartRun->bundle->failedSections,
                    [$message],
                ))),
            ),
            $chartRun->results,
            array_values(array_unique(array_merge($chartRun->toolsCalled, [self::GUIDELINE_SECTION]))),
            $chartRun->skippedSections,
        );
    }
}

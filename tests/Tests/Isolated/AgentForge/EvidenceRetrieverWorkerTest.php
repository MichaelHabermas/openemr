<?php

/**
 * Isolated tests for the Week 2 evidence-retriever worker.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Evidence\ChartEvidenceCollector;
use OpenEMR\AgentForge\Evidence\ChartQuestionPlan;
use OpenEMR\AgentForge\Evidence\EvidenceItem;
use OpenEMR\AgentForge\Evidence\EvidenceResult;
use OpenEMR\AgentForge\Evidence\EvidenceRetrieverWorker;
use OpenEMR\AgentForge\Evidence\EvidenceRun;
use OpenEMR\AgentForge\Guidelines\GuidelineChunk;
use OpenEMR\AgentForge\Guidelines\GuidelineRetrievalResult;
use OpenEMR\AgentForge\Guidelines\GuidelineRetriever;
use OpenEMR\AgentForge\Guidelines\GuidelineSearchCandidate;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use PHPUnit\Framework\TestCase;

final class EvidenceRetrieverWorkerTest extends TestCase
{
    public function testChartOnlyQuestionDoesNotRunGuidelineRetrieval(): void
    {
        $retriever = new RecordingGuidelineRetriever(new GuidelineRetrievalResult('found', [$this->candidate()], true, 0.4));

        $run = (new EvidenceRetrieverWorker(
            new RecordingChartCollector(),
            $retriever,
        ))->retrieve(
            new PatientId(900001),
            new AgentQuestion('Show me recent A1c.'),
            new ChartQuestionPlan('lab', ['Recent labs'], 8000),
            false,
        );

        $this->assertFalse($retriever->called);
        $this->assertSame(['Recent labs'], $run->toolsCalled);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $run->bundle->sourceIds());
    }

    public function testGuidelineQuestionMergesCitedGuidelineEvidence(): void
    {
        $retriever = new RecordingGuidelineRetriever(new GuidelineRetrievalResult('found', [$this->candidate()], true, 0.4));

        $run = (new EvidenceRetrieverWorker(
            new RecordingChartCollector(),
            $retriever,
        ))->retrieve(
            new PatientId(900001),
            new AgentQuestion('What does the guideline say about LDL?'),
            new ChartQuestionPlan('lab', ['Recent labs'], 8000),
            true,
        );

        $this->assertTrue($retriever->called);
        $this->assertContains('Guideline Evidence', $run->toolsCalled);
        $this->assertContains('guideline:ACC AHA LDL Follow-Up/ldl-1', $run->bundle->sourceIds());
        $guideline = $run->bundle->itemsBySourceId()['guideline:ACC AHA LDL Follow-Up/ldl-1'];
        $this->assertSame('guideline', $guideline->sourceType);
        $this->assertStringContainsString('LDL', $guideline->value);
    }

    public function testGuidelineRetrievalQueryIncludesCollectedEvidenceText(): void
    {
        $retriever = new RecordingGuidelineRetriever(new GuidelineRetrievalResult('found', [$this->candidate()], true, 0.4));

        (new EvidenceRetrieverWorker(
            new RecordingChartCollector(),
            $retriever,
        ))->retrieve(
            new PatientId(900001),
            new AgentQuestion('What changed and what evidence supports it?'),
            new ChartQuestionPlan('follow_up_change_review', ['Recent labs'], 8000),
            true,
        );

        $this->assertStringContainsString('Hemoglobin A1c 7.4 %', $retriever->lastQuery);
        $this->assertStringEndsWith('What changed and what evidence supports it?', $retriever->lastQuery);
    }


    public function testOutOfCorpusGuidelineQuestionReturnsMissingSignal(): void
    {
        $run = (new EvidenceRetrieverWorker(
            new RecordingChartCollector(),
            new RecordingGuidelineRetriever(new GuidelineRetrievalResult('not_found', [], true, 0.4)),
        ))->retrieve(
            new PatientId(900001),
            new AgentQuestion('What is the rheumatoid arthritis guideline?'),
            new ChartQuestionPlan('visit_briefing', [], 8000),
            true,
        );

        $this->assertContains(
            'Guideline evidence was not found in the approved corpus.',
            $run->bundle->missingSections,
        );
        $this->assertSame(['Guideline Evidence'], $run->toolsCalled);
    }

    public function testGuidelineRetrieverFailureReturnsVisibleUncheckedSignal(): void
    {
        $run = (new EvidenceRetrieverWorker(
            new RecordingChartCollector(),
            new ThrowingGuidelineRetriever(),
        ))->retrieve(
            new PatientId(900001),
            new AgentQuestion('What ACC/AHA evidence applies?'),
            new ChartQuestionPlan('lab', ['Recent labs'], 8000),
            true,
        );

        $this->assertContains('Guideline evidence could not be checked.', $run->bundle->failedSections);
        $this->assertContains('Guideline Evidence', $run->toolsCalled);
    }

    private function candidate(): GuidelineSearchCandidate
    {
        return new GuidelineSearchCandidate(
            new GuidelineChunk(
                'ldl-1',
                'clinical-guideline-demo',
                'ACC AHA LDL Follow-Up',
                'agent-forge/fixtures/clinical-guideline-corpus/acc-aha-ldl-follow-up.md',
                'LDL follow-up',
                'LDL greater than or equal to 130 should be reviewed with cardiovascular risk context.',
                [],
            ),
            rerankScore: 0.92,
        );
    }
}

final class RecordingChartCollector implements ChartEvidenceCollector
{
    public function collect(
        PatientId $patientId,
        ChartQuestionPlan $plan,
        ?\OpenEMR\AgentForge\Observability\StageTimer $timer = null,
        ?\OpenEMR\AgentForge\Deadline $deadline = null,
    ): EvidenceRun {
        $results = [];
        if ($plan->sections !== []) {
            $results[] = EvidenceResult::found('Recent labs', [
                new EvidenceItem('lab', 'procedure_result', 'a1c', '2026-04-10', 'Hemoglobin A1c', '7.4 %'),
            ]);
        }

        return new EvidenceRun(
            \OpenEMR\AgentForge\Evidence\EvidenceBundle::fromEvidenceResults($results),
            $results,
            $plan->sections,
            $plan->skippedSections,
        );
    }
}

final class RecordingGuidelineRetriever implements GuidelineRetriever
{
    public bool $called = false;
    public string $lastQuery = '';

    public function __construct(private readonly GuidelineRetrievalResult $result)
    {
    }

    public function retrieve(string $query): GuidelineRetrievalResult
    {
        $this->called = true;
        $this->lastQuery = $query;

        return $this->result;
    }
}

final class ThrowingGuidelineRetriever implements GuidelineRetriever
{
    public function retrieve(string $query): GuidelineRetrievalResult
    {
        throw new \RuntimeException('SQLSTATE should not escape');
    }
}

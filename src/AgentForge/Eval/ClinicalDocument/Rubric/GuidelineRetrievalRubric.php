<?php

/**
 * Clinical document guideline retrieval rubric.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

final class GuidelineRetrievalRubric implements Rubric
{
    public function name(): string
    {
        return 'guideline_retrieval';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        $expected = $inputs->case->expectedRetrieval;
        if (!$expected->guidelineRetrievalRequired && $expected->minGuidelineChunks === 0 && !$expected->outOfCorpus) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Guideline retrieval is not required.');
        }

        $status = $inputs->output->retrieval['status'] ?? null;
        $chunks = $inputs->output->retrieval['guideline_chunks'] ?? null;
        $rerankerUsed = $inputs->output->retrieval['reranker_used'] ?? null;
        if (!is_string($rerankerUsed)) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Guideline retrieval did not report which reranker was used.');
        }
        if (!is_array($chunks)) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Guideline retrieval chunks were not reported.');
        }

        if ($expected->outOfCorpus) {
            if ($status === 'not_found' && count($chunks) === 0) {
                return new RubricResult($this->name(), RubricStatus::Pass, 'Out-of-corpus guideline request returned not found.');
            }

            return new RubricResult($this->name(), RubricStatus::Fail, 'Out-of-corpus guideline request returned evidence.');
        }

        if ($status !== 'found') {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Required guideline retrieval did not find evidence.');
        }
        if (count($chunks) < $expected->minGuidelineChunks) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Guideline retrieval returned too few chunks.');
        }

        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Guideline retrieval returned a malformed chunk.');
            }
            foreach (['chunk_id', 'source_title', 'section', 'evidence_text', 'rerank_score', 'citation'] as $field) {
                if (!array_key_exists($field, $chunk)) {
                    return new RubricResult($this->name(), RubricStatus::Fail, sprintf('Guideline chunk is missing %s.', $field));
                }
            }
            if (!is_array($chunk['citation'] ?? null) || ($chunk['citation']['source_type'] ?? null) !== 'guideline') {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Guideline chunk citation is missing guideline source type.');
            }
            if (!is_int($chunk['rerank_score']) && !is_float($chunk['rerank_score'])) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Guideline chunk rerank score is not numeric.');
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Guideline retrieval matched expected behavior.');
    }
}

<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseCategory;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedAnswer;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedExtraction;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedRetrieval;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedRubrics;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricInputs;
use PHPUnit\Framework\TestCase;

abstract class RubricTestCase extends TestCase
{
    /**
     * @param array<string, bool|null> $rubrics
     * @param list<array<string, mixed>> $expectedFacts
     * @param list<array<string, mixed>> $expectedPromotions
     * @param list<array<string, mixed>> $expectedDocumentFacts
     * @param list<string> $logMustNotContain
     */
    protected function inputs(
        array $rubrics,
        CaseRunOutput $output,
        array $expectedFacts = [],
        bool $refusalRequired = false,
        array $logMustNotContain = [],
        ?ExpectedAnswer $expectedAnswer = null,
        array $expectedPromotions = [],
        array $expectedDocumentFacts = [],
    ): RubricInputs {
        return new RubricInputs(
            new EvalCase(
                1,
                'case-a',
                EvalCaseCategory::LabPdfExtraction,
                'patient:test',
                'lab_pdf',
                [],
                new ExpectedExtraction(true, $expectedFacts),
                $expectedPromotions,
                $expectedDocumentFacts,
                new ExpectedRetrieval(false, 0),
                $expectedAnswer ?? new ExpectedAnswer(),
                $refusalRequired,
                $logMustNotContain,
                new ExpectedRubrics($rubrics),
            ),
            $output,
        );
    }
}

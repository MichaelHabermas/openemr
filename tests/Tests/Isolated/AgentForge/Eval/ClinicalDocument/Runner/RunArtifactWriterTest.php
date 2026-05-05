<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Runner;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\EvalRunResult;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RegressionVerdict;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RubricSummary;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RunArtifactWriter;
use PHPUnit\Framework\TestCase;

final class RunArtifactWriterTest extends TestCase
{
    public function testWritesRunAndSummaryJson(): void
    {
        $dir = sys_get_temp_dir() . '/clinical-document-eval-writer-' . bin2hex(random_bytes(4));
        $runDir = (new RunArtifactWriter($dir))->write(
            new EvalRunResult([['case_id' => 'case-a']], ['schema_valid' => new RubricSummary('schema_valid', 0, 1, 0, 0.0)]),
            RegressionVerdict::ThresholdViolation,
        );

        $this->assertFileExists($runDir . '/run.json');
        $this->assertFileExists($runDir . '/summary.json');
    }
}

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

use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use PHPUnit\Framework\TestCase;

final class RubricRegistryTest extends TestCase
{
    public function testIncludesRequiredRubrics(): void
    {
        $registry = new RubricRegistry();

        $this->assertNotNull($registry->get('schema_valid'));
        $this->assertNotNull($registry->get('citation_present'));
        $this->assertNotNull($registry->get('factually_consistent'));
        $this->assertNotNull($registry->get('safe_refusal'));
        $this->assertNotNull($registry->get('no_phi_in_logs'));
        $this->assertNotNull($registry->get('bounding_box_present'));
    }
}

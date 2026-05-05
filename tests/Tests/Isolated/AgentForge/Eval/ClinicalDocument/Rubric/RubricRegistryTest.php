<?php

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

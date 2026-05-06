<?php

/**
 * Isolated tests for Week 2 clinical document seed contracts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use PHPUnit\Framework\TestCase;

final class ClinicalDocumentSeedTest extends TestCase
{
    private string $seedSql;

    protected function setUp(): void
    {
        $seedPath = dirname(__DIR__, 5) . '/agent-forge/sql/seed-demo-data.sql';
        $seedSql = file_get_contents($seedPath);
        $this->assertIsString($seedSql);
        $this->seedSql = $seedSql;
    }

    public function testSeedDoesNotCreateVisibleBrandedDocumentCategories(): void
    {
        $this->assertStringNotContainsString('AgentForge Lab PDF', $this->seedSql);
        $this->assertStringNotContainsString('AgentForge Intake Form', $this->seedSql);
    }

    public function testSeedMapsExistingLabReportCategoryToLabPdf(): void
    {
        $section = $this->clinicalDocumentSeedSection();

        $this->assertStringContainsString("WHERE name = 'Lab Report'", $section);
        $this->assertStringContainsString('INSERT INTO clinical_document_type_mappings', $section);
        $this->assertStringContainsString("SELECT @lab_pdf_cat_id, 'lab_pdf', 1, NOW()", $section);
        $this->assertStringContainsString('WHERE category_id = @lab_pdf_cat_id', $section);
    }

    public function testSeedCreatesAndMapsIntakeFormCategory(): void
    {
        $section = $this->clinicalDocumentSeedSection();

        $this->assertStringContainsString("'Intake Form'", $section);
        $this->assertStringContainsString('INSERT INTO categories', $section);
        $this->assertStringContainsString("SELECT @intake_form_cat_id, 'intake_form', 1, NOW()", $section);
        $this->assertStringContainsString('WHERE category_id = @intake_form_cat_id', $section);
    }

    public function testSeedIncludesChenClinicalDocumentDemoPatient(): void
    {
        $this->assertStringContainsString('SET @chen_pid := 900101;', $this->seedSql);
        $this->assertStringContainsString("SET @chen_pubpid := 'BHS-2847163';", $this->seedSql);
        $this->assertStringContainsString("'Margaret'", $this->seedSql);
        $this->assertStringContainsString("'Chen'", $this->seedSql);
        $this->assertStringContainsString("'1968-03-12'", $this->seedSql);
    }

    private function clinicalDocumentSeedSection(): string
    {
        $marker = '-- Week 2 clinical document extraction mappings.';
        $position = strpos($this->seedSql, $marker);
        $this->assertIsInt($position);

        return substr($this->seedSql, $position);
    }
}

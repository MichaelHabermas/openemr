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
        $this->assertStringNotContainsString('INSERT INTO categories', $this->clinicalDocumentSeedSection());
    }

    public function testSeedMapsExistingLabReportCategoryToLabPdf(): void
    {
        $section = $this->clinicalDocumentSeedSection();

        $this->assertStringContainsString("WHERE name = 'Lab Report'", $section);
        $this->assertStringContainsString('INSERT INTO clinical_document_type_mappings', $section);
        $this->assertStringContainsString("SELECT @lab_pdf_cat_id, 'lab_pdf', 1, NOW()", $section);
        $this->assertStringContainsString('WHERE category_id = @lab_pdf_cat_id AND doc_type = \'lab_pdf\'', $section);
    }

    public function testSeedDoesNotMapIntakeFormUntilARealCategoryIsChosen(): void
    {
        $section = $this->clinicalDocumentSeedSection();

        $this->assertStringNotContainsString("'intake_form'", $section);
    }

    private function clinicalDocumentSeedSection(): string
    {
        $marker = '-- Week 2 clinical document extraction mappings.';
        $position = strpos($this->seedSql, $marker);
        $this->assertIsInt($position);

        return substr($this->seedSql, $position);
    }
}

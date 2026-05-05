<?php

/**
 * Isolated tests for Week 2 clinical document schema contracts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use PHPUnit\Framework\TestCase;

final class ClinicalDocumentSchemaContractTest extends TestCase
{
    public function testFreshInstallUsesClinicalDocumentTableNamesOnly(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $section = $this->clinicalDocumentSection($databaseSql);

        $this->assertStringContainsString('CREATE TABLE `clinical_document_type_mappings`', $section);
        $this->assertStringContainsString('CREATE TABLE `clinical_document_processing_jobs`', $section);
        $this->assertStringNotContainsString('agentforge_document_type_mappings', $section);
        $this->assertStringNotContainsString('agentforge_document_jobs', $section);
    }

    public function testMappingSchemaAllowsOnlyOneMappingPerCategory(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        $this->assertStringContainsString(
            'UNIQUE KEY `uniq_clinical_document_type_mapping` (`category_id`)',
            $databaseSql,
        );
        $this->assertStringContainsString(
            'UNIQUE KEY `uniq_clinical_document_type_mapping` (`category_id`)',
            $upgradeSql,
        );
        $this->assertStringNotContainsString(
            'UNIQUE KEY `uniq_clinical_document_type_mapping` (`category_id`, `doc_type`)',
            $databaseSql . $upgradeSql,
        );
    }

    public function testProcessingJobSchemaIncludesRetractionMetadata(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        foreach ([$databaseSql, $upgradeSql] as $sql) {
            $this->assertStringContainsString('`retracted_at` datetime NULL', $sql);
            $this->assertStringContainsString('`retraction_reason` varchar(64) NULL', $sql);
            $this->assertStringContainsString(
                'UNIQUE KEY `uniq_clinical_document_processing_job` (`patient_id`, `document_id`, `doc_type`)',
                $sql,
            );
        }
    }

    public function testUpgradeDoesNotCarryUnreleasedBrandedTableRenames(): void
    {
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        $this->assertStringContainsString('#IfNotTable clinical_document_type_mappings', $upgradeSql);
        $this->assertStringContainsString('#IfNotTable clinical_document_processing_jobs', $upgradeSql);
        $this->assertStringNotContainsString('agentforge_document_type_mappings', $upgradeSql);
        $this->assertStringNotContainsString('agentforge_document_jobs', $upgradeSql);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 5) . $path);
        $this->assertIsString($contents);

        return $contents;
    }

    private function clinicalDocumentSection(string $databaseSql): string
    {
        $marker = '-- Week 2 clinical document ingestion tables';
        $position = strpos($databaseSql, $marker);
        $this->assertIsInt($position);

        return substr($databaseSql, $position);
    }
}

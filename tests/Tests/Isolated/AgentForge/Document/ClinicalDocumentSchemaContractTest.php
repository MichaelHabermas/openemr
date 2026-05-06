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
        $this->assertStringNotContainsString('CREATE TABLE `agentforge_', $section);
        $this->assertStringNotContainsString('DROP TABLE IF EXISTS `agentforge_', $section);
        $this->assertStringNotContainsString('KEY `idx_agentforge_', $section);
        $this->assertStringNotContainsString('UNIQUE KEY `uniq_agentforge_', $section);
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

    public function testWorkerHeartbeatSchemaExistsOnFreshInstallAndUpgrade(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        foreach ([$databaseSql, $upgradeSql] as $sql) {
            $this->assertStringContainsString('CREATE TABLE `clinical_document_worker_heartbeats`', $sql);
            $this->assertStringContainsString('`worker` varchar(64) NOT NULL', $sql);
            $this->assertStringContainsString('`process_id` int(11) NOT NULL', $sql);
            $this->assertStringContainsString('`status` varchar(32) NOT NULL', $sql);
            $this->assertStringContainsString('`iteration_count` bigint(20) NOT NULL DEFAULT 0', $sql);
            $this->assertStringContainsString('`jobs_processed` bigint(20) NOT NULL DEFAULT 0', $sql);
            $this->assertStringContainsString('`jobs_failed` bigint(20) NOT NULL DEFAULT 0', $sql);
            $this->assertStringContainsString('`started_at` datetime NOT NULL', $sql);
            $this->assertStringContainsString('`last_heartbeat_at` datetime NOT NULL', $sql);
            $this->assertStringContainsString('`stopped_at` datetime NULL', $sql);
            $this->assertStringContainsString(
                'UNIQUE KEY `uniq_clinical_document_worker_heartbeats_worker` (`worker`)',
                $sql,
            );
            $this->assertStringContainsString(
                'KEY `idx_clinical_document_worker_heartbeats_status` (`status`)',
                $sql,
            );
        }

        $this->assertStringContainsString('#IfNotTable clinical_document_worker_heartbeats', $upgradeSql);
    }

    public function testSupervisorHandoffSchemaExistsOnFreshInstallAndUpgrade(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        foreach ([$databaseSql, $upgradeSql] as $sql) {
            $this->assertStringContainsString('CREATE TABLE `clinical_supervisor_handoffs`', $sql);
            $this->assertStringContainsString('`request_id` varchar(64) NULL', $sql);
            $this->assertStringContainsString('`job_id` bigint(20) NULL', $sql);
            $this->assertStringContainsString('`source_node` varchar(64) NOT NULL', $sql);
            $this->assertStringContainsString('`destination_node` varchar(64) NOT NULL', $sql);
            $this->assertStringContainsString('`decision_reason` varchar(128) NOT NULL', $sql);
            $this->assertStringContainsString('`task_type` varchar(64) NOT NULL', $sql);
            $this->assertStringContainsString('`outcome` varchar(64) NOT NULL', $sql);
            $this->assertStringContainsString('`latency_ms` int(11) NULL', $sql);
            $this->assertStringContainsString('`error_reason` varchar(128) NULL', $sql);
            $this->assertStringContainsString(
                'KEY `idx_clinical_supervisor_handoff_job` (`job_id`, `created_at`)',
                $sql,
            );
            $this->assertStringContainsString(
                'KEY `idx_clinical_supervisor_handoff_destination` (`destination_node`, `created_at`)',
                $sql,
            );
        }

        $this->assertStringContainsString('#IfNotTable clinical_supervisor_handoffs', $upgradeSql);
    }

    public function testGuidelineVectorSchemaExistsOnFreshInstallAndUpgrade(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        foreach ([$databaseSql, $upgradeSql] as $sql) {
            $this->assertStringContainsString('CREATE TABLE `clinical_guideline_chunks`', $sql);
            $this->assertStringContainsString('CREATE TABLE `clinical_guideline_chunk_embeddings`', $sql);
            $this->assertStringContainsString('`corpus_version` varchar(191) NOT NULL', $sql);
            $this->assertStringContainsString('`embedding` VECTOR(1536) NOT NULL', $sql);
            $this->assertStringContainsString('PRIMARY KEY (`corpus_version`, `chunk_id`)', $sql);
            $this->assertStringContainsString(
                'UNIQUE KEY `uniq_clinical_guideline_chunk_version` (`corpus_version`, `chunk_id`)',
                $sql,
            );
            $this->assertStringNotContainsString('uniq_clinical_guideline_chunk_id', $sql);
        }

        $this->assertStringContainsString('#IfNotTable clinical_guideline_chunks', $upgradeSql);
        $this->assertStringContainsString('#IfNotTable clinical_guideline_chunk_embeddings', $upgradeSql);
    }

    public function testDocumentFactSchemaExistsOnFreshInstallAndUpgrade(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        foreach ([$databaseSql, $upgradeSql] as $sql) {
            $this->assertStringContainsString('CREATE TABLE `clinical_document_facts`', $sql);
            $this->assertStringContainsString('CREATE TABLE `clinical_document_fact_embeddings`', $sql);
            $this->assertStringContainsString('`fact_fingerprint` char(64) NOT NULL', $sql);
            $this->assertStringContainsString('`clinical_content_fingerprint` char(64) NOT NULL', $sql);
            $this->assertStringContainsString('`certainty` varchar(32) NOT NULL', $sql);
            $this->assertStringContainsString('`embedding` VECTOR(1536) NOT NULL', $sql);
            $this->assertStringContainsString(
                'UNIQUE KEY `uniq_clinical_document_fact_source` (`patient_id`, `document_id`, `doc_type`, `fact_fingerprint`)',
                $sql,
            );
            $this->assertStringContainsString(
                'KEY `idx_clinical_document_fact_patient_active` (`patient_id`, `active`, `retracted_at`, `created_at`)',
                $sql,
            );
            $this->assertStringContainsString('PRIMARY KEY (`fact_id`, `embedding_model`)', $sql);
        }

        $this->assertStringContainsString('#IfNotTable clinical_document_facts', $upgradeSql);
        $this->assertStringContainsString('#IfNotTable clinical_document_fact_embeddings', $upgradeSql);
    }

    public function testDatabaseVersionBumpedForWorkerHeartbeatSchema(): void
    {
        $databaseSql = $this->readProjectFile('/sql/database.sql');
        $versionPhp = $this->readProjectFile('/version.php');

        $this->assertStringContainsString('-- v_database: 540', $databaseSql);
        $this->assertStringContainsString('$v_database = 540;', $versionPhp);
    }

    public function testUpgradeDoesNotCarryUnreleasedBrandedTableRenames(): void
    {
        $upgradeSql = $this->readProjectFile('/sql/8_1_0-to-8_1_1_upgrade.sql');

        $this->assertStringContainsString('#IfNotTable clinical_document_type_mappings', $upgradeSql);
        $this->assertStringContainsString('#IfNotTable clinical_document_processing_jobs', $upgradeSql);
        $this->assertStringNotContainsString('#IfNotTable agentforge_', $upgradeSql);
        $this->assertStringNotContainsString('CREATE TABLE `agentforge_', $upgradeSql);
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

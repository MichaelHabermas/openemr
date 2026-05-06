<?php

/**
 * Schema contract tests for AgentForge clinical document promotion provenance.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use OpenEMR\AgentForge\Document\Promotion\PromotionOutcome;
use PHPUnit\Framework\TestCase;

final class PromotionSchemaContractTest extends TestCase
{
    public function testCanonicalPromotionTableExistsInInstallAndUpgradeSchema(): void
    {
        $databaseSql = $this->readRepoFile('sql/database.sql');
        $upgradeSql = $this->readRepoFile('sql/8_1_0-to-8_1_1_upgrade.sql');

        foreach ([$databaseSql, $upgradeSql] as $sql) {
            $this->assertStringContainsString('clinical_document_promotions', $sql);
            foreach ($this->requiredColumns() as $column) {
                $this->assertStringContainsString(sprintf('`%s`', $column), $sql);
            }

            $this->assertStringContainsString('uniq_clinical_document_promotion_target', $sql);
            $this->assertStringContainsString('uniq_clinical_document_promotion_source', $sql);
            $this->assertStringContainsString('idx_clinical_document_promotion_content', $sql);
        }

        $this->assertStringContainsString('INSERT IGNORE INTO `clinical_document_promotions`', $upgradeSql);
        $this->assertStringContainsString('FROM `clinical_document_promoted_facts`', $upgradeSql);
    }

    public function testPromotionOutcomeVocabularyMatchesWeekTwoContract(): void
    {
        $this->assertSame(
            [
                'promoted',
                'already_exists',
                'duplicate_skipped',
                'conflict_needs_review',
                'not_promotable',
                'needs_review',
                'rejected',
                'promotion_failed',
                'retracted',
            ],
            array_map(static fn (PromotionOutcome $outcome): string => $outcome->value, PromotionOutcome::cases()),
        );
    }

    /** @return list<string> */
    private function requiredColumns(): array
    {
        return [
            'id',
            'patient_id',
            'document_id',
            'job_id',
            'fact_id',
            'fact_fingerprint',
            'clinical_content_fingerprint',
            'promoted_table',
            'promoted_record_id',
            'promoted_pk_json',
            'outcome',
            'duplicate_key',
            'conflict_reason',
            'citation_json',
            'bounding_box_json',
            'confidence',
            'review_status',
            'active',
            'created_at',
            'updated_at',
            'retracted_at',
            'retraction_reason',
        ];
    }

    private function readRepoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 5) . '/' . $path);
        $this->assertIsString($contents);

        return $contents;
    }
}

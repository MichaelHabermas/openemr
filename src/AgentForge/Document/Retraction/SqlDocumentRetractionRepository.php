<?php

/**
 * SQL-backed source-document retraction workflow with append-only audit rows.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Retraction;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentRetractionReason;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Promotion\PromotionOutcome;
use Throwable;

final readonly class SqlDocumentRetractionRepository implements DocumentRetractionRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        $this->executor->executeStatement('START TRANSACTION');

        try {
            $affected = $this->retractByDocumentInTransaction($documentId, $reason);
            $this->executor->executeStatement('COMMIT');

            return $affected;
        } catch (Throwable $throwable) {
            $this->executor->executeStatement('ROLLBACK');

            throw $throwable;
        }
    }

    private function retractByDocumentInTransaction(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        $this->auditJobs($documentId, $reason);
        $affected = $this->retractJobs($documentId, $reason);

        $this->auditProcedureResults($documentId, $reason);
        $this->retractProcedureResults($documentId, $reason);

        $this->auditLists($documentId, $reason);
        $this->retractLists($documentId, $reason);

        $this->auditLegacyPromotedFacts($documentId, $reason);
        $this->retractLegacyProcedureResults($documentId);
        $this->retractLegacyLists($documentId);

        $this->auditPromotions($documentId, $reason);
        $this->retractPromotions($documentId, $reason);

        $this->retractLegacyPromotedFacts($documentId);

        $this->auditFacts($documentId, $reason);
        $this->retractFacts($documentId, $reason);

        $this->auditEmbeddings($documentId, $reason);
        $this->deactivateEmbeddings($documentId);

        $this->auditIdentityChecks($documentId, $reason);
        $this->scrubIdentityChecks($documentId);

        return $affected;
    }

    private function auditJobs(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT patient_id, document_id, id, '
            . 'JSON_OBJECT(\'status\', status, \'retracted_at\', retracted_at, \'retraction_reason\', retraction_reason), '
            . 'JSON_OBJECT(\'status\', ?, \'retraction_reason\', ?), '
            . '?, ?, ?, NOW() '
            . 'FROM clinical_document_processing_jobs '
            . 'WHERE document_id = ? AND status <> ?',
            [
                JobStatus::Retracted->value,
                $reason->value,
                'retract_job',
                'system',
                $reason->value,
                $documentId->value,
                JobStatus::Retracted->value,
            ],
        );
    }

    private function retractJobs(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        return $this->executor->executeAffected(
            'UPDATE clinical_document_processing_jobs '
            . 'SET status = ?, retracted_at = NOW(), retraction_reason = ?, '
            . 'finished_at = COALESCE(finished_at, NOW()), lock_token = NULL '
            . 'WHERE document_id = ? AND status <> ?',
            [
                JobStatus::Retracted->value,
                $reason->value,
                $documentId->value,
                JobStatus::Retracted->value,
            ],
        );
    }

    private function auditProcedureResults(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, fact_id, promotion_id, promoted_table, promoted_record_id, '
            . 'prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT p.patient_id, p.document_id, p.job_id, p.fact_id, p.id, p.promoted_table, p.promoted_record_id, '
            . 'JSON_OBJECT(\'result_status\', pr.result_status, \'comments\', pr.comments, \'promotion_outcome\', p.outcome, \'promotion_active\', p.active), '
            . 'JSON_OBJECT(\'result_status\', ?, \'promotion_outcome\', ?, \'promotion_active\', 0), '
            . '?, ?, ?, NOW() '
            . 'FROM procedure_result pr '
            . 'INNER JOIN clinical_document_promotions p ON p.promoted_table = ? AND p.promoted_record_id = CAST(pr.procedure_result_id AS CHAR) '
            . 'WHERE p.document_id = ? AND p.outcome = ? AND p.active = 1',
            [
                'corrected',
                PromotionOutcome::Retracted->value,
                'retract_promoted_row',
                'system',
                $reason->value,
                'procedure_result',
                $documentId->value,
                PromotionOutcome::Promoted->value,
            ],
        );
    }

    private function retractProcedureResults(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'UPDATE procedure_result pr '
            . 'INNER JOIN clinical_document_promotions p ON p.promoted_table = ? AND p.promoted_record_id = CAST(pr.procedure_result_id AS CHAR) '
            . 'SET pr.result_status = ?, pr.comments = CONCAT(COALESCE(pr.comments, \'\'), ?), '
            . 'p.outcome = ?, p.review_status = ?, p.active = 0, p.retracted_at = NOW(), p.retraction_reason = ?, p.updated_at = NOW() '
            . 'WHERE p.document_id = ? AND p.outcome = ? AND p.active = 1',
            [
                'procedure_result',
                'corrected',
                ' AgentForge source document retracted.',
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                $reason->value,
                $documentId->value,
                PromotionOutcome::Promoted->value,
            ],
        );
    }

    private function auditLists(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, fact_id, promotion_id, promoted_table, promoted_record_id, '
            . 'prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT p.patient_id, p.document_id, p.job_id, p.fact_id, p.id, p.promoted_table, p.promoted_record_id, '
            . 'JSON_OBJECT(\'activity\', l.activity, \'comments\', l.comments, \'promotion_outcome\', p.outcome, \'promotion_active\', p.active), '
            . 'JSON_OBJECT(\'activity\', 0, \'promotion_outcome\', ?, \'promotion_active\', 0), '
            . '?, ?, ?, NOW() '
            . 'FROM lists l '
            . 'INNER JOIN clinical_document_promotions p ON p.promoted_table = ? AND p.promoted_record_id = CAST(l.id AS CHAR) '
            . 'WHERE p.document_id = ? AND p.outcome = ? AND p.active = 1',
            [
                PromotionOutcome::Retracted->value,
                'retract_promoted_row',
                'system',
                $reason->value,
                'lists',
                $documentId->value,
                PromotionOutcome::Promoted->value,
            ],
        );
    }

    private function retractLists(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'UPDATE lists l '
            . 'INNER JOIN clinical_document_promotions p ON p.promoted_table = ? AND p.promoted_record_id = CAST(l.id AS CHAR) '
            . 'SET l.activity = 0, l.comments = CONCAT(COALESCE(l.comments, \'\'), ?), '
            . 'p.outcome = ?, p.review_status = ?, p.active = 0, p.retracted_at = NOW(), p.retraction_reason = ?, p.updated_at = NOW() '
            . 'WHERE p.document_id = ? AND p.outcome = ? AND p.active = 1',
            [
                'lists',
                ' AgentForge source document retracted.',
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                $reason->value,
                $documentId->value,
                PromotionOutcome::Promoted->value,
            ],
        );
    }

    private function auditPromotions(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, fact_id, promotion_id, promoted_table, promoted_record_id, '
            . 'prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT patient_id, document_id, job_id, fact_id, id, promoted_table, promoted_record_id, '
            . 'JSON_OBJECT(\'outcome\', outcome, \'review_status\', review_status, \'active\', active, \'retracted_at\', retracted_at), '
            . 'JSON_OBJECT(\'outcome\', ?, \'review_status\', ?, \'active\', 0, \'retraction_reason\', ?), '
            . '?, ?, ?, NOW() '
            . 'FROM clinical_document_promotions '
            . 'WHERE document_id = ? AND active = 1',
            [
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                $reason->value,
                'retract_promotion',
                'system',
                $reason->value,
                $documentId->value,
            ],
        );
    }

    private function retractLegacyProcedureResults(DocumentId $documentId): void
    {
        $this->executor->executeStatement(
            'UPDATE procedure_result pr '
            . 'INNER JOIN clinical_document_promoted_facts pf ON pf.native_table = ? AND pf.native_id = pr.procedure_result_id '
            . 'SET pr.result_status = ?, pr.comments = CONCAT(COALESCE(pr.comments, \'\'), ?), pf.promotion_status = ?, pf.review_status = ?, pf.updated_at = NOW() '
            . 'WHERE pf.document_id = ? AND pf.promotion_status = ?',
            [
                'procedure_result',
                'corrected',
                ' AgentForge source document retracted.',
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                $documentId->value,
                PromotionOutcome::Promoted->value,
            ],
        );
    }

    private function retractLegacyLists(DocumentId $documentId): void
    {
        $this->executor->executeStatement(
            'UPDATE lists l '
            . 'INNER JOIN clinical_document_promoted_facts pf ON pf.native_table = ? AND pf.native_id = l.id '
            . 'SET l.activity = 0, l.comments = CONCAT(COALESCE(l.comments, \'\'), ?), pf.promotion_status = ?, pf.review_status = ?, pf.updated_at = NOW() '
            . 'WHERE pf.document_id = ? AND pf.promotion_status = ?',
            [
                'lists',
                ' AgentForge source document retracted.',
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                $documentId->value,
                PromotionOutcome::Promoted->value,
            ],
        );
    }

    private function retractPromotions(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'UPDATE clinical_document_promotions '
            . 'SET outcome = ?, review_status = ?, active = 0, retracted_at = COALESCE(retracted_at, NOW()), retraction_reason = ?, updated_at = NOW() '
            . 'WHERE document_id = ? AND active = 1',
            [
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                $reason->value,
                $documentId->value,
            ],
        );
    }

    private function auditLegacyPromotedFacts(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, promoted_table, promoted_record_id, prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT patient_id, document_id, job_id, native_table, CAST(native_id AS CHAR), '
            . 'JSON_OBJECT(\'promotion_status\', promotion_status, \'review_status\', review_status), '
            . 'JSON_OBJECT(\'promotion_status\', ?, \'review_status\', ?), '
            . '?, ?, ?, NOW() '
            . 'FROM clinical_document_promoted_facts '
            . 'WHERE document_id = ? AND promotion_status <> ?',
            [
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                'retract_legacy_promoted_fact',
                'system',
                $reason->value,
                $documentId->value,
                PromotionOutcome::Retracted->value,
            ],
        );
    }

    private function retractLegacyPromotedFacts(DocumentId $documentId): void
    {
        $this->executor->executeStatement(
            'UPDATE clinical_document_promoted_facts '
            . 'SET promotion_status = ?, review_status = ?, updated_at = NOW() '
            . 'WHERE document_id = ? AND promotion_status <> ?',
            [
                PromotionOutcome::Retracted->value,
                PromotionOutcome::Retracted->reviewStatus(),
                $documentId->value,
                PromotionOutcome::Retracted->value,
            ],
        );
    }

    private function auditFacts(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, fact_id, prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT patient_id, document_id, job_id, id, '
            . 'JSON_OBJECT(\'active\', active, \'retracted_at\', retracted_at, \'retraction_reason\', retraction_reason, \'deactivated_at\', deactivated_at), '
            . 'JSON_OBJECT(\'active\', 0, \'retraction_reason\', ?), '
            . '?, ?, ?, NOW() '
            . 'FROM clinical_document_facts '
            . 'WHERE document_id = ? AND active = 1',
            [
                $reason->value,
                'retract_fact',
                'system',
                $reason->value,
                $documentId->value,
            ],
        );
    }

    private function retractFacts(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'UPDATE clinical_document_facts '
            . 'SET active = 0, retracted_at = COALESCE(retracted_at, NOW()), retraction_reason = ?, deactivated_at = COALESCE(deactivated_at, NOW()) '
            . 'WHERE document_id = ? AND active = 1',
            [
                $reason->value,
                $documentId->value,
            ],
        );
    }

    private function auditEmbeddings(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, fact_id, prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT f.patient_id, f.document_id, f.job_id, f.id, '
            . 'JSON_OBJECT(\'embedding_active\', e.active, \'embedding_model\', e.embedding_model), '
            . 'JSON_OBJECT(\'embedding_active\', 0), '
            . '?, ?, ?, NOW() '
            . 'FROM clinical_document_fact_embeddings e '
            . 'INNER JOIN clinical_document_facts f ON f.id = e.fact_id '
            . 'WHERE f.document_id = ? AND e.active = 1',
            [
                'deactivate_embedding',
                'system',
                $reason->value,
                $documentId->value,
            ],
        );
    }

    private function deactivateEmbeddings(DocumentId $documentId): void
    {
        $this->executor->executeStatement(
            'UPDATE clinical_document_fact_embeddings e '
            . 'INNER JOIN clinical_document_facts f ON f.id = e.fact_id '
            . 'SET e.active = 0 '
            . 'WHERE f.document_id = ?',
            [$documentId->value],
        );
    }

    private function auditIdentityChecks(DocumentId $documentId, DocumentRetractionReason $reason): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_retractions '
            . '(patient_id, document_id, job_id, prior_state, new_state, action, actor_type, reason, created_at) '
            . 'SELECT j.patient_id, j.document_id, ic.job_id, '
            . 'JSON_OBJECT(\'identity_status\', ic.identity_status, \'extracted_identifiers_json\', ic.extracted_identifiers_json, '
            . '\'matched_patient_fields_json\', ic.matched_patient_fields_json), '
            . 'JSON_OBJECT(\'extracted_identifiers_json\', NULL, \'matched_patient_fields_json\', NULL), '
            . '?, ?, ?, NOW() '
            . 'FROM clinical_document_identity_checks ic '
            . 'INNER JOIN clinical_document_processing_jobs j ON j.id = ic.job_id '
            . 'WHERE j.document_id = ? AND ic.extracted_identifiers_json IS NOT NULL',
            [
                'scrub_identity_check',
                'system',
                $reason->value,
                $documentId->value,
            ],
        );
    }

    private function scrubIdentityChecks(DocumentId $documentId): void
    {
        $this->executor->executeStatement(
            'UPDATE clinical_document_identity_checks ic '
            . 'INNER JOIN clinical_document_processing_jobs j ON j.id = ic.job_id '
            . 'SET ic.extracted_identifiers_json = NULL, ic.matched_patient_fields_json = NULL '
            . 'WHERE j.document_id = ?',
            [$documentId->value],
        );
    }
}

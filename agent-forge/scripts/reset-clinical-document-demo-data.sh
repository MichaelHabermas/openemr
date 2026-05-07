#!/usr/bin/env bash
# Reset local Week 2 clinical-document demo upload state.
#
# Scope is intentionally narrow: this removes Margaret Chen's uploaded clinical
# documents, processing jobs, identity checks, and supervisor handoffs. It does
# not drop seeded patients, category mappings, guideline chunks, or embeddings.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
DB_SERVICE="${AGENTFORGE_DB_SERVICE:-mysql}"
DB_NAME="${AGENTFORGE_DB_NAME:-openemr}"
DB_USER="${AGENTFORGE_DB_USER:-openemr}"
DB_PASS="${AGENTFORGE_DB_PASS:-openemr}"
DEMO_PID="${AGENTFORGE_CHEN_PID:-900101}"
DRY_RUN="${AGENTFORGE_RESET_DRY_RUN:-0}"
RESTART_WORKER="${AGENTFORGE_RESET_RESTART_WORKER:-1}"

compose() {
    (cd "${REPO_DIR}/${COMPOSE_DIR}" && docker compose "$@")
}

mysql_exec() {
    compose exec -T "${DB_SERVICE}" mariadb \
        --user="${DB_USER}" \
        --password="${DB_PASS}" \
        "${DB_NAME}" "$@"
}

reset_sql() {
    local dry_run="$1"

    mysql_exec <<SQL
CREATE TEMPORARY TABLE tmp_agentforge_demo_documents AS
SELECT DISTINCT d.id
FROM documents d
LEFT JOIN categories_to_documents ctd ON ctd.document_id = d.id
LEFT JOIN categories c ON c.id = ctd.category_id
WHERE d.foreign_id = ${DEMO_PID}
  AND (
    c.name IN ('Lab Report', 'Intake Form')
    OR EXISTS (
      SELECT 1
      FROM clinical_document_processing_jobs j
      WHERE j.document_id = d.id
        AND j.patient_id = ${DEMO_PID}
    )
  );

CREATE TEMPORARY TABLE tmp_agentforge_demo_jobs AS
SELECT DISTINCT j.id
FROM clinical_document_processing_jobs j
WHERE j.patient_id = ${DEMO_PID}
   OR j.document_id IN (SELECT id FROM tmp_agentforge_demo_documents);

CREATE TEMPORARY TABLE tmp_agentforge_demo_promoted_results AS
SELECT DISTINCT CAST(p.promoted_record_id AS UNSIGNED) AS procedure_result_id
FROM clinical_document_promotions p
WHERE p.patient_id = ${DEMO_PID}
  AND p.promoted_table = 'procedure_result'
  AND p.promoted_record_id REGEXP '^[0-9]+$'
UNION
SELECT DISTINCT pr.procedure_result_id
FROM procedure_result pr
INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id
WHERE po.patient_id = ${DEMO_PID}
  AND (
    pr.document_id IN (SELECT id FROM tmp_agentforge_demo_documents)
    OR pr.comments LIKE 'agentforge-fact:%'
  );

CREATE TEMPORARY TABLE tmp_agentforge_demo_reports AS
SELECT DISTINCT pr.procedure_report_id
FROM procedure_result pr
WHERE pr.procedure_result_id IN (SELECT procedure_result_id FROM tmp_agentforge_demo_promoted_results);

CREATE TEMPORARY TABLE tmp_agentforge_demo_orders AS
SELECT DISTINCT rep.procedure_order_id
FROM procedure_report rep
WHERE rep.procedure_report_id IN (SELECT procedure_report_id FROM tmp_agentforge_demo_reports);

SELECT 'documents_to_reset' AS item, COUNT(*) AS count
FROM tmp_agentforge_demo_documents
UNION ALL
SELECT 'jobs_to_reset' AS item, COUNT(*) AS count
FROM tmp_agentforge_demo_jobs
UNION ALL
SELECT 'facts_to_reset' AS item, COUNT(*) AS count
FROM clinical_document_facts
WHERE patient_id = ${DEMO_PID}
   OR job_id IN (SELECT id FROM tmp_agentforge_demo_jobs)
   OR document_id IN (SELECT id FROM tmp_agentforge_demo_documents)
UNION ALL
SELECT 'identity_checks_to_reset' AS item, COUNT(*) AS count
FROM clinical_document_identity_checks
WHERE job_id IN (SELECT id FROM tmp_agentforge_demo_jobs)
   OR (
     patient_id = ${DEMO_PID}
     AND document_id IN (SELECT id FROM tmp_agentforge_demo_documents)
   )
UNION ALL
SELECT 'promotions_to_reset' AS item, COUNT(*) AS count
FROM clinical_document_promotions
WHERE patient_id = ${DEMO_PID}
   OR job_id IN (SELECT id FROM tmp_agentforge_demo_jobs)
   OR document_id IN (SELECT id FROM tmp_agentforge_demo_documents)
UNION ALL
SELECT 'promoted_lab_results_to_reset' AS item, COUNT(*) AS count
FROM tmp_agentforge_demo_promoted_results
UNION ALL
SELECT 'handoffs_to_reset' AS item, COUNT(*) AS count
FROM clinical_supervisor_handoffs
WHERE job_id IN (SELECT id FROM tmp_agentforge_demo_jobs);

$(if [[ "${dry_run}" == "1" ]]; then
    printf '%s\n' "SELECT 'dry_run' AS item, 'no rows deleted' AS count;"
else
    cat <<DELETE_SQL
DELETE FROM clinical_supervisor_handoffs
WHERE job_id IN (SELECT id FROM tmp_agentforge_demo_jobs);

DELETE FROM clinical_document_identity_checks
WHERE job_id IN (SELECT id FROM tmp_agentforge_demo_jobs)
   OR (
     patient_id = ${DEMO_PID}
     AND document_id IN (SELECT id FROM tmp_agentforge_demo_documents)
   );

DELETE FROM clinical_document_facts
WHERE patient_id = ${DEMO_PID}
   OR job_id IN (SELECT id FROM tmp_agentforge_demo_jobs)
   OR document_id IN (SELECT id FROM tmp_agentforge_demo_documents);

DELETE FROM clinical_document_promotions
WHERE patient_id = ${DEMO_PID}
   OR job_id IN (SELECT id FROM tmp_agentforge_demo_jobs)
   OR document_id IN (SELECT id FROM tmp_agentforge_demo_documents);

DELETE FROM procedure_result
WHERE procedure_result_id IN (SELECT procedure_result_id FROM tmp_agentforge_demo_promoted_results);

DELETE FROM procedure_report
WHERE procedure_report_id IN (SELECT procedure_report_id FROM tmp_agentforge_demo_reports)
  AND NOT EXISTS (
    SELECT 1
    FROM procedure_result pr
    WHERE pr.procedure_report_id = procedure_report.procedure_report_id
  );

DELETE FROM procedure_order_code
WHERE procedure_order_id IN (SELECT procedure_order_id FROM tmp_agentforge_demo_orders);

DELETE FROM procedure_order
WHERE procedure_order_id IN (SELECT procedure_order_id FROM tmp_agentforge_demo_orders)
  AND NOT EXISTS (
    SELECT 1
    FROM procedure_report rep
    WHERE rep.procedure_order_id = procedure_order.procedure_order_id
  );

DELETE FROM clinical_document_processing_jobs
WHERE id IN (SELECT id FROM tmp_agentforge_demo_jobs);

DELETE FROM categories_to_documents
WHERE document_id IN (SELECT id FROM tmp_agentforge_demo_documents);

DELETE FROM documents
WHERE id IN (SELECT id FROM tmp_agentforge_demo_documents);

SELECT 'reset_complete' AS item, 'rows deleted' AS count;
DELETE_SQL
fi)
SQL
}

main() {
    printf 'Resetting local clinical-document demo state for patient pid=%s.\n' "${DEMO_PID}"

    if [[ "${DRY_RUN}" == "1" ]]; then
        printf 'DRY RUN: no rows will be deleted.\n'
    fi

    reset_sql "${DRY_RUN}"

    if [[ "${DRY_RUN}" == "1" ]]; then
        printf 'PASS reset dry-run: reviewed local demo cleanup scope.\n'
        return 0
    fi

    if [[ "${RESTART_WORKER}" == "1" ]]; then
        compose restart agentforge-worker
    fi

    printf 'PASS reset: clinical-document demo upload state cleared for pid=%s.\n' "${DEMO_PID}"
    printf 'NOTE: guideline chunks and embeddings were intentionally left intact.\n'
}

main "$@"

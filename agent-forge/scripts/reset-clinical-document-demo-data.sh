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

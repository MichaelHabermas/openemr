#!/usr/bin/env bash
# Verify AgentForge fake demo patient data exists after seeding.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
DB_SERVICE="${AGENTFORGE_DB_SERVICE:-mysql}"
DB_NAME="${AGENTFORGE_DB_NAME:-openemr}"
DB_USER="${AGENTFORGE_DB_USER:-root}"
DB_PASS="${AGENTFORGE_DB_PASS:-root}"
DEMO_PID="${AGENTFORGE_DEMO_PID:-900001}"
FAILURES=0

compose() {
    (cd "${REPO_DIR}/${COMPOSE_DIR}" && docker compose "$@")
}

mysql_scalar() {
    compose exec -T "${DB_SERVICE}" mariadb \
        --batch \
        --skip-column-names \
        --user="${DB_USER}" \
        --password="${DB_PASS}" \
        "${DB_NAME}" \
        --execute="$1"
}

expect_count() {
    local label="$1"
    local query="$2"
    local expected="$3"
    local actual

    actual="$(mysql_scalar "${query}")"

    if [[ "${actual}" == "${expected}" ]]; then
        printf 'PASS %s: %s\n' "${label}" "${actual}"
        return 0
    fi

    printf 'FAIL %s: expected %s, got %s\n' "${label}" "${expected}" "${actual:-empty}" >&2
    FAILURES=$((FAILURES + 1))
}

main() {
    expect_count \
        "demographics" \
        "SELECT COUNT(*) FROM patient_data WHERE pid = ${DEMO_PID} AND pubpid = 'AF-DEMO-900001' AND fname = 'Alex' AND lname = 'Testpatient' AND DOB = '1976-04-12' AND sex = 'Female';" \
        "1"

    expect_count \
        "active problems" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${DEMO_PID} AND type = 'medical_problem' AND activity = 1 AND external_id IN ('af-prob-diabetes', 'af-prob-htn');" \
        "2"

    expect_count \
        "active medications" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${DEMO_PID} AND active = 1 AND external_id IN ('af-rx-metformin', 'af-rx-lisinopril');" \
        "2"

    expect_count \
        "recent encounter" \
        "SELECT COUNT(*) FROM form_encounter WHERE pid = ${DEMO_PID} AND encounter = 900415 AND reason LIKE '%diabetes and blood pressure%';" \
        "1"

    expect_count \
        "last plan note" \
        "SELECT COUNT(*) FROM form_clinical_notes WHERE pid = ${DEMO_PID} AND external_id = 'af-note-20260415' AND description LIKE '%Continue metformin ER and lisinopril%' AND description LIKE '%Recheck A1c in 3 months%';" \
        "1"

    expect_count \
        "a1c lab trend" \
        "SELECT COUNT(*) FROM procedure_result pr INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id WHERE po.patient_id = ${DEMO_PID} AND pr.result_text = 'Hemoglobin A1c' AND ((DATE(pr.date) = '2026-01-09' AND pr.result = '8.2') OR (DATE(pr.date) = '2026-04-10' AND pr.result = '7.4'));" \
        "2"

    expect_count \
        "known missing microalbumin" \
        "SELECT COUNT(*) FROM procedure_result pr INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id WHERE po.patient_id = ${DEMO_PID} AND (LOWER(pr.result_text) LIKE '%microalbumin%' OR LOWER(pr.result_code) LIKE '%microalbumin%');" \
        "0"

    expect_count \
        "encounter linked into forms" \
        "SELECT COUNT(*) FROM forms WHERE pid = ${DEMO_PID} AND encounter = 900415 AND form_name = 'Clinical Notes' AND formdir = 'clinical_notes' AND deleted = 0;" \
        "1"

    expect_count \
        "clinical note linked to forms row" \
        "SELECT COUNT(*) FROM forms f INNER JOIN form_clinical_notes fcn ON fcn.form_id = f.id WHERE f.pid = ${DEMO_PID} AND fcn.external_id = 'af-note-20260415' AND f.deleted = 0;" \
        "1"

    expect_count \
        "a1c result chain (order to report to result)" \
        "SELECT COUNT(*) FROM procedure_result pr INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id INNER JOIN procedure_order_code poc ON poc.procedure_order_id = rep.procedure_order_id INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id WHERE po.patient_id = ${DEMO_PID} AND poc.procedure_code = '4548-4' AND pr.result_text = 'Hemoglobin A1c';" \
        "2"

    expect_count \
        "evidence contract demographics source row" \
        "SELECT COUNT(*) FROM patient_data WHERE pid = ${DEMO_PID} AND DATE(date) = '2026-04-15' AND CONCAT(fname, ' ', lname) = 'Alex Testpatient';" \
        "1"

    expect_count \
        "evidence contract problem source rows" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${DEMO_PID} AND type = 'medical_problem' AND activity = 1 AND external_id <> '' AND title <> '' AND begdate IS NOT NULL;" \
        "2"

    expect_count \
        "evidence contract prescription source rows" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${DEMO_PID} AND active = 1 AND external_id <> '' AND drug <> '' AND start_date IS NOT NULL;" \
        "2"

    expect_count \
        "evidence contract lab source rows" \
        "SELECT COUNT(*) FROM procedure_result pr INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id WHERE po.patient_id = ${DEMO_PID} AND pr.comments <> '' AND pr.result_text <> '' AND pr.date IS NOT NULL AND pr.result <> '';" \
        "2"

    expect_count \
        "evidence contract last-plan source row" \
        "SELECT COUNT(*) FROM form_clinical_notes WHERE pid = ${DEMO_PID} AND activity = 1 AND authorized = 1 AND external_id = 'af-note-20260415' AND date IS NOT NULL AND codetext <> '' AND description <> '';" \
        "1"

    expect_count \
        "no contradicting metformin titration" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${DEMO_PID} AND drug LIKE 'Metformin%' AND active = 1 AND dosage <> '500 mg';" \
        "0"

    if [[ "${FAILURES}" -gt 0 ]]; then
        printf 'Demo data verification failed: %s check(s) failed.\n' "${FAILURES}" >&2
        exit 1
    fi

    printf 'PASS verify: all AgentForge demo data checks passed.\n'
}

main "$@"

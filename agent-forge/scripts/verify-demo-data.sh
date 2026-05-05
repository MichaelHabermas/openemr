#!/usr/bin/env bash
# Verify AgentForge fake demo patient data exists after seeding.
#
# Default: queries via `docker compose exec` (local Docker dev stack).
# CI / host DB: set AGENTFORGE_VERIFY_TRANSPORT=direct and DB host/user/pass/name
# (see .github/workflows/agentforge-evals.yml Tier 1).
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
VERIFY_TRANSPORT="${AGENTFORGE_VERIFY_TRANSPORT:-compose}"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
DB_SERVICE="${AGENTFORGE_DB_SERVICE:-mysql}"
DB_HOST="${AGENTFORGE_DB_HOST:-127.0.0.1}"
DB_PORT="${AGENTFORGE_DB_PORT:-3306}"
DB_NAME="${AGENTFORGE_DB_NAME:-openemr}"
DB_USER="${AGENTFORGE_DB_USER:-root}"
DB_PASS="${AGENTFORGE_DB_PASS:-root}"
DEMO_PID="${AGENTFORGE_DEMO_PID:-900001}"
POLY_PID="${AGENTFORGE_POLY_PID:-900002}"
SPARSE_PID="${AGENTFORGE_SPARSE_PID:-900003}"
FAILURES=0

compose() {
    (cd "${REPO_DIR}/${COMPOSE_DIR}" && docker compose "$@")
}

mysql_scalar() {
    local sql="$1"

    if [[ "${VERIFY_TRANSPORT}" == "direct" ]]; then
        MYSQL_PWD="${DB_PASS}" mysql \
            --batch \
            --skip-column-names \
            -h "${DB_HOST}" \
            -P "${DB_PORT}" \
            -u "${DB_USER}" \
            "${DB_NAME}" \
            --execute="${sql}"
        return 0
    fi

    compose exec -T "${DB_SERVICE}" mariadb \
        --batch \
        --skip-column-names \
        --user="${DB_USER}" \
        --password="${DB_PASS}" \
        "${DB_NAME}" \
        --execute="${sql}"
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
        "active allergies" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${DEMO_PID} AND type = 'allergy' AND activity = 1 AND external_id IN ('af-al-penicillin', 'af-al-shellfish') AND reaction <> '' AND severity_al <> '' AND verification <> '';" \
        "2"

    expect_count \
        "recent vitals" \
        "SELECT COUNT(*) FROM form_vitals WHERE pid = ${DEMO_PID} AND activity = 1 AND authorized = 1 AND external_id = 'af-vitals-20260415' AND bps = '142' AND bpd = '88' AND pulse = 84.000000 AND oxygen_saturation = 98.00;" \
        "1"

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
        "evidence contract allergy source rows" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${DEMO_PID} AND type = 'allergy' AND activity = 1 AND external_id <> '' AND title <> '' AND begdate IS NOT NULL;" \
        "2"

    expect_count \
        "evidence contract lab source rows" \
        "SELECT COUNT(*) FROM procedure_result pr INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id WHERE po.patient_id = ${DEMO_PID} AND pr.comments <> '' AND pr.result_text <> '' AND pr.date IS NOT NULL AND pr.result <> '';" \
        "2"

    expect_count \
        "evidence contract vitals source rows" \
        "SELECT COUNT(*) FROM form_vitals WHERE pid = ${DEMO_PID} AND activity = 1 AND authorized = 1 AND external_id <> '' AND date IS NOT NULL AND bps <> '' AND bpd <> '';" \
        "1"

    expect_count \
        "evidence contract last-plan source row" \
        "SELECT COUNT(*) FROM form_clinical_notes WHERE pid = ${DEMO_PID} AND activity = 1 AND authorized = 1 AND external_id = 'af-note-20260415' AND date IS NOT NULL AND codetext <> '' AND description <> '';" \
        "1"

    expect_count \
        "no contradicting metformin titration" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${DEMO_PID} AND drug LIKE 'Metformin%' AND active = 1 AND dosage <> '500 mg';" \
        "0"

    expect_count \
        "polypharmacy demographics" \
        "SELECT COUNT(*) FROM patient_data WHERE pid = ${POLY_PID} AND pubpid = 'AF-DEMO-900002' AND fname = 'Riley' AND lname = 'Medmix' AND DOB = '1954-02-20' AND sex = 'Male';" \
        "1"

    expect_count \
        "polypharmacy active problems" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${POLY_PID} AND type = 'medical_problem' AND activity = 1 AND external_id IN ('af-p900002-afib', 'af-p900002-dm');" \
        "2"

    expect_count \
        "polypharmacy active prescription rows" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${POLY_PID} AND active = 1 AND external_id IN ('af-rx-p2-apixaban', 'af-rx-p2-metformin');" \
        "2"

    expect_count \
        "polypharmacy inactive stale rows retained" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${POLY_PID} AND active = 0 AND external_id IN ('af-rx-p2-warfarin', 'af-rx-p2-simvast');" \
        "2"

    expect_count \
        "polypharmacy stale row older than active window" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${POLY_PID} AND external_id = 'af-rx-p2-simvast' AND active = 0 AND start_date < '2024-01-01';" \
        "1"

    expect_count \
        "polypharmacy duplicate list medication row" \
        "SELECT COUNT(*) FROM lists l INNER JOIN lists_medication lm ON lm.list_id = l.id WHERE l.pid = ${POLY_PID} AND l.type = 'medication' AND l.activity = 1 AND l.external_id = 'af-l900002-metdup' AND l.title = 'Metformin ER 500 mg' AND lm.id = 90000203;" \
        "1"

    expect_count \
        "polypharmacy active allergy row" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${POLY_PID} AND type = 'allergy' AND activity = 1 AND external_id = 'af-al-p2-sulfa' AND title = 'Sulfonamide antibiotics' AND reaction = 'hives';" \
        "1"

    expect_count \
        "polypharmacy inactive allergy row retained" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${POLY_PID} AND type = 'allergy' AND activity = 0 AND external_id = 'af-al-p2-warfarin';" \
        "1"

    expect_count \
        "polypharmacy active medication evidence excludes inactive stale rows" \
        "SELECT COUNT(*) FROM prescriptions WHERE patient_id = ${POLY_PID} AND active = 1 AND external_id IN ('af-rx-p2-warfarin', 'af-rx-p2-simvast');" \
        "0"

    expect_count \
        "polypharmacy active allergy evidence excludes inactive rows" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${POLY_PID} AND type = 'allergy' AND activity = 1 AND external_id = 'af-al-p2-warfarin';" \
        "0"

    expect_count \
        "polypharmacy lab context" \
        "SELECT COUNT(*) FROM procedure_result pr INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id WHERE po.patient_id = ${POLY_PID} AND pr.result_text = 'Estimated GFR' AND DATE(pr.date) = '2026-05-10' AND pr.result = '68' AND pr.comments = 'agentforge-egfr-900002-2026-05';" \
        "1"

    expect_count \
        "polypharmacy medication reconciliation note" \
        "SELECT COUNT(*) FROM form_clinical_notes WHERE pid = ${POLY_PID} AND activity = 1 AND authorized = 1 AND external_id = 'af-note-900002-med-recon' AND description LIKE '%Warfarin is documented as stopped%' AND description LIKE '%Duplicate metformin row%';" \
        "1"

    expect_count \
        "sparse demographics" \
        "SELECT COUNT(*) FROM patient_data WHERE pid = ${SPARSE_PID} AND pubpid = 'AF-DEMO-900003' AND fname = 'Jordan' AND lname = 'Sparsechart' AND DOB = '1988-11-03' AND sex = 'Female';" \
        "1"

    expect_count \
        "sparse present problem" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${SPARSE_PID} AND type = 'medical_problem' AND activity = 1 AND external_id = 'af-p900003-rh' AND title = 'Seasonal allergic rhinitis';" \
        "1"

    expect_count \
        "sparse encounter present" \
        "SELECT COUNT(*) FROM form_encounter WHERE pid = ${SPARSE_PID} AND encounter = 900617 AND reason LIKE '%Sparse chart orientation%';" \
        "1"

    expect_count \
        "sparse labs absent" \
        "SELECT COUNT(*) FROM procedure_result pr INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id WHERE po.patient_id = ${SPARSE_PID};" \
        "0"

    expect_count \
        "sparse active allergies absent" \
        "SELECT COUNT(*) FROM lists WHERE pid = ${SPARSE_PID} AND type = 'allergy' AND activity = 1;" \
        "0"

    expect_count \
        "sparse stale vitals retained" \
        "SELECT COUNT(*) FROM form_vitals WHERE pid = ${SPARSE_PID} AND activity = 1 AND authorized = 1 AND external_id = 'af-vit-900003-stale' AND date < DATE_SUB(CURRENT_DATE, INTERVAL 180 DAY);" \
        "1"

    expect_count \
        "sparse recent vitals absent" \
        "SELECT COUNT(*) FROM form_vitals WHERE pid = ${SPARSE_PID} AND activity = 1 AND authorized = 1 AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 180 DAY);" \
        "0"

    expect_count \
        "sparse notes absent" \
        "SELECT COUNT(*) FROM form_clinical_notes WHERE pid = ${SPARSE_PID};" \
        "0"

    expect_count \
        "sparse forbidden note source absent" \
        "SELECT COUNT(*) FROM form_clinical_notes WHERE external_id = 'af-note-900003';" \
        "0"

    if [[ "${FAILURES}" -gt 0 ]]; then
        printf 'Demo data verification failed: %s check(s) failed.\n' "${FAILURES}" >&2
        exit 1
    fi

    printf 'PASS verify: all AgentForge demo data checks passed.\n'
}

main "$@"

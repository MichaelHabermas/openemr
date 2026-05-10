#!/usr/bin/env bash
# Run the comprehensive AgentForge quality gate.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

CLINICAL_DOCUMENT_GATE_EXPECTATION="${CLINICAL_DOCUMENT_GATE_EXPECTATION:-pass}"

cd "${REPO_DIR}"

run_step() {
    local label="$1"
    shift

    printf '\n==> %s\n' "${label}"
    "$@"
}

host_php_has_phpunit_extensions() {
    php -r '
        $required = ["dom", "json", "libxml", "mbstring", "tokenizer", "xml", "xmlwriter"];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                exit(1);
            }
        }
        exit(0);
    ' 2>/dev/null
}

run_clinical_document_phpunit() {
    local compose_file="${REPO_DIR}/docker/development-easy/docker-compose.yml"
    local filter='OpenEMR\\Tests\\Isolated\\AgentForge\\Eval\\ClinicalDocument'

    if host_php_has_phpunit_extensions; then
        composer phpunit-isolated -- --filter "${filter}"
        return
    fi

    if [[ ! -f "${compose_file}" ]]; then
        printf 'Host PHP is missing extensions required by PHPUnit (dom, xml, xmlwriter, etc.).\n' >&2
        printf 'No %s found; cannot run in container.\n' "${compose_file}" >&2
        return 1
    fi

    if ! docker compose -f "${compose_file}" ps --status running -q openemr 2>/dev/null | grep -q .; then
        printf 'Host PHP is missing extensions and openemr container is not running.\n' >&2
        return 1
    fi

    printf 'Host PHP lacks PHPUnit extensions; running clinical document PHPUnit in the openemr container.\n'
    docker compose -f "${compose_file}" exec -T -w /var/www/localhost/htdocs/openemr openemr \
        bash -lc 'git config --global --add safe.directory "$PWD" 2>/dev/null || true; composer phpunit-isolated -- --filter '"'"'OpenEMR\\Tests\\Isolated\\AgentForge\\Eval\\ClinicalDocument'"'"''
}

run_clinical_document_gate() {
    local output_file
    local exit_code

    output_file="$(mktemp -t agentforge-clinical-document-gate.XXXXXX)"
    exit_code=0

    bash agent-forge/scripts/check-clinical-document.sh > "${output_file}" 2>&1 || exit_code=$?
    cat "${output_file}"

    if [[ "${CLINICAL_DOCUMENT_GATE_EXPECTATION}" == "pass" ]]; then
        if [[ "${exit_code}" -eq 0 ]]; then
            printf '\nPASS clinical document gate.\n'
            rm -f "${output_file}"
            return 0
        fi

        printf '\nFAIL clinical document gate: expected exit 0, got exit %s.\n' "${exit_code}" >&2
        rm -f "${output_file}"
        return "${exit_code}"
    fi

    if [[ "${CLINICAL_DOCUMENT_GATE_EXPECTATION}" == "threshold_violation" ]]; then
        if [[ "${exit_code}" -eq 2 ]] && grep -q 'Clinical document eval verdict: threshold_violation' "${output_file}"; then
            printf '\nEXPECTED FAIL clinical document gate: threshold_violation.\n'
            rm -f "${output_file}"
            return 0
        fi

        printf '\nFAIL clinical document gate: expected threshold_violation exit 2, got exit %s.\n' "${exit_code}" >&2
        rm -f "${output_file}"
        return 1
    fi

    printf 'Unknown CLINICAL_DOCUMENT_GATE_EXPECTATION: %s\n' "${CLINICAL_DOCUMENT_GATE_EXPECTATION}" >&2
    printf 'Allowed values: threshold_violation, pass\n' >&2
    rm -f "${output_file}"
    return 1
}

run_step "Run baseline AgentForge gate" \
    bash agent-forge/scripts/check-local.sh

run_step "Run clinical document harness self-tests" \
    run_clinical_document_phpunit

run_step "Run clinical document gate (${CLINICAL_DOCUMENT_GATE_EXPECTATION})" \
    run_clinical_document_gate

printf '\nPASS comprehensive AgentForge check.\n'

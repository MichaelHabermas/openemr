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
    composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge\\Eval\\ClinicalDocument'

run_step "Run clinical document gate (${CLINICAL_DOCUMENT_GATE_EXPECTATION})" \
    run_clinical_document_gate

printf '\nPASS comprehensive AgentForge check.\n'

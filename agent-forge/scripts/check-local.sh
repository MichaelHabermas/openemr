#!/usr/bin/env bash
# Run the focused local AgentForge quality gate.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${REPO_DIR}"

run_step() {
    local label="$1"
    shift

    printf '\n==> %s\n' "${label}"
    "$@"
}

phpcs_changed_agentforge_files() {
    local files

    files="$(
        {
            git diff --name-only --diff-filter=ACM
            git ls-files --others --exclude-standard
        } | grep -E '^(src/AgentForge|tests/Tests/Isolated/AgentForge|interface/patient_file/summary/agent_request.php)' || true
    )"

    if [[ -z "${files}" ]]; then
        printf 'No changed AgentForge PHP files to check with PHPCS.\n'
        return 0
    fi

    printf '%s\n' "${files}" | xargs vendor/bin/phpcs
}

run_step "Check diff whitespace" git diff --check

run_step "Check PHP syntax" bash -c \
    "php -l interface/patient_file/summary/agent_request.php \
    && find src/AgentForge tests/Tests/Isolated/AgentForge -name '*.php' -print0 | xargs -0 -n 1 php -l \
    && php -l agent-forge/scripts/run-evals.php"

run_step "Check AgentForge shell script syntax" bash -c \
    "find agent-forge/scripts -name '*.sh' -print0 | xargs -0 -n 1 bash -n"

run_step "Run AgentForge isolated PHPUnit" \
    composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'

run_step "Run AgentForge deterministic evals" \
    php agent-forge/scripts/run-evals.php

run_step "Run focused AgentForge PHPStan" \
    composer phpstan -- --error-format=raw \
        src/AgentForge \
        interface/patient_file/summary/agent_request.php \
        tests/Tests/Isolated/AgentForge

run_step "Run PHPCS on changed AgentForge PHP files" phpcs_changed_agentforge_files

printf '\nPASS local AgentForge check.\n'

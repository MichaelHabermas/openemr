#!/usr/bin/env bash
# Run the focused local AgentForge quality gate.
#
# CI parity note:
# - .github/workflows/agentforge-evals.yml Tier 0 runs `php agent-forge/scripts/run-evals.php` only.
# - This script adds syntax/whitespace checks, AgentForge-filtered phpunit-isolated, PHPStan, and PHPCS.
# - .github/workflows/isolated-tests.yml runs the full repo isolated matrix (not invoked here).
# - Use agent-forge/scripts/check-agentforge.sh for the comprehensive gate (includes clinical document expectation).
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

run_agentforge_isolated_phpunit() {
    local compose_file="${REPO_DIR}/docker/development-easy/docker-compose.yml"

    if host_php_has_phpunit_extensions; then
        composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'
        return
    fi

    if [[ ! -f "${compose_file}" ]]; then
        printf 'Host PHP is missing extensions required by PHPUnit (dom, xml, xmlwriter, etc.).\n' >&2
        printf 'No %s found; install PHP dev extensions on this host or run checks from a full dev environment.\n' "${compose_file}" >&2
        return 1
    fi

    if ! docker compose -f "${compose_file}" ps --status running -q openemr 2>/dev/null | grep -q .; then
        printf 'Host PHP is missing extensions required by PHPUnit, and the openemr compose service is not running.\n' >&2
        printf 'Either: apt-get install -y php-xml php-mbstring (or your OS equivalent), or start the stack:\n' >&2
        printf '  cd docker/development-easy && docker compose up -d openemr\n' >&2
        printf 'Then re-run %s (PHPUnit will run inside the container automatically).\n' "${BASH_SOURCE[0]}" >&2
        return 1
    fi

    printf 'Host PHP lacks PHPUnit extensions; running isolated PHPUnit in the openemr container.\n'
    docker compose -f "${compose_file}" exec -T -w /var/www/localhost/htdocs/openemr openemr \
        bash -lc 'git config --global --add safe.directory "$PWD" 2>/dev/null || true; composer phpunit-isolated -- --filter '"'"'OpenEMR\\Tests\\Isolated\\AgentForge'"'"''
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

run_step "Run AgentForge isolated PHPUnit" run_agentforge_isolated_phpunit

run_step "Run AgentForge deterministic evals" \
    php agent-forge/scripts/run-evals.php

run_step "Run focused AgentForge PHPStan" \
    env COMPOSER_PROCESS_TIMEOUT=900 composer phpstan -- --error-format=raw \
        src/AgentForge \
        interface/patient_file/summary/agent_request.php \
        tests/Tests/Isolated/AgentForge

run_step "Run PHPCS on changed AgentForge PHP files" phpcs_changed_agentforge_files

printf '\nPASS local AgentForge check.\n'

#!/usr/bin/env bash
# Run all deployed AgentForge verification checks in sequence.
#
# Steps:
#   1. Runtime health check (health-check.sh)
#   2. Demo data verification (verify-demo-data.sh)
#   3. Clinical document deployed smoke (run-clinical-document-deployed-smoke.php)
#
# Env vars are loaded from docker/development-easy/.env (or AGENTFORGE_COMPOSE_DIR/.env)
# unless already set in the environment.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

load_compose_env() {
    local compose_dir="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
    local env_file

    if [[ "${compose_dir}" == /* ]]; then
        env_file="${compose_dir}/.env"
    else
        env_file="${REPO_DIR}/${compose_dir}/.env"
    fi

    if [[ -f "${env_file}" ]]; then
        set -a
        # shellcheck source=/dev/null
        source "${env_file}"
        set +a
    fi
}

run_step() {
    local label="$1"
    shift

    printf '\n━━━ %s ━━━\n' "${label}"
    "$@"
}

load_compose_env

FAILURES=0

run_step "Runtime health check" \
    bash "${SCRIPT_DIR}/health-check.sh" || FAILURES=$((FAILURES + 1))

run_step "Demo data verification" \
    bash "${SCRIPT_DIR}/verify-demo-data.sh" || FAILURES=$((FAILURES + 1))

run_step "Clinical document deployed smoke" \
    php "${SCRIPT_DIR}/run-clinical-document-deployed-smoke.php" || FAILURES=$((FAILURES + 1))

printf '\n'
if [[ "${FAILURES}" -gt 0 ]]; then
    printf 'Deployed verification failed: %s step(s) failed.\n' "${FAILURES}" >&2
    exit 1
fi

printf 'All deployed verification checks passed.\n'

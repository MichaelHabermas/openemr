#!/usr/bin/env bash
set -Eeuo pipefail

REPO_DIR="${AGENTFORGE_REPO_DIR:-${HOME}/repos/openemr}"
COMPOSE_FILE="${AGENTFORGE_COMPOSE_FILE:-docker/development-easy/docker-compose.yml}"
APP_URL="${AGENTFORGE_APP_URL:-https://openemr.titleredacted.cc/}"
READYZ_URL="${AGENTFORGE_READYZ_URL:-https://openemr.titleredacted.cc/meta/health/readyz}"
HEALTH_TIMEOUT_SECONDS="${AGENTFORGE_HEALTH_TIMEOUT_SECONDS:-180}"
HEALTH_INTERVAL_SECONDS="${AGENTFORGE_HEALTH_INTERVAL_SECONDS:-5}"

require_verified_deploy_facts() {
    local missing=()

    [[ -n "${AGENTFORGE_DEPLOY_BRANCH:-}" ]] || missing+=("AGENTFORGE_DEPLOY_BRANCH")
    [[ -n "${AGENTFORGE_GIT_REMOTE:-}" ]] || missing+=("AGENTFORGE_GIT_REMOTE")
    [[ -n "${AGENTFORGE_COMPOSE_COMMAND:-}" ]] || missing+=("AGENTFORGE_COMPOSE_COMMAND")
    [[ "${AGENTFORGE_DOCKER_PERMISSION_VERIFIED:-}" == "yes" ]] || missing+=("AGENTFORGE_DOCKER_PERMISSION_VERIFIED=yes")
    [[ "${AGENTFORGE_VOLUME_PRESERVATION_VERIFIED:-}" == "yes" ]] || missing+=("AGENTFORGE_VOLUME_PRESERVATION_VERIFIED=yes")

    if [[ "${#missing[@]}" -gt 0 ]]; then
        printf 'Refusing deploy. Verify VM deployment facts first:\n' >&2
        printf -- '- %s\n' "${missing[@]}" >&2
        printf 'See agent-forge/docs/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md.\n' >&2
        exit 2
    fi
}

compose() {
    # shellcheck disable=SC2086
    ${AGENTFORGE_COMPOSE_COMMAND} -f "${COMPOSE_FILE}" "$@"
}

check_url() {
    local label="$1"
    local url="$2"
    local status

    set +e
    status="$(
        curl \
            --silent \
            --show-error \
            --location \
            --output /dev/null \
            --write-out '%{http_code}' \
            --connect-timeout 10 \
            --max-time 20 \
            "${url}"
    )"
    local curl_exit=$?
    set -e

    if [[ "${curl_exit}" -eq 0 && "${status}" =~ ^[23][0-9][0-9]$ ]]; then
        printf 'PASS %s: HTTP %s\n' "${label}" "${status}"
        return 0
    fi

    printf 'WAIT %s: curl exit %s, HTTP %s\n' "${label}" "${curl_exit}" "${status:-000}"
    return 1
}

wait_for_health() {
    local elapsed=0

    until check_url "public app" "${APP_URL}" && check_url "readiness endpoint" "${READYZ_URL}"; do
        if [[ "${elapsed}" -ge "${HEALTH_TIMEOUT_SECONDS}" ]]; then
            printf 'Deploy failed: health checks did not pass within %s seconds.\n' "${HEALTH_TIMEOUT_SECONDS}" >&2
            return 1
        fi

        sleep "${HEALTH_INTERVAL_SECONDS}"
        elapsed=$((elapsed + HEALTH_INTERVAL_SECONDS))
    done
}

main() {
    require_verified_deploy_facts

    cd "${REPO_DIR}"

    local current_branch
    local old_commit
    local new_commit

    current_branch="$(git rev-parse --abbrev-ref HEAD)"
    old_commit="$(git rev-parse HEAD)"

    printf 'Deploy branch: %s\n' "${current_branch}"
    printf 'Rollback commit: %s\n' "${old_commit}"

    if [[ "${current_branch}" != "${AGENTFORGE_DEPLOY_BRANCH}" ]]; then
        printf 'Refusing deploy: current branch %s does not match verified branch %s.\n' \
            "${current_branch}" \
            "${AGENTFORGE_DEPLOY_BRANCH}" >&2
        exit 3
    fi

    git fetch "${AGENTFORGE_GIT_REMOTE}" "${AGENTFORGE_DEPLOY_BRANCH}"
    git pull --ff-only "${AGENTFORGE_GIT_REMOTE}" "${AGENTFORGE_DEPLOY_BRANCH}"
    new_commit="$(git rev-parse HEAD)"

    printf 'New commit: %s\n' "${new_commit}"
    printf 'Compose file: %s\n' "${COMPOSE_FILE}"

    compose down
    compose up --detach --wait

    wait_for_health

    printf 'Deploy succeeded.\n'
    printf 'Rollback target: %s\n' "${old_commit}"
}

main "$@"

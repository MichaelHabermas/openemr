#!/usr/bin/env bash
# Demo deploy: pulls latest code, recreates the stack, runs health checks, and
# re-seeds the idempotent fake demo data. Volumes are preserved across deploys
# because the upstream MariaDB image's first-init is fragile on the demo VM;
# the seed script alone is sufficient to restore known demo state.
# See agent-forge/docs/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md.
set -Eeuo pipefail

REPO_DIR="${AGENTFORGE_REPO_DIR:-${HOME}/repos/openemr}"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
APP_URL="${AGENTFORGE_APP_URL:-https://openemr.titleredacted.cc/}"
READYZ_URL="${AGENTFORGE_READYZ_URL:-https://openemr.titleredacted.cc/meta/health/readyz}"
HEALTH_TIMEOUT_SECONDS="${AGENTFORGE_HEALTH_TIMEOUT_SECONDS:-300}"
HEALTH_INTERVAL_SECONDS="${AGENTFORGE_HEALTH_INTERVAL_SECONDS:-5}"
SEED_SCRIPT="${AGENTFORGE_SEED_SCRIPT:-agent-forge/scripts/seed-demo-data.sh}"
DEPLOY_BRANCH="${AGENTFORGE_DEPLOY_BRANCH:-master}"
DRAFT_PROVIDER="${AGENTFORGE_DRAFT_PROVIDER:-openai}"

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

    until check_url "public app" "${APP_URL}"; do
        if [[ "${elapsed}" -ge "${HEALTH_TIMEOUT_SECONDS}" ]]; then
            printf 'Deploy failed: app did not become healthy within %s seconds.\n' "${HEALTH_TIMEOUT_SECONDS}" >&2
            return 1
        fi
        sleep "${HEALTH_INTERVAL_SECONDS}"
        elapsed=$((elapsed + HEALTH_INTERVAL_SECONDS))
    done

    # Readiness endpoint is informational; do not block deploy on it.
    check_url "readiness endpoint" "${READYZ_URL}" || true
}

verify_agentforge_model_config() {
    if [[ "${DRAFT_PROVIDER}" == "openai" && -z "${AGENTFORGE_OPENAI_API_KEY:-${OPENAI_API_KEY:-}}" ]]; then
        printf 'Deploy failed: AGENTFORGE_DRAFT_PROVIDER=openai requires AGENTFORGE_OPENAI_API_KEY or OPENAI_API_KEY.\n' >&2
        printf 'Set the key in the shell running deploy-vm.sh or in docker/development-easy/.env on the VM.\n' >&2
        return 1
    fi
}

main() {
    cd "${REPO_DIR}"

    local current_branch old_commit new_commit
    current_branch="$(git rev-parse --abbrev-ref HEAD)"
    old_commit="$(git rev-parse HEAD)"

    printf 'Repo: %s\n' "${REPO_DIR}"
    printf 'Branch: %s\n' "${current_branch}"
    printf 'Old commit: %s\n' "${old_commit}"
    verify_agentforge_model_config

    # Reattach to the deploy branch if a prior rollback left HEAD detached.
    if [[ "${current_branch}" != "${DEPLOY_BRANCH}" ]]; then
        printf 'Switching to deploy branch: %s\n' "${DEPLOY_BRANCH}"
        git switch "${DEPLOY_BRANCH}"
    fi

    # Pull first so a merge/network failure doesn't take the app offline.
    git pull --ff-only

    new_commit="$(git rev-parse HEAD)"
    printf 'New commit: %s\n' "${new_commit}"

    cd "${COMPOSE_DIR}"
    printf 'Compose dir: %s\n' "${COMPOSE_DIR}"

    # Volumes are preserved (no -v): the upstream MariaDB image's first-init
    # is fragile on the demo VM; the idempotent seed script below is what
    # guarantees known demo state.
    docker compose down
    docker compose up -d

    wait_for_health

    if [[ -x "${REPO_DIR}/${SEED_SCRIPT}" ]]; then
        printf 'Seeding fake demo data: %s\n' "${SEED_SCRIPT}"
        "${REPO_DIR}/${SEED_SCRIPT}"
    else
        printf 'NOTE: seed script %s not present or not executable. Skipping seed.\n' "${SEED_SCRIPT}"
        printf 'Fake demo data must be loaded manually before recording the demo.\n'
    fi

    printf 'Deploy succeeded.\n'
    printf 'Rollback target: %s\n' "${old_commit}"
}

main "$@"

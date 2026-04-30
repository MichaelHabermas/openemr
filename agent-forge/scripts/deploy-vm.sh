#!/usr/bin/env bash
# Demo deploy: pulls latest code, resets the stack and DB volumes, runs health
# checks. Data is NOT preserved by design — fake demo data must be re-seeded
# after deploy. See agent-forge/docs/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md.
set -Eeuo pipefail

REPO_DIR="${AGENTFORGE_REPO_DIR:-${HOME}/repos/openemr}"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
APP_URL="${AGENTFORGE_APP_URL:-https://openemr.titleredacted.cc/}"
READYZ_URL="${AGENTFORGE_READYZ_URL:-https://openemr.titleredacted.cc/meta/health/readyz}"
HEALTH_TIMEOUT_SECONDS="${AGENTFORGE_HEALTH_TIMEOUT_SECONDS:-300}"
HEALTH_INTERVAL_SECONDS="${AGENTFORGE_HEALTH_INTERVAL_SECONDS:-5}"
SEED_SCRIPT="${AGENTFORGE_SEED_SCRIPT:-agent-forge/scripts/seed-demo-data.sh}"

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

main() {
    cd "${REPO_DIR}"

    local current_branch old_commit new_commit
    current_branch="$(git rev-parse --abbrev-ref HEAD)"
    old_commit="$(git rev-parse HEAD)"

    printf 'Repo: %s\n' "${REPO_DIR}"
    printf 'Branch: %s\n' "${current_branch}"
    printf 'Old commit: %s\n' "${old_commit}"

    # Pull first so a merge/network failure doesn't take the app offline.
    git pull --ff-only

    new_commit="$(git rev-parse HEAD)"
    printf 'New commit: %s\n' "${new_commit}"

    cd "${COMPOSE_DIR}"
    printf 'Compose dir: %s\n' "${COMPOSE_DIR}"

    # Destructive by design: -v wipes named volumes (incl. MySQL data).
    # Demo data is fake and re-seeded below.
    docker compose down -v
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

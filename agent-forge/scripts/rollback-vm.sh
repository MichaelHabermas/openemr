#!/usr/bin/env bash
# Code rollback. Switches to a prior commit, recreates the stack, runs health
# checks, and re-seeds the idempotent fake demo data. Volumes are preserved
# across rollbacks; the seed script restores known demo state for pid=900001.
# There is no point-in-time database rollback in this project.
# See agent-forge/docs/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md.
set -Eeuo pipefail

REPO_DIR="${AGENTFORGE_REPO_DIR:-${HOME}/repos/openemr}"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
TARGET_COMMIT="${1:-${AGENTFORGE_ROLLBACK_COMMIT:-}}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEED_SCRIPT="${AGENTFORGE_SEED_SCRIPT:-agent-forge/scripts/seed-demo-data.sh}"
HEALTH_TIMEOUT_SECONDS="${AGENTFORGE_HEALTH_TIMEOUT_SECONDS:-300}"
HEALTH_INTERVAL_SECONDS="${AGENTFORGE_HEALTH_INTERVAL_SECONDS:-5}"

wait_for_health() {
    local elapsed=0
    until "${SCRIPT_DIR}/health-check.sh"; do
        if [[ "${elapsed}" -ge "${HEALTH_TIMEOUT_SECONDS}" ]]; then
            printf 'Rollback failed: app did not become healthy within %s seconds.\n' "${HEALTH_TIMEOUT_SECONDS}" >&2
            return 1
        fi
        printf 'Health check not ready yet; retrying in %ss...\n' "${HEALTH_INTERVAL_SECONDS}"
        sleep "${HEALTH_INTERVAL_SECONDS}"
        elapsed=$((elapsed + HEALTH_INTERVAL_SECONDS))
    done
}

if [[ -z "${TARGET_COMMIT}" ]]; then
    printf 'Usage: %s <rollback-commit>\n' "$0" >&2
    printf 'Or set AGENTFORGE_ROLLBACK_COMMIT.\n' >&2
    exit 2
fi

cd "${REPO_DIR}"

printf 'Current commit: %s\n' "$(git rev-parse HEAD)"
printf 'Rollback target: %s\n' "${TARGET_COMMIT}"

git fetch --all --prune
git switch --detach "${TARGET_COMMIT}"

cd "${COMPOSE_DIR}"
docker compose down
docker compose up -d

wait_for_health

if [[ -x "${REPO_DIR}/${SEED_SCRIPT}" ]]; then
    printf 'Seeding fake demo data: %s\n' "${SEED_SCRIPT}"
    "${REPO_DIR}/${SEED_SCRIPT}"
else
    printf 'NOTE: seed script %s not present. Re-seed manually before demo.\n' "${SEED_SCRIPT}"
fi

printf 'Rollback completed to %s.\n' "${TARGET_COMMIT}"

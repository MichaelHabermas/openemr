#!/usr/bin/env bash
# Code rollback. Switches to a prior commit, recreates the stack, runs health
# checks, and re-seeds fake demo data. Data is reset by design — there is no
# database rollback in this project. See
# agent-forge/docs/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md.
set -Eeuo pipefail

REPO_DIR="${AGENTFORGE_REPO_DIR:-${HOME}/repos/openemr}"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
TARGET_COMMIT="${1:-${AGENTFORGE_ROLLBACK_COMMIT:-}}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEED_SCRIPT="${AGENTFORGE_SEED_SCRIPT:-agent-forge/scripts/seed-demo-data.sh}"

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
docker compose down -v
docker compose up -d

"${SCRIPT_DIR}/health-check.sh"

if [[ -x "${REPO_DIR}/${SEED_SCRIPT}" ]]; then
    printf 'Seeding fake demo data: %s\n' "${SEED_SCRIPT}"
    "${REPO_DIR}/${SEED_SCRIPT}"
else
    printf 'NOTE: seed script %s not present. Re-seed manually before demo.\n' "${SEED_SCRIPT}"
fi

printf 'Rollback completed to %s.\n' "${TARGET_COMMIT}"

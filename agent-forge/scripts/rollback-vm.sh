#!/usr/bin/env bash
set -Eeuo pipefail

REPO_DIR="${AGENTFORGE_REPO_DIR:-${HOME}/repos/openemr}"
COMPOSE_FILE="${AGENTFORGE_COMPOSE_FILE:-docker/development-easy/docker-compose.yml}"
TARGET_COMMIT="${1:-${AGENTFORGE_ROLLBACK_COMMIT:-}}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -z "${TARGET_COMMIT}" ]]; then
    printf 'Usage: %s <rollback-commit>\n' "$0" >&2
    printf 'Or set AGENTFORGE_ROLLBACK_COMMIT.\n' >&2
    exit 2
fi

if [[ -z "${AGENTFORGE_COMPOSE_COMMAND:-}" ]]; then
    printf 'Refusing rollback: set verified AGENTFORGE_COMPOSE_COMMAND first.\n' >&2
    exit 2
fi

cd "${REPO_DIR}"

printf 'Current commit: %s\n' "$(git rev-parse HEAD)"
printf 'Rollback target: %s\n' "${TARGET_COMMIT}"

git fetch --all --prune
git switch --detach "${TARGET_COMMIT}"

# shellcheck disable=SC2086
${AGENTFORGE_COMPOSE_COMMAND} -f "${COMPOSE_FILE}" down
# shellcheck disable=SC2086
${AGENTFORGE_COMPOSE_COMMAND} -f "${COMPOSE_FILE}" up --detach --wait

"${SCRIPT_DIR}/health-check.sh"

printf 'Rollback completed to %s.\n' "${TARGET_COMMIT}"

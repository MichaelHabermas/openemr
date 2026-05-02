#!/usr/bin/env bash
# Load AgentForge fake demo patient data into the OpenEMR development database.
# This script is idempotent for pid=900001, pid=900002, and pid=900003 and never drops tables or volumes.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_DIR="${AGENTFORGE_COMPOSE_DIR:-docker/development-easy}"
DB_SERVICE="${AGENTFORGE_DB_SERVICE:-mysql}"
DB_NAME="${AGENTFORGE_DB_NAME:-openemr}"
DB_USER="${AGENTFORGE_DB_USER:-root}"
DB_PASS="${AGENTFORGE_DB_PASS:-root}"
SQL_FILE="${AGENTFORGE_SQL_FILE:-agent-forge/sql/seed-demo-data.sql}"
WAIT_TIMEOUT_SECONDS="${AGENTFORGE_DB_WAIT_TIMEOUT_SECONDS:-120}"
WAIT_INTERVAL_SECONDS="${AGENTFORGE_DB_WAIT_INTERVAL_SECONDS:-3}"

compose() {
    (cd "${REPO_DIR}/${COMPOSE_DIR}" && docker compose "$@")
}

mysql_exec() {
    compose exec -T "${DB_SERVICE}" mariadb \
        --user="${DB_USER}" \
        --password="${DB_PASS}" \
        "${DB_NAME}" "$@"
}

wait_for_mysql() {
    local elapsed=0

    until mysql_exec --execute="SELECT 1;" >/dev/null 2>&1; do
        if [[ "${elapsed}" -ge "${WAIT_TIMEOUT_SECONDS}" ]]; then
            printf 'FAIL mysql: database was not ready within %s seconds.\n' "${WAIT_TIMEOUT_SECONDS}" >&2
            return 1
        fi

        printf 'WAIT mysql: database not ready yet.\n'
        sleep "${WAIT_INTERVAL_SECONDS}"
        elapsed=$((elapsed + WAIT_INTERVAL_SECONDS))
    done
}

main() {
    local sql_path="${REPO_DIR}/${SQL_FILE}"

    if [[ ! -f "${sql_path}" ]]; then
        printf 'FAIL seed: SQL file not found: %s\n' "${sql_path}" >&2
        exit 2
    fi

    wait_for_mysql

    printf 'Seeding AgentForge demo data from %s\n' "${SQL_FILE}"
    mysql_exec < "${sql_path}"
    printf 'PASS seed: fake demo patients pid=900001,900002,900003 loaded.\n'
}

main "$@"

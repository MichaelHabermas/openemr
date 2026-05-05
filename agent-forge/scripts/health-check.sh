#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/deploy-common.sh
source "${SCRIPT_DIR}/lib/deploy-common.sh"

agentforge_load_public_urls
APP_URL="${AGENTFORGE_APP_URL}"
READYZ_URL="${AGENTFORGE_READYZ_URL}"

health_check_url() {
    local label="$1"
    local url="$2"
    local requirement="$3"

    printf 'Checking %s: %s\n' "${label}" "${url}"
    agentforge_check_http "${label}" "${url}" "${requirement}"
}

main() {
    local failures=0

    health_check_url "public app" "${APP_URL}" "required" || failures=$((failures + 1))
    health_check_url "readiness endpoint" "${READYZ_URL}" "optional" || failures=$((failures + 1))

    if [[ "${failures}" -gt 0 ]]; then
        printf 'Health check failed: %s endpoint(s) failed.\n' "${failures}" >&2
        exit 1
    fi

    printf 'Health check passed.\n'
}

main "$@"

#!/usr/bin/env bash
set -Eeuo pipefail

APP_URL="${AGENTFORGE_APP_URL:-https://openemr.titleredacted.cc/}"
READYZ_URL="${AGENTFORGE_READYZ_URL:-https://openemr.titleredacted.cc/meta/health/readyz}"
CONNECT_TIMEOUT_SECONDS="${AGENTFORGE_CONNECT_TIMEOUT_SECONDS:-10}"
MAX_TIME_SECONDS="${AGENTFORGE_MAX_TIME_SECONDS:-20}"

check_url() {
    local label="$1"
    local url="$2"
    local required="$3"
    local status

    printf 'Checking %s: %s\n' "${label}" "${url}"

    set +e
    status="$(
        curl \
            --silent \
            --show-error \
            --location \
            --output /dev/null \
            --write-out '%{http_code}' \
            --connect-timeout "${CONNECT_TIMEOUT_SECONDS}" \
            --max-time "${MAX_TIME_SECONDS}" \
            "${url}"
    )"
    local curl_exit=$?
    set -e

    if [[ "${curl_exit}" -ne 0 ]]; then
        printf 'FAIL %s: curl exited with %s\n' "${label}" "${curl_exit}" >&2
        return 1
    fi

    if [[ "${status}" =~ ^[23][0-9][0-9]$ ]]; then
        printf 'PASS %s: HTTP %s\n' "${label}" "${status}"
        return 0
    fi

    if [[ "${required}" == "optional" && "${status}" == "404" ]]; then
        printf 'WARN %s: HTTP 404, readiness endpoint unavailable\n' "${label}"
        return 0
    fi

    printf 'FAIL %s: HTTP %s\n' "${label}" "${status}" >&2
    return 1
}

main() {
    local failures=0

    check_url "public app" "${APP_URL}" "required" || failures=$((failures + 1))
    check_url "readiness endpoint" "${READYZ_URL}" "optional" || failures=$((failures + 1))

    if [[ "${failures}" -gt 0 ]]; then
        printf 'Health check failed: %s endpoint(s) failed.\n' "${failures}" >&2
        exit 1
    fi

    printf 'Health check passed.\n'
}

main "$@"

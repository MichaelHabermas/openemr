#!/usr/bin/env bash
# Shared URL defaults and HTTP probe helpers for deploy-vm.sh and health-check.sh.
# shellcheck shell=bash
# Intended to be sourced from agent-forge/scripts/*.sh — do not execute directly.

agentforge_load_public_urls() {
    AGENTFORGE_APP_URL="${AGENTFORGE_APP_URL:-https://openemr.titleredacted.cc/}"
    AGENTFORGE_READYZ_URL="${AGENTFORGE_READYZ_URL:-https://openemr.titleredacted.cc/meta/health/readyz}"
    export AGENTFORGE_APP_URL AGENTFORGE_READYZ_URL
}

agentforge_init_curl_timeouts() {
    AGENTFORGE_CONNECT_TIMEOUT_SECONDS="${AGENTFORGE_CONNECT_TIMEOUT_SECONDS:-10}"
    AGENTFORGE_MAX_TIME_SECONDS="${AGENTFORGE_MAX_TIME_SECONDS:-20}"
    export AGENTFORGE_CONNECT_TIMEOUT_SECONDS AGENTFORGE_MAX_TIME_SECONDS
}

# Probe an HTTP(S) URL. Prints PASS/WAIT/FAIL/WARN lines to stdout (or stderr for FAIL).
# Args: label, url, requirement — requirement is "required" or "optional"
# (optional: HTTP 404 is success; other non-2xx/3xx fail).
# Returns 0 on success, 1 on retryable/wait (required only), 1 on failure.
agentforge_check_http() {
    local label="$1"
    local url="$2"
    local requirement="$3"
    local status

    agentforge_init_curl_timeouts

    set +e
    status="$(
        curl \
            --silent \
            --show-error \
            --location \
            --output /dev/null \
            --write-out '%{http_code}' \
            --connect-timeout "${AGENTFORGE_CONNECT_TIMEOUT_SECONDS}" \
            --max-time "${AGENTFORGE_MAX_TIME_SECONDS}" \
            "${url}"
    )"
    local curl_exit=$?
    set -e

    if [[ "${curl_exit}" -ne 0 ]]; then
        if [[ "${requirement}" == "required" ]]; then
            printf 'WAIT %s: curl exit %s, HTTP %s\n' "${label}" "${curl_exit}" "${status:-000}"
        else
            printf 'FAIL %s: curl exited with %s\n' "${label}" "${curl_exit}" >&2
        fi
        return 1
    fi

    if [[ "${status}" =~ ^[23][0-9][0-9]$ ]]; then
        printf 'PASS %s: HTTP %s\n' "${label}" "${status}"
        return 0
    fi

    if [[ "${requirement}" == "optional" && "${status}" == "404" ]]; then
        printf 'WARN %s: HTTP 404, readiness endpoint unavailable\n' "${label}"
        return 0
    fi

    if [[ "${requirement}" == "required" ]]; then
        printf 'WAIT %s: HTTP %s\n' "${label}" "${status:-000}"
        return 1
    fi

    printf 'FAIL %s: HTTP %s\n' "${label}" "${status}" >&2
    return 1
}

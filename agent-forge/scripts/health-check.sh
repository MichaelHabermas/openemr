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

validate_readyz_payload() {
    local payload_file="$1"

    php -r '
        $path = $argv[1] ?? "";
        $json = is_file($path) ? file_get_contents($path) : false;
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)) {
            fwrite(STDERR, "FAIL readiness payload: invalid JSON\n");
            exit(1);
        }
        $issues = [];
        $checks = is_array($payload["checks"] ?? null) ? $payload["checks"] : [];
        $components = is_array($payload["components"] ?? null) ? $payload["components"] : [];
        $runtime = is_array($components["agentforge_runtime"] ?? null) ? $components["agentforge_runtime"] : [];
        $mariadb = is_array($runtime["mariadb"] ?? null) ? $runtime["mariadb"] : [];
        $worker = is_array($runtime["worker"] ?? null) ? $runtime["worker"] : [];
        $queue = is_array($runtime["queue"] ?? null) ? $runtime["queue"] : [];

        if (($payload["status"] ?? null) !== "ready") {
            $issues[] = "status is not ready";
        }
        if (($checks["database"] ?? false) !== true) {
            $issues[] = "database check is not healthy";
        }
        if (($checks["agentforge_runtime"] ?? false) !== true) {
            $issues[] = "agentforge_runtime check is not healthy";
        }
        $version = (string) ($mariadb["version"] ?? "");
        $compatibleMariaDb = preg_match("/^(\d+)\.(\d+)/", $version, $matches) === 1
            && ((int) $matches[1] > 11 || ((int) $matches[1] === 11 && (int) $matches[2] >= 8));
        if (($mariadb["healthy"] ?? false) !== true || !$compatibleMariaDb) {
            $issues[] = "MariaDB 11.8 runtime check failed";
        }
        if (($worker["healthy"] ?? false) !== true || ($worker["worker"] ?? "") !== "intake-extractor") {
            $issues[] = "agentforge-worker heartbeat check failed";
        }
        if (($queue["healthy"] ?? false) !== true) {
            $issues[] = "clinical document queue check failed";
        }
        $forbidden = ["patient_id", "patient_ref", "document_id", "job_id", "filename", "quote", "quote_or_value", "raw_value", "document_text", "exception"];
        $encoded = json_encode($payload);
        foreach ($forbidden as $key) {
            if (is_string($encoded) && str_contains($encoded, $key)) {
                $issues[] = "readiness payload contains forbidden key " . $key;
            }
        }
        if ($issues !== []) {
            foreach ($issues as $issue) {
                fwrite(STDERR, "FAIL readiness payload: " . $issue . "\n");
            }
            exit(1);
        }
        printf(
            "PASS runtime: MariaDB %s, worker %s %s age=%ss, queue pending=%s running=%s stale=%s\n",
            (string) ($mariadb["version"] ?? "unknown"),
            (string) ($worker["worker"] ?? "unknown"),
            (string) ($worker["status"] ?? "unknown"),
            (string) ($worker["last_heartbeat_age_seconds"] ?? "unknown"),
            (string) ($queue["pending"] ?? "unknown"),
            (string) ($queue["running"] ?? "unknown"),
            (string) ($queue["stale_running"] ?? "unknown")
        );
    ' "${payload_file}"
}

main() {
    local failures=0
    local readyz_payload
    readyz_payload="$(mktemp "${TMPDIR:-/tmp}/agentforge-readyz.XXXXXX.json")"
    trap 'rm -f "${readyz_payload}"' EXIT

    health_check_url "public app" "${APP_URL}" "required" || failures=$((failures + 1))
    health_check_url "readiness endpoint" "${READYZ_URL}" "required" || failures=$((failures + 1))

    if curl \
        --silent \
        --show-error \
        --location \
        --output "${readyz_payload}" \
        --connect-timeout "${AGENTFORGE_CONNECT_TIMEOUT_SECONDS:-10}" \
        --max-time "${AGENTFORGE_MAX_TIME_SECONDS:-20}" \
        "${READYZ_URL}"; then
        validate_readyz_payload "${readyz_payload}" || failures=$((failures + 1))
    else
        printf 'FAIL readiness payload: unable to fetch %s\n' "${READYZ_URL}" >&2
        failures=$((failures + 1))
    fi

    if [[ "${failures}" -gt 0 ]]; then
        printf 'Health check failed: %s endpoint(s) failed.\n' "${failures}" >&2
        exit 1
    fi

    printf 'Health check passed.\n'
}

main "$@"

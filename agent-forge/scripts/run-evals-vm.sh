#!/usr/bin/env bash
# Run AgentForge Tier 0/1/2 evals inside the openemr container on the deployed
# VM, then pull result JSON files back to the local machine. Tier 2 uses the
# API key already loaded into the VM's docker/development-easy/.env via the
# compose environment block, so the local shell does not need provider creds.
#
# This is not a substitute for browser/session smoke proof. PHP-CLI inside the
# container bypasses Apache, the controller, the OpenEMR session, CSRF, and
# Twig output. Deployed HTTP-path proof is Tier 4 (currently manual; see
# agent-forge/docs/evaluation/TIER4-DEPLOYED-SMOKE-AUTOMATION-SCOPE.md).
#
# See agent-forge/docs/evaluation/EVALUATION-TIERS.md.
set -Eeuo pipefail

SSH_HOST="${AGENTFORGE_VM_SSH_HOST:-}"
REMOTE_REPO_DIR="${AGENTFORGE_VM_REPO_DIR:-repos/openemr}"
REMOTE_COMPOSE_DIR="${AGENTFORGE_VM_COMPOSE_DIR:-docker/development-easy}"
REMOTE_CONTAINER_REPO="${AGENTFORGE_VM_CONTAINER_REPO:-/var/www/localhost/htdocs/openemr}"
LOCAL_RESULTS_DIR="${AGENTFORGE_VM_RESULTS_DIR:-agent-forge/eval-results/vm-pulled}"
TIERS="${AGENTFORGE_VM_TIERS:-0,1,2}"

if [[ -z "${SSH_HOST}" ]]; then
    printf 'Set AGENTFORGE_VM_SSH_HOST to the SSH alias or user@host for the deployed VM.\n' >&2
    printf 'Example: AGENTFORGE_VM_SSH_HOST=openemr-demo bash agent-forge/scripts/run-evals-vm.sh\n' >&2
    exit 1
fi

resolve_remote_home() {
    REMOTE_HOME="$(ssh "${SSH_HOST}" 'printf "%s" "$HOME"')"
    if [[ -z "${REMOTE_HOME}" ]]; then
        printf 'Failed to resolve $HOME on %s\n' "${SSH_HOST}" >&2
        return 1
    fi
    REMOTE_REPO_ABS="${REMOTE_HOME}/${REMOTE_REPO_DIR}"
    REMOTE_COMPOSE_ABS="${REMOTE_REPO_ABS}/${REMOTE_COMPOSE_DIR}"
}

run_remote_tier() {
    local tier="$1"
    local script="$2"
    local label="$3"

    printf '\n=== Tier %s: %s ===\n' "${tier}" "${label}"

    set +e
    ssh "${SSH_HOST}" "cd '${REMOTE_COMPOSE_ABS}' && docker compose exec -T openemr php '${REMOTE_CONTAINER_REPO}/${script}'"
    local exit_code=$?
    set -e

    if [[ "${exit_code}" -ne 0 ]]; then
        printf 'FAIL Tier %s: exit %s\n' "${tier}" "${exit_code}" >&2
        return "${exit_code}"
    fi

    printf 'PASS Tier %s\n' "${tier}"
}

pull_results() {
    local timestamp dest
    timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
    dest="${LOCAL_RESULTS_DIR}/${timestamp}"

    mkdir -p "${dest}"
    printf '\n=== Pulling result JSON files ===\n'
    printf 'Source: %s:%s/agent-forge/eval-results/\n' "${SSH_HOST}" "${REMOTE_REPO_ABS}"
    printf 'Dest:   %s\n' "${dest}"

    set +e
    scp -q "${SSH_HOST}:${REMOTE_REPO_ABS}/agent-forge/eval-results/*.json" "${dest}/" 2>/dev/null
    local scp_exit=$?
    set -e

    if [[ "${scp_exit}" -ne 0 ]]; then
        printf 'WARN scp returned %s (no result files found, or path differs).\n' "${scp_exit}" >&2
        return 0
    fi

    local count
    count="$(find "${dest}" -name '*.json' -type f | wc -l | tr -d '[:space:]')"
    printf 'Pulled %s JSON file(s) into %s\n' "${count}" "${dest}"
}

main() {
    resolve_remote_home

    printf 'VM:           %s\n' "${SSH_HOST}"
    printf 'Repo (VM):    %s\n' "${REMOTE_REPO_ABS}"
    printf 'Compose (VM): %s\n' "${REMOTE_COMPOSE_ABS}"
    printf 'Container:    %s\n' "${REMOTE_CONTAINER_REPO}"
    printf 'Tiers:        %s\n' "${TIERS}"

    local tiers
    IFS=',' read -ra tiers <<< "${TIERS}"
    local failures=0

    for tier in "${tiers[@]}"; do
        case "${tier}" in
            0)
                run_remote_tier 0 "agent-forge/scripts/run-evals.php" "fixture orchestration" \
                    || failures=$((failures + 1))
                ;;
            1)
                run_remote_tier 1 "agent-forge/scripts/run-sql-evidence-evals.php" "seeded SQL evidence" \
                    || failures=$((failures + 1))
                ;;
            2)
                run_remote_tier 2 "agent-forge/scripts/run-tier2-evals.php" "live LLM" \
                    || failures=$((failures + 1))
                ;;
            *)
                printf 'Skip: unknown tier "%s" in AGENTFORGE_VM_TIERS\n' "${tier}" >&2
                ;;
        esac
    done

    pull_results

    if [[ "${failures}" -gt 0 ]]; then
        printf '\nVM eval run failed: %s tier(s) failed.\n' "${failures}" >&2
        exit 1
    fi

    printf '\nVM eval run passed.\n'
}

main "$@"

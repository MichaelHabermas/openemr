#!/usr/bin/env bash

# AgentForge final submission preflight.
#
# Runs every gate that must pass before submitting. Covers both local quality
# (syntax, tests, evals, static analysis) and deployed readiness (health,
# demo data, eval freshness, observability proof).
#
# Usage:
#   bash agent-forge/scripts/preflight-final-submission.sh
#
# Skip deployed checks (local-only run):
#   AGENTFORGE_PREFLIGHT_LOCAL_ONLY=1 bash agent-forge/scripts/preflight-final-submission.sh
#
# Exit codes:
#   0  All gates passed
#   1  One or more gates failed

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

PASS=0
FAIL=0
SKIP=0
GATE_RESULTS=()
LOCAL_ONLY="${AGENTFORGE_PREFLIGHT_LOCAL_ONLY:-0}"

run_gate() {
    local name="$1"
    shift
    printf '\n── Gate: %s ──\n' "$name"
    if "$@"; then
        GATE_RESULTS+=("PASS  $name")
        PASS=$((PASS + 1))
    else
        GATE_RESULTS+=("FAIL  $name")
        FAIL=$((FAIL + 1))
    fi
}

skip_gate() {
    local name="$1"
    printf '\n── Gate: %s ── SKIPPED (LOCAL_ONLY)\n' "$name"
    GATE_RESULTS+=("SKIP  $name")
    SKIP=$((SKIP + 1))
}

# ── Local gates ──────────────────────────────────────────────────────────────

gate_agentforge_full() {
    bash agent-forge/scripts/check-agentforge.sh 2>&1
}

# ── Deployed gates ───────────────────────────────────────────────────────────

gate_health_check() {
    bash agent-forge/scripts/health-check.sh 2>&1
}

gate_demo_data() {
    bash agent-forge/scripts/verify-demo-data.sh 2>&1
}

# ── Eval freshness gates ─────────────────────────────────────────────────────

gate_eval_freshness_tier2() {
    local max_age_days=7
    local latest
    latest="$(find agent-forge/eval-results -name 'tier2-live-*.json' -type f 2>/dev/null | sort | tail -n 1)"
    if [ -z "$latest" ]; then
        echo "FAIL: No Tier 2 eval result file found in agent-forge/eval-results/"
        return 1
    fi
    local age_days
    age_days=$(( ( $(date +%s) - $(stat -c %Y "$latest" 2>/dev/null || stat -f %m "$latest" 2>/dev/null) ) / 86400 ))
    if [ "$age_days" -gt "$max_age_days" ]; then
        echo "FAIL: Latest Tier 2 result ($latest) is ${age_days}d old (max ${max_age_days}d)."
        echo "  Run: php agent-forge/scripts/run-tier2-evals.php"
        return 1
    fi
    echo "PASS: Tier 2 result ($latest) is ${age_days}d old."
}

gate_eval_freshness_deployed_smoke() {
    local max_age_days=7
    local latest
    latest="$(find agent-forge/eval-results -name 'deployed-smoke-*.json' -type f 2>/dev/null | sort | tail -n 1)"
    if [ -z "$latest" ]; then
        echo "FAIL: No deployed smoke result file found in agent-forge/eval-results/"
        return 1
    fi
    local age_days
    age_days=$(( ( $(date +%s) - $(stat -c %Y "$latest" 2>/dev/null || stat -f %m "$latest" 2>/dev/null) ) / 86400 ))
    if [ "$age_days" -gt "$max_age_days" ]; then
        echo "FAIL: Latest deployed smoke result ($latest) is ${age_days}d old (max ${max_age_days}d)."
        echo "  Run: php agent-forge/scripts/run-deployed-smoke.php"
        return 1
    fi
    echo "PASS: Deployed smoke result ($latest) is ${age_days}d old."
}

# ── Observability gate ────────────────────────────────────────────────────────

gate_latency_results() {
    local file="agent-forge/docs/operations/LATENCY-RESULTS.md"
    if [ ! -s "$file" ]; then
        echo "FAIL: $file is missing or empty."
        return 1
    fi
    echo "PASS: Latency results documented in $file."
}

gate_observability_proof() {
    local mode="${AGENTFORGE_AUDIT_MODE:-docker-compose}"
    local output
    output="$(php agent-forge/scripts/show-request-traces.php --limit 1 2>&1)" || true
    if echo "$output" | grep -q 'request_id'; then
        echo "PASS: Observability trace retrievable (mode: ${mode})."
        return 0
    fi
    echo "FAIL: Could not retrieve any request traces (mode: ${mode})."
    echo "  Output: $output"
    return 1
}

# ── Run all gates ─────────────────────────────────────────────────────────────

printf '══ AgentForge Final Submission Preflight ══\n'

# Local gates
run_gate "AgentForge full gate (Tier 0 + clinical doc + PHPStan + PHPCS)" gate_agentforge_full
run_gate "Latency SLO documentation"                                       gate_latency_results

# Eval freshness (local file check, no network)
run_gate "Tier 2 eval freshness (< 7d)"          gate_eval_freshness_tier2
run_gate "Deployed smoke freshness (< 7d)"        gate_eval_freshness_deployed_smoke

# Deployed gates (skippable)
if [ "$LOCAL_ONLY" = "1" ]; then
    skip_gate "Health check (deployed app)"
    skip_gate "Demo data verification"
    skip_gate "Observability proof (trace retrieval)"
else
    run_gate "Health check (deployed app)"             gate_health_check
    run_gate "Demo data verification"                  gate_demo_data
    run_gate "Observability proof (trace retrieval)"   gate_observability_proof
fi

# ── Summary ──────────────────────────────────────────────────────────────────

printf '\n══ Preflight Summary ══\n'
for result in "${GATE_RESULTS[@]}"; do
    printf '  %s\n' "$result"
done
printf '\n%d passed, %d failed, %d skipped.\n' "$PASS" "$FAIL" "$SKIP"

if [ "$FAIL" -gt 0 ]; then
    printf '\n❌ Preflight FAILED. Fix the above failures before submitting.\n'
    exit 1
fi

printf '\n✅ Preflight PASSED. Ready for final submission.\n'

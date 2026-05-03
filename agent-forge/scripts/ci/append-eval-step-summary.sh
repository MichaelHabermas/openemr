#!/usr/bin/env bash
# Append a Markdown eval summary to GITHUB_STEP_SUMMARY (GitHub Actions).
# Usage: append-eval-step-summary.sh <human_label> <glob_under_repo_root>
# Example: append-eval-step-summary.sh "Tier 0" "agent-forge/eval-results/eval-results-*.json"
set -euo pipefail

label="${1:-}"
glob="${2:-}"

if [[ -z "${GITHUB_STEP_SUMMARY:-}" ]]; then
  echo "GITHUB_STEP_SUMMARY is not set; skipping eval summary (${label})." >&2
  exit 0
fi

if [[ -z "${label}" || -z "${glob}" ]]; then
  echo "Usage: $0 <label> <glob>" >&2
  exit 2
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "${repo_root}"

# shellcheck disable=SC2086
latest="$(ls -t ${glob} 2>/dev/null | head -1 || true)"
if [[ -z "${latest}" ]]; then
  {
    echo "### ${label}"
    echo ""
    echo "_No eval JSON matched ${glob}; summary skipped._"
    echo ""
  } >>"${GITHUB_STEP_SUMMARY}"
  exit 0
fi

{
  echo "### ${label}"
  echo ""
} >>"${GITHUB_STEP_SUMMARY}"

php agent-forge/scripts/render-eval-summary.php --input="${latest}" --format=github >>"${GITHUB_STEP_SUMMARY}"

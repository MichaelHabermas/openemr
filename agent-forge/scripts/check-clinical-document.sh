#!/usr/bin/env bash
# Run the AgentForge clinical document regression gate.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${REPO_DIR}"

run_step() {
  local label="$1"
  shift
  printf '\n==> %s\n' "${label}"
  "$@"
}

run_step "Check diff whitespace" git diff --check

run_step "Check PHP syntax (clinical document eval surface)" bash -c \
  "php -l library/ajax/upload.php \
   && find src/AgentForge tests/Tests/Isolated/AgentForge agent-forge/scripts \
        -type f -name '*.php' -print0 | xargs -0 -n 1 php -l > /dev/null"

run_step "Check shell script syntax" bash -c \
  "find agent-forge/scripts -type f -name '*.sh' -print0 | xargs -0 -n 1 bash -n"

run_step "Run AgentForge isolated PHPUnit" \
  composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'

run_step "Run Clinical document evals" \
  php agent-forge/scripts/run-clinical-document-evals.php

run_step "Run focused PHPStan (clinical document eval surface)" \
  composer phpstan -- --error-format=raw \
    src/AgentForge \
    tests/Tests/Isolated/AgentForge \
    interface/patient_file/summary/agent_request.php \
    library/ajax/upload.php

run_step "Run PHPCS on changed AgentForge clinical document eval PHP files" bash -c '
  files="$(
    { git diff --name-only --diff-filter=ACM; git ls-files --others --exclude-standard; } \
    | grep -E "^(src/AgentForge|tests/Tests/Isolated/AgentForge|agent-forge/scripts|library/ajax/upload\.php)" || true
  )"
  if [[ -z "${files}" ]]; then
    printf "No changed AgentForge clinical document eval PHP files to check.\n"
  else
    printf "%s\n" "${files}" | xargs vendor/bin/phpcs
  fi
'

printf '\nPASS clinical document eval gate.\n'

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

host_php_has_phpunit_extensions() {
  php -r '
      $required = ["dom", "json", "libxml", "mbstring", "tokenizer", "xml", "xmlwriter"];
      foreach ($required as $ext) {
          if (!extension_loaded($ext)) { exit(1); }
      }
      exit(0);
  ' 2>/dev/null
}

run_agentforge_isolated_phpunit() {
  local compose_file="${REPO_DIR}/docker/development-easy/docker-compose.yml"

  if host_php_has_phpunit_extensions; then
      composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'
      return
  fi

  if [[ ! -f "${compose_file}" ]]; then
      printf 'Host PHP is missing extensions required by PHPUnit.\n' >&2
      return 1
  fi

  if ! docker compose -f "${compose_file}" ps --status running -q openemr 2>/dev/null | grep -q .; then
      printf 'Host PHP is missing extensions and openemr container is not running.\n' >&2
      return 1
  fi

  printf 'Host PHP lacks PHPUnit extensions; running isolated PHPUnit in the openemr container.\n'
  docker compose -f "${compose_file}" exec -T -w /var/www/localhost/htdocs/openemr openemr \
      bash -lc 'git config --global --add safe.directory "$PWD" 2>/dev/null || true; composer phpunit-isolated -- --filter '"'"'OpenEMR\\Tests\\Isolated\\AgentForge'"'"''
}

run_step "Check diff whitespace" git diff --check

run_step "Check PHP syntax (clinical document eval surface)" bash -c \
  "php -l library/ajax/upload.php \
   && php -l interface/patient_file/summary/agent_document_source.php \
   && find src/AgentForge tests/Tests/Isolated/AgentForge agent-forge/scripts \
        -type f -name '*.php' -print0 | xargs -0 -n 1 php -l > /dev/null"

run_step "Check shell script syntax" bash -c \
  "find agent-forge/scripts -type f -name '*.sh' -print0 | xargs -0 -n 1 bash -n"

run_step "Run AgentForge isolated PHPUnit" \
  run_agentforge_isolated_phpunit

run_step "Run Clinical document evals" \
  php agent-forge/scripts/run-clinical-document-evals.php

run_step "Format coverage summary" php -r '
$resultsDir = getenv("AGENTFORGE_CLINICAL_DOCUMENT_EVAL_RESULTS_DIR") ?: "agent-forge/eval-results";
$dirs = glob($resultsDir . "/clinical-document-*", GLOB_ONLYDIR);
if ($dirs === [] || $dirs === false) { echo "No clinical-document eval directories found.\n"; exit(0); }
usort($dirs, static fn (string $a, string $b): int => strcmp($b, $a));
$file = $dirs[0] . "/summary.json";
if (!is_file($file)) { echo "No summary.json in latest run.\n"; exit(0); }
$data = json_decode((string) file_get_contents($file), true);
$meta = $data["metadata"] ?? [];
$docTypes = $meta["doc_type_counts"] ?? [];
$formats = $meta["source_format_counts"] ?? [];
$perType = $data["doc_type_rubrics"] ?? [];
if ($docTypes !== []) {
    printf("  %-25s %s\n", "Document Type", "Cases");
    printf("  %-25s %s\n", str_repeat("-", 25), str_repeat("-", 5));
    foreach ($docTypes as $type => $count) { printf("  %-25s %d\n", $type, $count); }
}
if ($formats !== []) {
    printf("\n  %-25s %s\n", "Source Format", "Cases");
    printf("  %-25s %s\n", str_repeat("-", 25), str_repeat("-", 5));
    foreach ($formats as $ext => $count) { printf("  %-25s %d\n", "." . $ext, $count); }
}
if ($perType !== []) {
    printf("\n  Per-type rubric pass rates:\n");
    foreach ($perType as $type => $rubrics) {
        printf("    %s:\n", $type);
        foreach ($rubrics as $name => $r) {
            $total = $r["passed"] + $r["failed"];
            printf("      %-28s %d/%d (%.0f%%)\n", $name, $r["passed"], $total, $r["pass_rate"] * 100);
        }
    }
}
'

run_step "Run focused PHPStan (clinical document eval surface)" \
  env COMPOSER_PROCESS_TIMEOUT=900 composer phpstan -- --error-format=raw \
    src/AgentForge \
    tests/Tests/Isolated/AgentForge \
    interface/patient_file/summary/agent_request.php \
    library/ajax/upload.php

run_step "Run PHPCS on changed AgentForge clinical document eval PHP files" bash -c '
  files="$(
    { git diff --name-only --diff-filter=ACM; git ls-files --others --exclude-standard; } \
    | grep -E "^(src/AgentForge|tests/Tests/Isolated/AgentForge|agent-forge/scripts|library/ajax/upload\.php|interface/patient_file/summary/agent_document_source\.php)" || true
  )"
  if [[ -z "${files}" ]]; then
    printf "No changed AgentForge clinical document eval PHP files to check.\n"
  else
    printf "%s\n" "${files}" | xargs vendor/bin/phpcs
  fi
'

printf '\nPASS clinical document eval gate.\n'

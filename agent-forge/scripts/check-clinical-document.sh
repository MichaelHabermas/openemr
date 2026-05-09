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
   && php -l interface/patient_file/summary/agent_document_source.php \
   && find src/AgentForge tests/Tests/Isolated/AgentForge agent-forge/scripts \
        -type f -name '*.php' -print0 | xargs -0 -n 1 php -l > /dev/null"

run_step "Check shell script syntax" bash -c \
  "find agent-forge/scripts -type f -name '*.sh' -print0 | xargs -0 -n 1 bash -n"

run_step "Run AgentForge isolated PHPUnit" \
  composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'

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
  composer phpstan -- --error-format=raw \
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

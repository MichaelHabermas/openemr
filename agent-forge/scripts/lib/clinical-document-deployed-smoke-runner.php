<?php

/**
 * Week 2 clinical-document deployed smoke runner.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\AgentForge\Cli\AgentForgeRepoPaths;

require_once __DIR__ . '/code-version.php';
require_once __DIR__ . '/script-runtime.php';
require_once __DIR__ . '/deployed-smoke-runner.php';

const AGENTFORGE_CLINICAL_SMOKE_DEFAULT_PID = 900101;

/**
 * @return array<string, mixed>
 */
function agentforge_clinical_smoke_config(): array
{
    $repoRoot = AgentForgeRepoPaths::fromScriptsLibDirectory(__DIR__);
    agentforge_scripts_load_compose_dotenv($repoRoot);
    $baseUrl = agentforge_scripts_env_string('AGENTFORGE_DEPLOYED_URL', 'https://openemr.titleredacted.cc/');
    if (!str_ends_with($baseUrl, '/')) {
        $baseUrl .= '/';
    }

    return [
        'base_url' => $baseUrl,
        'username' => agentforge_scripts_env_string('AGENTFORGE_SMOKE_USER'),
        'password' => agentforge_scripts_env_string('AGENTFORGE_SMOKE_PASSWORD'),
        'pid' => agentforge_scripts_env_int('AGENTFORGE_CLINICAL_SMOKE_PID', AGENTFORGE_CLINICAL_SMOKE_DEFAULT_PID),
        'lab_path' => agentforge_scripts_env_string('AGENTFORGE_CLINICAL_SMOKE_LAB_PATH', $repoRoot . '/agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf'),
        'intake_path' => agentforge_scripts_env_string('AGENTFORGE_CLINICAL_SMOKE_INTAKE_PATH', $repoRoot . '/agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf'),
        'lab_category' => agentforge_scripts_env_string('AGENTFORGE_CLINICAL_SMOKE_LAB_CATEGORY', 'Lab Report'),
        'intake_category' => agentforge_scripts_env_string('AGENTFORGE_CLINICAL_SMOKE_INTAKE_CATEGORY', 'Intake Form'),
        'job_timeout_s' => agentforge_scripts_env_int('AGENTFORGE_CLINICAL_SMOKE_JOB_TIMEOUT_S', 180),
        'poll_interval_ms' => agentforge_scripts_env_int('AGENTFORGE_CLINICAL_SMOKE_POLL_INTERVAL_MS', 2000),
        'timeout_s' => agentforge_scripts_env_int('AGENTFORGE_SMOKE_TIMEOUT_S', 90),
        'results_dir' => agentforge_scripts_env_string('AGENTFORGE_EVAL_RESULTS_DIR', $repoRoot . '/agent-forge/eval-results'),
        'repo_root' => $repoRoot,
        'executor' => agentforge_scripts_env_string('AGENTFORGE_SMOKE_EXECUTOR', 'local'),
    ];
}

function agentforge_clinical_smoke_main(): int
{
    $config = agentforge_clinical_smoke_config();
    $issues = agentforge_clinical_smoke_preflight_issues($config);
    if ($issues !== []) {
        foreach ($issues as $issue) {
            fwrite(STDERR, "Clinical deployed smoke preflight failed: {$issue}\n");
        }

        return 2;
    }

    if (!agentforge_scripts_ensure_directory((string) $config['results_dir'], 'results directory')) {
        return 2;
    }

    $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $cookieJar = agentforge_deployed_smoke_temp_cookie_jar('clinical-document');
    $cases = [];

    try {
        if (!agentforge_deployed_smoke_login($config['base_url'], $config['username'], $config['password'], $cookieJar, $config['timeout_s'])) {
            throw new RuntimeException('login failed');
        }
        if (!agentforge_deployed_smoke_set_pid($config['base_url'], $config['pid'], $cookieJar, $config['timeout_s'])) {
            throw new RuntimeException('patient session selection failed');
        }
        $csrf = agentforge_deployed_smoke_fetch_csrf_token($config['base_url'], $config['pid'], $cookieJar, $config['timeout_s']);
        if ($csrf === null) {
            throw new RuntimeException('csrf token scrape failed');
        }

        $runtimeBefore = agentforge_clinical_smoke_runtime_snapshot();
        $lab = agentforge_clinical_smoke_upload_and_wait($config, $cookieJar, $csrf, 'lab_pdf', $config['lab_category'], $config['lab_path']);
        $intake = agentforge_clinical_smoke_upload_and_wait($config, $cookieJar, $csrf, 'intake_form', $config['intake_category'], $config['intake_path']);
        $question = agentforge_deployed_smoke_post_question(
            $config['base_url'],
            $config['pid'],
            'What changed in the uploaded labs and intake form, and what guideline follow-up evidence is relevant?',
            $csrf,
            null,
            $cookieJar,
            $config['timeout_s'],
        );
        $questionIssues = agentforge_clinical_smoke_evaluate_question($question['body']);
        $runtimeAfter = agentforge_clinical_smoke_runtime_snapshot();

        $cases[] = [
            'id' => 'clinical_document_deployed_upload_worker_answer',
            'verdict' => $questionIssues === [] ? 'pass' : 'fail',
            'uploaded_documents' => [$lab, $intake],
            'question' => [
                'http_status' => $question['http_status'],
                'request_id' => $question['request_id'],
                'latency_ms' => $question['latency_ms'],
                'citation_counts' => agentforge_clinical_smoke_citation_counts($question['body']),
                'issues' => $questionIssues,
            ],
            'runtime_before' => $runtimeBefore,
            'runtime_after' => $runtimeAfter,
        ];
    } catch (Throwable $e) {
        $cases[] = [
            'id' => 'clinical_document_deployed_upload_worker_answer',
            'verdict' => 'fail',
            'failure_detail' => $e->getMessage(),
        ];
    } finally {
        @unlink($cookieJar);
    }

    $failed = count(array_filter($cases, static fn (array $case): bool => ($case['verdict'] ?? null) === 'fail'));
    $summary = [
        'tier' => 'clinical_document_deployed_smoke',
        'code_version' => agentforge_deployed_smoke_code_version($config['repo_root']),
        'deployed_url' => $config['base_url'],
        'executed_at_utc' => $startedAt->format(DateTimeInterface::ATOM),
        'executor' => $config['executor'],
        'patient_ref' => 'clinical-smoke-pid-' . hash('sha256', (string) $config['pid']),
        'summary' => [
            'total' => count($cases),
            'passed' => count($cases) - $failed,
            'failed' => $failed,
        ],
        'cases' => agentforge_clinical_smoke_redact_artifact($cases),
    ];

    $resultPath = agentforge_scripts_write_eval_result($config['results_dir'], 'clinical-document-deployed-smoke', $startedAt, $summary);
    printf("Clinical document deployed smoke: %d passed, %d failed. Results: %s\n", $summary['summary']['passed'], $failed, $resultPath);

    return $failed === 0 ? 0 : 1;
}

/**
 * @param array<string, mixed> $config
 * @return list<string>
 */
function agentforge_clinical_smoke_preflight_issues(array $config): array
{
    $issues = [];
    foreach (['username' => 'AGENTFORGE_SMOKE_USER', 'password' => 'AGENTFORGE_SMOKE_PASSWORD'] as $key => $env) {
        if (($config[$key] ?? '') === '') {
            $issues[] = "{$env} is required";
        }
    }
    foreach (['lab_path', 'intake_path'] as $key) {
        if (!is_string($config[$key] ?? null) || !is_file($config[$key])) {
            $issues[] = "{$key} does not point to a readable fixture";
        }
    }
    if (!extension_loaded('curl')) {
        $issues[] = 'PHP curl extension is required';
    }

    return $issues;
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function agentforge_clinical_smoke_upload_and_wait(array $config, string $cookieJar, string $csrf, string $docType, string $categoryName, string $path): array
{
    $categoryId = agentforge_clinical_smoke_query_scalar("SELECT id FROM categories WHERE name = '" . agentforge_clinical_smoke_sql_quote($categoryName) . "' LIMIT 1");
    if ($categoryId === '') {
        throw new RuntimeException("missing category mapping for {$categoryName}");
    }

    $fileName = basename($path);
    $preUploadMaxDocumentId = agentforge_clinical_smoke_max_document_id((int) $config['pid'], (int) $categoryId);
    $url = sprintf(
        '%slibrary/ajax/upload.php?patient_id=%d&parent_id=%d&csrf_token_form=%s',
        $config['base_url'],
        $config['pid'],
        (int) $categoryId,
        rawurlencode($csrf),
    );
    $response = agentforge_deployed_smoke_curl_request([
        'url' => $url,
        'method' => 'POST',
        'cookie_jar' => $cookieJar,
        'timeout_s' => $config['timeout_s'],
        'post_fields' => [
            'file' => new CURLFile($path, 'application/pdf', $fileName),
        ],
        'headers' => ['Accept: application/json'],
    ]);
    if (!in_array($response['http_status'], [200, 204], true)) {
        throw new RuntimeException("upload failed for {$docType}: HTTP {$response['http_status']}");
    }

    $documentId = agentforge_clinical_smoke_wait_for_document($config['pid'], (int) $categoryId, $fileName, $preUploadMaxDocumentId);
    $job = agentforge_clinical_smoke_wait_for_job($documentId, $docType, $config['job_timeout_s'], $config['poll_interval_ms']);

    return [
        'doc_type' => $docType,
        'category' => $categoryName,
        'document_ref' => agentforge_clinical_smoke_ref('document', $documentId),
        'job_ref' => agentforge_clinical_smoke_ref('job', agentforge_clinical_smoke_mixed_to_int($job['id'] ?? null)),
        'job_status' => $job['status'],
    ];
}

function agentforge_clinical_smoke_max_document_id(int $pid, int $categoryId): int
{
    return (int) agentforge_clinical_smoke_query_scalar(
        'SELECT COALESCE(MAX(d.id), 0) FROM documents d ' .
        'INNER JOIN categories_to_documents ctd ON ctd.document_id = d.id ' .
        'WHERE d.foreign_id = ' . $pid . ' AND ctd.category_id = ' . $categoryId
    );
}

function agentforge_clinical_smoke_wait_for_document(int $pid, int $categoryId, string $fileName, int $afterDocumentId): int
{
    $deadline = time() + 30;
    do {
        $documentId = agentforge_clinical_smoke_query_scalar(
            'SELECT COALESCE(MAX(d.id), 0) FROM documents d ' .
            'INNER JOIN categories_to_documents ctd ON ctd.document_id = d.id ' .
            'WHERE d.foreign_id = ' . $pid . ' AND ctd.category_id = ' . $categoryId .
            ' AND d.id > ' . $afterDocumentId .
            " AND d.name = '" . agentforge_clinical_smoke_sql_quote($fileName) . "' AND d.deleted = 0"
        );
        if ((int) $documentId > 0) {
            return (int) $documentId;
        }
        usleep(500_000);
    } while (time() < $deadline);

    throw new RuntimeException("uploaded document was not found for {$fileName}");
}

/**
 * @return array<string, mixed>
 */
function agentforge_clinical_smoke_wait_for_job(int $documentId, string $docType, int $timeoutS, int $pollIntervalMs): array
{
    $deadline = time() + $timeoutS;
    do {
        $json = agentforge_clinical_smoke_query_scalar(
            "SELECT JSON_OBJECT('id', id, 'status', status, 'error_code', error_code) " .
            'FROM clinical_document_processing_jobs WHERE document_id = ' . $documentId .
            " AND doc_type = '" . agentforge_clinical_smoke_sql_quote($docType) . "' ORDER BY id DESC LIMIT 1"
        );
        $job = json_decode($json, true);
        if (is_array($job) && in_array($job['status'] ?? null, ['succeeded', 'failed', 'retracted'], true)) {
            if (($job['status'] ?? null) !== 'succeeded') {
                throw new RuntimeException("clinical document job {$documentId}/{$docType} reached {$job['status']}");
            }

            return $job;
        }
        usleep(max(250, $pollIntervalMs) * 1000);
    } while (time() < $deadline);

    throw new RuntimeException("clinical document job {$documentId}/{$docType} did not finish before timeout");
}

/**
 * @return array<string, mixed>
 */
function agentforge_clinical_smoke_runtime_snapshot(): array
{
    $json = agentforge_clinical_smoke_query_scalar(
        "SELECT JSON_OBJECT(" .
        "'mariadb_version', VERSION(), " .
        "'worker_status', COALESCE((SELECT status FROM clinical_document_worker_heartbeats WHERE worker = 'intake-extractor' LIMIT 1), 'missing'), " .
        "'pending', (SELECT COUNT(*) FROM clinical_document_processing_jobs WHERE status = 'pending' AND retracted_at IS NULL), " .
        "'running', (SELECT COUNT(*) FROM clinical_document_processing_jobs WHERE status = 'running' AND retracted_at IS NULL), " .
        "'succeeded', (SELECT COUNT(*) FROM clinical_document_processing_jobs WHERE status = 'succeeded'))"
    );
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $payload
 * @return list<string>
 */
function agentforge_clinical_smoke_evaluate_question(array $payload): array
{
    $issues = [];
    if (($payload['status'] ?? null) !== 'ok') {
        $issues[] = 'expected status ok';
    }
    $counts = agentforge_clinical_smoke_citation_counts($payload);
    if (($counts['clinical_document'] ?? 0) < 1) {
        $issues[] = 'expected at least one clinical-document citation';
    }
    if (($counts['guideline'] ?? 0) < 1) {
        $issues[] = 'expected at least one guideline citation';
    }

    return $issues;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, int>
 */
function agentforge_clinical_smoke_citation_counts(array $payload): array
{
    $counts = ['clinical_document' => 0, 'guideline' => 0, 'other' => 0];

    // citation_details contains the rich metadata arrays with source_type;
    // citations is a list<string> of opaque IDs (not useful for type counting).
    $details = is_array($payload['citation_details'] ?? null) ? $payload['citation_details'] : [];
    foreach ($details as $detail) {
        if (!is_array($detail)) {
            continue;
        }
        $sourceType = (string) ($detail['source_type'] ?? $detail['type'] ?? '');
        if (str_contains($sourceType, 'guideline')) {
            $counts['guideline']++;
        } elseif (str_contains($sourceType, 'document') || str_contains($sourceType, 'lab_pdf') || str_contains($sourceType, 'intake')) {
            $counts['clinical_document']++;
        } else {
            $counts['other']++;
        }
    }

    return $counts;
}

/**
 * @param array<mixed> $artifact
 * @return array<mixed>
 */
function agentforge_clinical_smoke_redact_artifact(array $artifact): array
{
    return agentforge_clinical_smoke_redact_value($artifact);
}

function agentforge_clinical_smoke_query_scalar(string $sql): string
{
    $composeFile = agentforge_deployed_smoke_compose_file_path();
    $dbUser = agentforge_scripts_env_string('AGENTFORGE_DB_USER', 'root');
    $dbPass = agentforge_scripts_env_string('AGENTFORGE_DB_PASS', 'root');
    $dbName = agentforge_scripts_env_string('AGENTFORGE_DB_NAME', 'openemr');
    $command = sprintf(
        'docker compose -f %s exec -T mysql mariadb --batch --skip-column-names --user=%s --password=%s %s --execute=%s',
        escapeshellarg($composeFile),
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName),
        escapeshellarg($sql),
    );

    $sshHost = getenv('AGENTFORGE_VM_SSH_HOST');
    if (is_string($sshHost) && $sshHost !== '' && !in_array(strtolower($sshHost), ['local', 'docker-compose'], true)) {
        $remoteRepoDirEnv = getenv('AGENTFORGE_REPO_DIR');
        $remoteRepoDir = is_string($remoteRepoDirEnv) && $remoteRepoDirEnv !== ''
            ? escapeshellarg($remoteRepoDirEnv)
            : '~/repos/openemr';
        $remoteComposeDir = agentforge_scripts_env_string('AGENTFORGE_COMPOSE_DIR', 'docker/development-easy');
        $remoteCommand = sprintf(
            'cd %s && docker compose -f %s exec -T mysql mariadb --batch --skip-column-names --user=%s --password=%s %s --execute=%s',
            $remoteRepoDir,
            escapeshellarg($remoteComposeDir . '/docker-compose.yml'),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($sql),
        );
        $command = sprintf('ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s', escapeshellarg($sshHost), escapeshellarg($remoteCommand));
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start database probe');
    }
    $stdout = trim(stream_get_contents($pipes[1]) ?: '');
    $stderr = trim(stream_get_contents($pipes[2]) ?: '');
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('database probe failed: ' . ($stderr !== '' ? $stderr : 'no output'));
    }

    return $stdout;
}

function agentforge_clinical_smoke_sql_quote(string $value): string
{
    return str_replace("'", "''", $value);
}

function agentforge_clinical_smoke_ref(string $type, int $id): string
{
    return $type . ':' . substr(hash('sha256', $type . ':' . $id), 0, 16);
}

function agentforge_clinical_smoke_mixed_to_int(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && is_numeric($value)) {
        return (int) $value;
    }

    return 0;
}

/**
 * @return mixed
 */
function agentforge_clinical_smoke_redact_value(mixed $value): mixed
{
    $forbiddenKeys = ['question_text', 'answer', 'raw_value', 'quote', 'quote_or_value', 'document_text', 'password', 'DOB', 'fname', 'lname'];
    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $forbiddenKeys, true)) {
                $redacted['redacted_field'] = '[redacted]';
                continue;
            }
            $redacted[$key] = agentforge_clinical_smoke_redact_value($item);
        }

        return $redacted;
    }

    if (!is_string($value)) {
        return $value;
    }

    $patterns = [
        '/What changed in the uploaded labs and intake form[^"]*/i',
        '/p01-chen-[A-Za-z0-9._-]+/i',
        '/[A-Z][a-z]+ Chen/',
        '/\b\d{4}-\d{2}-\d{2}\b/',
    ];

    return preg_replace($patterns, '[redacted]', $value) ?? '[redacted]';
}

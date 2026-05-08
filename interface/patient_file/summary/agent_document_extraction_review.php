<?php

/**
 * JSON extraction-review payload for an AgentForge clinical document.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

ob_start();

require_once("../../globals.php");

use OpenEMR\AgentForge\Document\StrictPositiveInt;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;

$json = static function (array $payload, int $status): never {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

$documentId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'document_id'));

if ($documentId === null) {
    $json(['status' => 'error', 'message' => 'Extraction review needs a document.'], 400);
}

if (!AclMain::aclCheckCore('patients', 'med')) {
    $json(['status' => 'error', 'message' => 'Extraction review could not be loaded.'], 403);
}

$extractionTable = QueryUtils::fetchRecords("SHOW TABLES LIKE 'clinical_document_processing_jobs'");
if ($extractionTable === []) {
    $json(['status' => 'error', 'message' => 'AgentForge extraction tables are not installed.'], 404);
}

$jobs = QueryUtils::fetchRecords(
    'SELECT j.id, j.patient_id, j.document_id, j.doc_type, j.status, j.attempts, j.created_at, j.started_at, '
    . 'j.finished_at, j.error_code, j.error_message, j.retracted_at, d.name AS document_name, d.deleted AS document_deleted '
    . 'FROM clinical_document_processing_jobs j '
    . 'LEFT JOIN documents d ON d.id = j.document_id '
    . 'WHERE j.document_id = ? '
    . 'ORDER BY j.created_at ASC, j.id ASC',
    [$documentId],
);

if ($jobs === []) {
    $json(['status' => 'error', 'message' => 'No AgentForge extraction job was found for this document.'], 404);
}

$activeJobs = array_values(array_filter(
    $jobs,
    static fn (array $job): bool => trim((string) ($job['retracted_at'] ?? '')) === ''
        && (int) ($job['document_deleted'] ?? 0) === 0,
));
if ($activeJobs !== []) {
    $jobs = $activeJobs;
}

$documentPatientId = StrictPositiveInt::tryParse($jobs[0]['patient_id'] ?? null);
if ($documentPatientId === null) {
    $json(['status' => 'error', 'message' => 'Extraction review could not identify the document patient.'], 404);
}

$facts = QueryUtils::fetchRecords(
    'SELECT f.id, f.job_id, f.doc_type, f.fact_type, f.certainty, f.fact_text, f.structured_value_json, '
    . 'f.citation_json, f.confidence, f.promotion_status, f.active, f.created_at, f.retracted_at, f.retraction_reason, '
    . 'p.outcome, p.promoted_table, p.promoted_record_id, p.conflict_reason, p.review_status '
    . 'FROM clinical_document_facts f '
    . 'LEFT JOIN clinical_document_promotions p ON p.patient_id = f.patient_id '
    . 'AND p.document_id = f.document_id '
    . 'AND p.job_id = f.job_id '
    . 'AND p.fact_fingerprint = f.fact_fingerprint '
    . 'AND p.active = 1 '
    . 'WHERE f.patient_id = ? '
    . 'AND f.document_id = ? '
    . 'AND f.active = 1 '
    . 'AND f.retracted_at IS NULL '
    . 'AND f.deactivated_at IS NULL '
    . 'ORDER BY f.created_at ASC, f.id ASC',
    [$documentPatientId, $documentId],
);

$decode = static function (mixed $jsonValue): array {
    if (!is_string($jsonValue) || trim($jsonValue) === '') {
        return [];
    }

    try {
        $decoded = json_decode($jsonValue, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
};

$string = static fn (array $row, string $key): string => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
$positiveInt = static function (array $row, string $key): ?int {
    $value = $row[$key] ?? null;
    if (!is_numeric($value)) {
        return null;
    }

    $int = (int) $value;
    return $int > 0 ? $int : null;
};

$displayValue = static function (array $row, array $structured) use ($string): string {
    $text = $string($row, 'fact_text');
    if ($text !== '') {
        return $text;
    }

    foreach (['display_value', 'value', 'quote_or_value', 'test_name', 'field_value'] as $key) {
        if (is_scalar($structured[$key] ?? null) && trim((string) $structured[$key]) !== '') {
            return trim((string) $structured[$key]);
        }
    }

    return '';
};

$reviewStatus = static function (array $row) use ($string): string {
    $promotion = $string($row, 'outcome') ?: $string($row, 'promotion_status');
    if ($promotion === 'promoted') {
        return 'Promoted';
    }

    if ($promotion === 'already_exists') {
        return 'Already exists';
    }

    if ($promotion === 'needs_review') {
        return 'Needs review';
    }

    if ($promotion === 'not_promotable' || $promotion === 'document_fact') {
        return 'Document only';
    }

    if ($promotion === 'duplicate_skipped') {
        return 'Duplicate skipped';
    }

    return $promotion === '' ? 'Document only' : ucfirst(str_replace('_', ' ', $promotion));
};

$factPayloads = [];
$counts = [
    'promoted' => 0,
    'document_only' => 0,
    'needs_review' => 0,
    'duplicate_or_skipped' => 0,
];

foreach ($facts as $row) {
    $structured = $decode($row['structured_value_json'] ?? null);
    $citation = $decode($row['citation_json'] ?? null);
    $outcome = $string($row, 'outcome') ?: $string($row, 'promotion_status');
    if ($outcome === 'promoted') {
        $counts['promoted']++;
    } elseif ($outcome === 'needs_review') {
        $counts['needs_review']++;
    } elseif ($outcome === 'duplicate_skipped' || $outcome === 'already_exists') {
        $counts['duplicate_or_skipped']++;
    } else {
        $counts['document_only']++;
    }

    $factId = $positiveInt($row, 'id');
    $jobId = $positiveInt($row, 'job_id');
    $factPayloads[] = [
        'id' => $factId,
        'job_id' => $jobId,
        'doc_type' => $string($row, 'doc_type'),
        'fact_type' => $string($row, 'fact_type'),
        'label' => $string($row, 'fact_type') ?: $string($row, 'doc_type'),
        'value' => $displayValue($row, $structured),
        'status' => $reviewStatus($row),
        'outcome' => $outcome,
        'promoted_table' => $string($row, 'promoted_table'),
        'promoted_record_id' => $string($row, 'promoted_record_id'),
        'reason' => $string($row, 'conflict_reason'),
        'review_status' => $string($row, 'review_status'),
        'confidence' => is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null,
        'citation' => [
            'page_or_section' => $string($citation, 'page_or_section'),
            'field_or_chunk_id' => $string($citation, 'field_or_chunk_id'),
            'quote_or_value' => $string($citation, 'quote_or_value'),
        ],
        'source_review_url' => ($jobId !== null && $factId !== null)
            ? 'agent_document_source_review.php?document_id=' . rawurlencode((string) $documentId)
                . '&job_id=' . rawurlencode((string) $jobId)
                . '&fact_id=' . rawurlencode((string) $factId)
            : '',
    ];
}

$json([
    'status' => 'ok',
    'document' => [
        'id' => $documentId,
        'name' => $string($jobs[0], 'document_name'),
    ],
    'jobs' => array_map(static function (array $row) use ($string, $positiveInt): array {
        return [
            'id' => $positiveInt($row, 'id'),
            'doc_type' => $string($row, 'doc_type'),
            'status' => $string($row, 'status'),
            'attempts' => is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : 0,
            'created_at' => $string($row, 'created_at'),
            'started_at' => $string($row, 'started_at'),
            'finished_at' => $string($row, 'finished_at'),
            'error_code' => $string($row, 'error_code'),
            'error_message' => $string($row, 'error_message'),
            'retracted_at' => $string($row, 'retracted_at'),
        ];
    }, $jobs),
    'counts' => $counts,
    'facts' => $factPayloads,
], 200);

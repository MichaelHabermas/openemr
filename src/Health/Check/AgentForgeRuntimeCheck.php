<?php

/**
 * AgentForgeRuntimeCheck - Week 2 deployment runtime readiness.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Health\Check;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Health\HealthCheckInterface;
use OpenEMR\Health\HealthCheckResult;

class AgentForgeRuntimeCheck implements HealthCheckInterface
{
    public const NAME = 'agentforge_runtime';
    private const REQUIRED_MARIADB_PREFIX = '11.8';
    private const DEFAULT_WORKER = 'intake-extractor';
    private const DEFAULT_HEARTBEAT_MAX_AGE_SECONDS = 120;
    private const DEFAULT_OLDEST_PENDING_MAX_AGE_SECONDS = 300;
    private const DEFAULT_STALE_RUNNING_SECONDS = 600;

    public function getName(): string
    {
        return self::NAME;
    }

    public function check(): HealthCheckResult
    {
        try {
            if (!$this->clinicalDocumentTablesExist()) {
                return new HealthCheckResult(
                    $this->getName(),
                    true,
                    'AgentForge clinical document runtime is not configured.',
                    [
                        'status' => 'not_configured',
                    ],
                );
            }
            $mariadb = self::evaluateMariaDb($this->fetchMariaDbVersion());
            $worker = $this->evaluateWorkerHeartbeat($this->fetchWorkerHeartbeat());
            $queue = $this->evaluateQueue($this->fetchQueueSummary());
            $healthy = $mariadb['healthy'] === true
                && $worker['healthy'] === true
                && $queue['healthy'] === true;

            return new HealthCheckResult(
                $this->getName(),
                $healthy,
                $healthy ? null : 'AgentForge runtime is not ready.',
                [
                    'mariadb' => $mariadb,
                    'worker' => $worker,
                    'queue' => $queue,
                ],
            );
        } catch (SqlQueryException $e) {
            return new HealthCheckResult(
                $this->getName(),
                false,
                'AgentForge runtime check failed.',
                [
                    'mariadb' => self::unhealthyComponent('unavailable'),
                    'worker' => self::unhealthyComponent('unavailable'),
                    'queue' => self::unhealthyComponent('unavailable'),
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function evaluateMariaDb(string $version): array
    {
        $healthy = self::isCompatibleMariaDbVersion($version);

        return [
            'healthy' => $healthy,
            'required_version' => self::REQUIRED_MARIADB_PREFIX,
            'version' => $version,
            'vector_expected' => true,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    public function evaluateWorkerHeartbeat(?array $row): array
    {
        $threshold = self::envInt('AGENTFORGE_WORKER_HEARTBEAT_MAX_AGE_SECONDS', self::DEFAULT_HEARTBEAT_MAX_AGE_SECONDS);
        $workerName = self::envString('AGENTFORGE_WORKER_NAME', self::DEFAULT_WORKER);
        if ($row === null) {
            return [
                'healthy' => false,
                'worker' => $workerName,
                'status' => 'missing',
                'fresh' => false,
                'freshness_threshold_seconds' => $threshold,
                'last_heartbeat_age_seconds' => null,
                'jobs_processed' => 0,
                'jobs_failed' => 0,
            ];
        }

        $status = is_string($row['status'] ?? null) ? $row['status'] : 'unknown';
        $age = array_key_exists('last_heartbeat_age_seconds', $row)
            ? self::mixedToInt($row['last_heartbeat_age_seconds'])
            : null;
        $stoppedAt = $row['stopped_at'] ?? null;
        $fresh = $age !== null && $age <= $threshold;
        $runningStatus = in_array($status, ['starting', 'running', 'idle'], true);
        $notStopped = $stoppedAt === null || $stoppedAt === '' || $stoppedAt === '0000-00-00 00:00:00';

        return [
            'healthy' => $fresh && $runningStatus && $notStopped,
            'worker' => is_string($row['worker'] ?? null) ? $row['worker'] : $workerName,
            'status' => $status,
            'fresh' => $fresh,
            'freshness_threshold_seconds' => $threshold,
            'last_heartbeat_age_seconds' => $age,
            'jobs_processed' => self::mixedToInt($row['jobs_processed'] ?? null),
            'jobs_failed' => self::mixedToInt($row['jobs_failed'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function evaluateQueue(array $row): array
    {
        $oldestPendingLimit = self::envInt('AGENTFORGE_QUEUE_OLDEST_PENDING_MAX_AGE_SECONDS', self::DEFAULT_OLDEST_PENDING_MAX_AGE_SECONDS);
        $pending = self::mixedToInt($row['pending'] ?? null);
        $running = self::mixedToInt($row['running'] ?? null);
        $failedRecent = self::mixedToInt($row['failed_recent'] ?? null);
        $rawOldestPendingAge = $row['oldest_pending_age_seconds'] ?? null;
        $oldestPendingAge = $rawOldestPendingAge === null
            ? null
            : self::mixedToInt($rawOldestPendingAge);
        $staleRunning = self::mixedToInt($row['stale_running'] ?? null);

        return [
            'healthy' => $staleRunning === 0 && ($oldestPendingAge === null || $oldestPendingAge <= $oldestPendingLimit),
            'pending' => $pending,
            'running' => $running,
            'failed_recent' => $failedRecent,
            'failed_recent_affects_health' => false,
            'oldest_pending_age_seconds' => $oldestPendingAge,
            'oldest_pending_threshold_seconds' => $oldestPendingLimit,
            'stale_running' => $staleRunning,
        ];
    }

    private function fetchMariaDbVersion(): string
    {
        $rows = QueryUtils::fetchRecords('SELECT VERSION() AS version', [], noLog: true);

        return is_string($rows[0]['version'] ?? null) ? $rows[0]['version'] : 'unknown';
    }

    private function clinicalDocumentTablesExist(): bool
    {
        $rows = QueryUtils::fetchRecords(
            "SHOW TABLES LIKE 'clinical_document_processing_jobs'",
            [],
            noLog: true,
        );
        if ($rows === []) {
            return false;
        }

        $rows = QueryUtils::fetchRecords(
            "SHOW TABLES LIKE 'clinical_document_worker_heartbeats'",
            [],
            noLog: true,
        );

        return $rows !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWorkerHeartbeat(): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT worker, status, last_heartbeat_at, ' .
            'TIMESTAMPDIFF(SECOND, last_heartbeat_at, NOW()) AS last_heartbeat_age_seconds, ' .
            'stopped_at, jobs_processed, jobs_failed ' .
            'FROM clinical_document_worker_heartbeats WHERE worker = ? LIMIT 1',
            [self::envString('AGENTFORGE_WORKER_NAME', self::DEFAULT_WORKER)],
            noLog: true,
        );

        $row = $rows[0] ?? null;

        return is_array($row) ? self::stringKeyed($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchQueueSummary(): array
    {
        $staleRunningSeconds = self::envInt('AGENTFORGE_QUEUE_STALE_RUNNING_SECONDS', self::DEFAULT_STALE_RUNNING_SECONDS);
        $rows = QueryUtils::fetchRecords(
            'SELECT ' .
            "COALESCE(SUM(status = 'pending' AND retracted_at IS NULL), 0) AS pending, " .
            "COALESCE(SUM(status = 'running' AND retracted_at IS NULL), 0) AS running, " .
            "COALESCE(SUM(status = 'failed' AND finished_at >= NOW() - INTERVAL 15 MINUTE), 0) AS failed_recent, " .
            "TIMESTAMPDIFF(SECOND, MIN(CASE WHEN status = 'pending' AND retracted_at IS NULL THEN created_at END), NOW()) AS oldest_pending_age_seconds, " .
            "COALESCE(SUM(status = 'running' AND retracted_at IS NULL AND started_at < NOW() - INTERVAL " . $staleRunningSeconds . ' SECOND), 0) AS stale_running ' .
            'FROM clinical_document_processing_jobs',
            [],
            noLog: true,
        );

        $row = $rows[0] ?? null;

        return is_array($row) ? self::stringKeyed($row) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function unhealthyComponent(string $status): array
    {
        return [
            'healthy' => false,
            'status' => $status,
        ];
    }

    private static function mixedToInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function isCompatibleMariaDbVersion(string $version): bool
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $matches) !== 1) {
            return false;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];

        return $major > 11 || ($major === 11 && $minor >= 8);
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return max(1, (int) $value);
    }

    private static function envString(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

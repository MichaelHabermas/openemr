#!/usr/bin/env php
<?php

/**
 * Run the AgentForge Tier 4 deployed-smoke runner.
 *
 * Required environment:
 *   AGENTFORGE_SMOKE_USER       — login user provisioned with chart access to
 *                                 the demo patient.
 *   AGENTFORGE_SMOKE_PASSWORD   — the user's password.
 *
 * Optional:
 *   AGENTFORGE_DEPLOYED_URL          — defaults to the documented public URL.
 *   AGENTFORGE_SMOKE_PRIMARY_PID     — defaults to 900001.
 *   AGENTFORGE_SMOKE_SECONDARY_PID   — required to exercise the cross-patient
 *                                      conversation-reuse case; otherwise that
 *                                      case is recorded as skipped.
 *   AGENTFORGE_VM_SSH_HOST           — SSH alias or user@host for the VM. Used
 *                                      to grep the deployed PSR-3 audit log;
 *                                      required unless AGENTFORGE_SMOKE_SKIP_AUDIT_LOG=1.
 *   AGENTFORGE_VM_AUDIT_LOG_PATH     — defaults to /var/log/php-error.log.
 *   AGENTFORGE_SMOKE_SKIP_AUDIT_LOG  — set to a truthy value to skip audit-log
 *                                      assertions (only for local dry-runs).
 *   AGENTFORGE_SMOKE_TIMEOUT_S       — per-request timeout in seconds (default 90).
 *   AGENTFORGE_EVAL_RESULTS_DIR      — output directory for the result JSON.
 *   AGENTFORGE_SMOKE_EXECUTOR        — free-form tag captured in the result file
 *                                      (e.g. "github-actions", "local").
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/deployed-smoke-runner.php';

exit(agentforge_deployed_smoke_main());

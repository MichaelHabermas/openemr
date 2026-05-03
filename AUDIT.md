# Audit - First Principles Reset

## Summary

This audit is rebuilt from observed repository evidence only. The prior audit document is not treated as source of truth. The goal is not to catalog every possible weakness in OpenEMR. The goal is to identify the minimum set of system facts that matter before adding a Clinical Co-Pilot that reads patient data and returns patient-specific answers.

The strongest finding is that OpenEMR's authorization model is capability-oriented, not patient-resource-oriented. `AclMain::aclCheckCore()` checks a section, value, user, and optional access type. It does not receive a patient, encounter, or chart resource identifier. REST route authorization passes the session user into that same coarse ACL check. `PatientService::search()` builds patient queries from caller-provided search fields; in the audited code path, no provider, care-team, or patient-assignment predicate is added by the service layer. Therefore, a future agent cannot treat existing service calls as answering the question "may this user access this patient?" That boundary must be designed explicitly.

The second load-bearing finding is that identity is session-bound. The ACL path reads the active user from the HTTP session when no user is provided. Existing OpenEMR code is built around synchronous request handling, session globals, and shared database helpers. This matters because an agent, worker, or sidecar process does not automatically have a trustworthy OpenEMR principal. If the agent runs outside the normal request path, identity and audit attribution must be passed deliberately.

The third finding is that PHI read auditing is configurable rather than guaranteed. `EventAuditLogger` reads global flags such as `audit_events_query` and category toggles. When query auditing is off, SELECT events return before being logged. A read-only agent that only queries clinical data could therefore become invisible to the audit log unless deployment configuration and agent-specific logging close that gap.

The fourth finding is that clinical data quality is uneven at the schema level. `sql/database.sql` contains no enforced foreign keys. Major clinical tables use nullable or free-text fields for values the agent would need to cite. The polymorphic `lists` table holds problems, allergies, medications, and other clinical concepts under a free-text `type` column with no enumeration constraint, so every disclosure must cite both row id and `type` to remain auditable, and a missing `type` filter could surface unrelated chart concepts. `prescriptions` is separate from medication rows in `lists`, coded medication fields such as RxNorm are optional, and many patient demographic fields use empty strings as defaults. The agent must treat chart data as source material with gaps, not as clean ground truth.

The fifth finding is that performance cannot be treated as known. The schema has indexes for some common patient lookups, but several agent-relevant reads do not have obvious composite indexes, such as active prescriptions by patient or list entries by patient/type/activity. Without `lists(pid, type, activity)`, an active-medications read scans every list row for the patient and filters by `type` and `activity` after the fetch. The single A1c demo measurement captured in `agent-forge/docs/operations/COST-ANALYSIS.md` ran `2,989 ms` locally and `10,693 ms` on the deployed VM — baseline single-request observations, not p95 under load — and this audit did not run production-scale benchmarks, so performance claims remain limited to schema shape and those single-request timings.

The resulting rule for the agent plan is simple: read narrowly, verify every claim, cite source rows, log every agent read, and fail closed when patient identity, authorization, or source data is unclear.

## Method

Only these evidence types are accepted:

- `SPECS.txt`
- repository source files
- repository schema files
- observed local command output

Anything else is treated as unverified.

## Current Implementation Status

This audit remains the source of record for the risks that drove the first AgentForge implementation, but several findings are not closed.

Already implemented:

- The agent uses a narrow fail-closed patient authorization gate before evidence reads.
- Evidence tools use server-controlled, parameterized, patient-scoped reads.
- The demo path records request metadata, total latency, source IDs, token usage, estimated cost, and verifier result.
- Tier 0 fixture and Tier 1 SQL evidence eval suites run on every PR via `.github/workflows/agentforge-evals.yml`; Tier 2 live-LLM evals (12 cases including refusals, hallucination pressure, and prompt injection) run nightly and on demand via `.github/workflows/agentforge-tier2.yml`, with results in `agent-forge/eval-results/tier2-live-*.json`.
- Tier 4 deployed-smoke proof of the full HTTP/session/CSRF/audit-log path runs nightly and post-deploy via `.github/workflows/agentforge-deployed-smoke.yml` (`php agent-forge/scripts/run-deployed-smoke.php`). The runner exercises Apache, the `agent_request.php` controller, real session establishment, CSRF validation, and the deployed PSR-3 `agent_forge_request` audit-log line — none of which are exercised by Tier 0/1/2 or by the in-container `run-evals-vm.sh`. Results live in `agent-forge/eval-results/deployed-smoke-*.json`.

Accepted v1 limitations:

- Authorization currently covers direct provider, encounter provider, and supervisor relationships only. Care-team membership, facility scope, schedule-derived access, group assignment, and broader delegation are unavailable and must fail closed.
- Medication evidence now checks `prescriptions`, active medication rows in `lists`, and linked `lists_medication` extension rows where available. It still treats duplicates, conflicts, uncoded rows, and missing instructions as chart evidence rather than reconciled clinical truth.
- Performance P1 has documented candidate indexes but no migration in this pass; composite-index implementation still requires query-plan proof and OpenEMR migration review.
- Observability is structured logging plus per-stage timings, not full observability. Aggregation, dashboards or queries, SLOs, and alerting are unavailable.

## Security

### S1. Authorization is coarse and does not receive a patient resource

**Finding:** The core ACL check does not take a patient, encounter, or resource identifier.

**Evidence:**

- `src/Common/Acl/AclMain.php:166-181` defines `aclCheckCore($section, $value, $user = '', $return_value = '')`.
- `src/RestControllers/Config/RestConfig.php:180-187` passes the session user into `AclMain::aclCheckCore()`.
- `src/Services/PatientService.php:418-442` begins patient search query construction without a visible principal or resource-scoping parameter.

**Risk for the agent:** A user who can call a patient-read capability may still need a separate patient-specific authorization check. The agent cannot rely on coarse ACL alone.

### S2. Identity is tied to the HTTP session

**Finding:** If no user is passed to `AclMain::aclCheckCore()`, it reads `authUser` from the active session.

**Evidence:**

- `src/Common/Acl/AclMain.php:168-171` calls `SessionWrapperFactory::getInstance()->getActiveSession()` and reads `authUser`.
- `src/RestControllers/Config/RestConfig.php:180-182` also reads `authUser` from the request session before authorization.

**Risk for the agent:** A sidecar, queue worker, CLI process, or separate agent service has no implicit OpenEMR user. It must receive identity from OpenEMR deliberately and must not invent its own.

### S3. Browser-facing security posture is partly configuration and convention

**Finding:** Core session defaults and CORS behavior create security constraints for any embedded agent UI.

**Evidence:**

- `src/Common/Session/SessionConfigurationBuilder.php:20-27` defaults `cookie_secure` to `false`.
- `src/Common/Session/SessionConfigurationBuilder.php:83-90` sets core UI cookies to `HttpOnly(false)`.
- `src/RestControllers/Subscriber/CORSListener.php:53-57` reflects the request `Origin` into `Access-Control-Allow-Origin`.
- `src/Common/Twig/TwigContainer.php:67-70` creates Twig with `autoescape => false`.

**Risk for the agent:** The agent UI should minimize browser trust assumptions. It should not store PHI or bearer tokens in long-lived browser storage, and any embedded surface must be careful about XSS, origin, and session exposure.

## Architecture

### A1. OpenEMR mixes legacy procedural code with service classes

**Finding:** Modern services still call legacy includes and global SQL helpers.

**Evidence:**

- `src/Services/EncounterService.php:39-40` requires files from `library/`.
- `src/Services/EncounterService.php:449` calls `sqlQuery()`.
- `library/sql.inc.php:56-63` creates an ADODB connection and stores it in `$GLOBALS`.
- `library/sql.inc.php:96-102` exposes `sqlStatement()` as a global helper.

**Risk for the agent:** Integration should be small and isolated. The agent plan should avoid broad rewrites of OpenEMR internals during this project window.

### A2. OpenEMR has two relevant request shapes

**Finding:** The codebase includes direct PHP UI entry points and REST/FHIR routes, both sharing lower-level data/session infrastructure.

**Evidence:**

- `apis/dispatch.php` is the REST/FHIR entry point.
- `interface/` contains direct UI PHP entry points.
- Both paths ultimately use shared session and database helpers observed above.

**Risk for the agent:** The architecture must choose one integration path intentionally. It should not treat the codebase as one uniform application framework.

## Performance

### P1. Agent-relevant reads may need narrow queries and indexes

**Finding:** Some agent-relevant reads are not directly supported by composite indexes in the schema.

**Evidence:**

- `sql/database.sql:7671-7711` defines `lists` with indexes on `pid` and `type`, but no composite index covering `pid`, `type`, and `activity`.
- `sql/database.sql:8698-8750` defines `prescriptions` with an index on `patient_id`, but no composite index covering `patient_id` and `active`.
- `sql/database.sql:2022-2061` defines `form_encounter` with `pid_encounter` and `encounter_date` indexes.

**Risk for the agent:** The first implementation should use a small number of bounded, patient-specific queries. Broad chart scans, panel-wide precomputation, and open-ended search are not implemented.

**Current status:** Documented, not migrated. The active agent predicates are:

- Active prescriptions: `prescriptions.patient_id = ? AND prescriptions.active = 1`, candidate composite index `prescriptions(patient_id, active)`.
- Active medication-list entries: `lists.pid = ? AND lists.type = 'medication' AND lists.activity = 1`, candidate composite index `lists(pid, type, activity)`.
- Linked medication extensions: `lists_medication.list_id = lists.id`, already supported by `lists_medication_list_idx`.
- Recent notes and encounters: current query remains bounded by `form_clinical_notes.pid` and joins `form_encounter`; future index work must inspect deployed `EXPLAIN` output before proposing changes.

No migration is created in this pass. Future implementation must capture before/after `EXPLAIN`, review the migration against OpenEMR conventions, and document rollback.

### P2. No runtime latency benchmark has been established

**Finding:** This audit verifies schema shape, not production latency.

**Evidence:** No benchmark output or deployed telemetry is included in this bare-bones audit.

**Risk for the agent:** Any response-time target must be treated as an implementation goal, not an observed fact, until measured.

**Current status:** A single local A1c request and a single public VM A1c request have been measured in `agent-forge/docs/operations/COST-ANALYSIS.md`, with the local path at `2,989 ms` and the VM path at `10,693 ms`. These are baseline observations, not a production latency benchmark. Stage timing now records evidence-tool, draft, and verification durations in `stage_timings_ms`, decomposed in `agent-forge/docs/operations/LATENCY-DECOMPOSITION.md`; production-readiness claims still require aggregation, p95 proof under the accepted latency budget, SLOs, alerting, and an optimization plan.

## Data Quality

### D1. The schema does not enforce foreign keys

**Finding:** No `FOREIGN KEY` or `REFERENCES` constraints were found in `sql/database.sql`.

**Evidence:**

- `rg -n 'FOREIGN KEY|REFERENCES' sql/database.sql` returned no matches.
- Schema comments reference relationships, for example `lists_medication.list_id` comments on `sql/database.sql:7719`, but comments are not constraints.

**Risk for the agent:** The agent must handle orphaned, missing, or inconsistent rows without inventing clinical facts.

### D2. Clinical concepts are stored in flexible, weakly constrained fields

**Finding:** The `lists` table stores multiple clinical concept types using a free-text `type` column.

**Evidence:**

- `sql/database.sql:7671-7711` defines `lists`.
- `sql/database.sql:7675` defines `type varchar(255) default NULL`.
- `sql/database.sql:7688` defines `activity tinyint(4) default NULL`.

**Risk for the agent:** Problems, allergies, and medication-list records may require type-specific filters and careful handling of null or inactive rows.

### D3. Medication data exists across more than one table shape

**Finding:** Medication-related data appears in `lists`, `lists_medication`, and `prescriptions`.

**Evidence:**

- `sql/database.sql:7671-7711` defines `lists`.
- `sql/database.sql:7717-7735` defines `lists_medication`, including optional `prescription_id`.
- `sql/database.sql:8698-8750` defines `prescriptions`.

**Risk for the agent:** "Current medications" is not a single trivial table read. The agent must define exactly which medication sources it reads and cite the rows used.

**Current status:** Implemented for bounded evidence coverage. The active medication evidence path checks active rows in `prescriptions`, active medication rows in `lists`, and linked `lists_medication` extension rows where available. Inactive rows are not surfaced as active evidence. Uncoded, duplicate, conflicting, and instruction-missing records are displayed as source-cited chart evidence without reconciliation or medication-change advice.

### D4. Coded fields are optional

**Finding:** Prescription rows include free-text and coded drug fields, but the code field is nullable/defaultable.

**Evidence:**

- `sql/database.sql:8709-8711` defines `drug`, `drug_id`, and nullable `rxnorm_drugcode`.

**Risk for the agent:** Clinical rule checks based on standardized codes may miss records. The first agent should avoid unsupported interaction or dosing claims unless the source data supports them.

### D5. Empty string and unknown are often indistinguishable

**Finding:** Many patient demographic fields are `NOT NULL default ''`.

**Evidence:**

- `sql/database.sql:8335-8415` shows many `patient_data` fields defaulting to empty strings, including name, address, phone, email, sex, and HIPAA preference fields.

**Risk for the agent:** Missing data must be reported as missing or not found. Empty fields should not be interpreted as negative clinical facts.

## Compliance And Regulatory

### C1. PHI read auditing is configurable, not guaranteed by code

**Finding:** SELECT query logging depends on global configuration.

**Evidence:**

- `src/Common/Logging/EventAuditLogger.php:70-83` reads audit flags including `audit_events_query` and event category toggles.
- `src/Common/Logging/EventAuditLogger.php:424-428` returns before logging SELECT statements when query logging is disabled, except for breakglass users.

**Risk for the agent:** A read-only agent can access PHI without producing normal read audit events unless configuration or agent-specific logging is added.

### C2. The audit log has a checksum but no schema-enforced append-only protection

**Finding:** The `log` table has a `checksum` column, but no schema-level immutability control is visible.

**Evidence:**

- `sql/database.sql:7758-7776` defines the `log` table with `checksum` and primary key.
- The schema shown there does not define triggers or append-only constraints.

**Risk for the agent:** The agent should maintain its own request log in addition to relying on OpenEMR's configurable audit behavior.

### C3. Clinical PHI is stored as ordinary schema columns

**Finding:** The audited clinical tables define ordinary plaintext columns for demographics, notes, prescriptions, and encounter data.

**Evidence:**

- `sql/database.sql:8335-8415` defines patient demographic columns.
- `sql/database.sql:8698-8750` defines prescription columns.
- `sql/database.sql:2022-2061` defines encounter columns.

**Risk for the agent:** Deployment-level storage security matters. The agent should not create extra PHI copies unless required, and logs must avoid raw PHI where possible.

### C4. Agent structured audit logs map to HIPAA §164 controls

**Finding:** The agent's structured request log is laid out so each field can be traced to a specific HIPAA Security Rule sub-control, independently of OpenEMR's configurable SELECT logging.

**Evidence:**

| Audit log field | HIPAA control | What it answers |
|---|---|---|
| `request_id`, `user_id`, `patient_id`, timestamp | §164.312(b) Audit controls | Who accessed which chart at what time |
| `decision`, `failure_reason` | §164.308(a)(1)(ii)(D) Information system activity review | What was attempted vs allowed |
| `tools_called`, `source_ids` | §164.312(c)(1) Integrity | What chart facts were surfaced |
| `model`, `input_tokens`, `output_tokens`, `verifier_result` | §164.308(a)(8) Evaluation | Did the agent comply with policy on this request |

Field emission is implemented in `src/AgentForge/Observability/AgentTelemetry.php` and `src/AgentForge/Observability/PsrRequestLogger.php`. Behavior is unchanged by this mapping.

**Risk for the agent:** This mapping documents which sub-control each existing audit field supports; it does not by itself satisfy production HIPAA obligations. Sensitive-log retention, access governance, tamper-evident handling, monitoring, and review cadence remain explicit production-readiness blockers.

## Minimum Agent Constraints From This Audit

- Use demo data only.
- Bind every agent request to a specific OpenEMR user and patient.
- Do not trust coarse ACL as patient-specific authorization.
- Read only the minimum patient rows needed for the answer.
- Use allowlisted, parameterized queries or audited service calls.
- Cite source rows for patient-specific claims.
- Say "not found in the chart" instead of inferring from missing data.
- Log agent reads, tool calls, failures, and source row IDs.
- Avoid diagnosis, treatment advice, medication changes, and unsupported clinical rule claims in the first version.

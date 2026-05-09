# Final Demo Walkthrough Checklist

## 1. Pre-Demo Environment

- [ ] Deployed app accessible at stable URL (HTTPS)
- [ ] Login with `admin` / `pass` succeeds
- [ ] Docker services healthy: `docker compose ps` shows `openemr`, `mysql`, `agentforge-worker` running
- [ ] `/meta/health/readyz` returns `status: ready`, `database: true`, `agentforge_runtime: true`
- [ ] Worker heartbeat shows `intake-extractor` with recent `last_heartbeat_at`
- [ ] Demo patient (pid 900001) exists with seeded chart data

## 2. Recording Setup

- [ ] Screen recording started with audio capture enabled
- [ ] Browser dev tools open (Network + Console tabs visible)
- [ ] Terminal window visible for log tailing / script output

## 3. Ingestion Walkthrough

- [ ] Upload a clinical document (PDF, DOCX, TIFF, XLSX, or HL7 v2) to patient chart
- [ ] Show `clinical_document.job.enqueued` log line with `patient_ref` (hashed, not raw ID)
- [ ] Show worker claiming job: `clinical_document.worker.job_completed` with `trace_id`
- [ ] Show identity verification pass in `clinical_document_identity_checks`
- [ ] Show extracted facts in `clinical_document_facts` table with citation JSON
- [ ] Show fact promotion to native EMR tables (e.g., `procedure_result` for lab values)
- [ ] Show `needs_review` facts flagged for physician review

## 4. Retrieval Walkthrough

- [ ] Ask a chart question that triggers document fact retrieval (e.g., "What are the latest lab results?")
- [ ] Show document-derived facts appearing alongside native EMR data in the response
- [ ] Ask a guideline question (e.g., "What does the ACC/AHA guideline say about LDL follow-up?")
- [ ] Show hybrid retrieval merge telemetry in logs: `sparse_candidate_count`, `dense_candidate_count`, `overlap_count`
- [ ] Show `reranker_used` field identifying which reranker ran (cohere vs deterministic)
- [ ] Verify every factual claim in the response has a citation marker

## 5. Citation and Source Tracing

- [ ] Click through to source review panel for a document-derived fact
- [ ] Show bounding box overlay on the source document page image
- [ ] Show `open_source_url` and `review_url` resolve correctly
- [ ] Show citation metadata: `source_type`, `source_id`, `page_or_section`, `quote_or_value`
- [ ] Show guideline citations with `source_type: guideline` and chunk reference

## 6. Eval Gates

- [ ] Show GitHub Actions CI workflow (`AgentForge Evals`) running on a PR
- [ ] Show that a failing eval case blocks the PR merge
- [ ] Show `thresholds.json`: all 14 rubrics at `1.0` threshold
- [ ] Show `baseline.json`: 65-case baseline with regression blocking (`regression_max_drop_pct: 5`)
- [ ] Show Tier 0 results: 32/32 pass
- [ ] Show Tier 1 SQL evidence results: 7/7 pass
- [ ] Show Clinical Document Gate results: 65/65 pass across 14 rubrics

## 7. Traces and Orchestration

- [ ] Show `trace_id` (UUID v4) in structured log output linking operations
- [ ] Show supervisor routing in `clinical_supervisor_handoffs`: `source_node`, `destination_node`, `decision_reason`
- [ ] Show worker heartbeat lifecycle: Starting → Running → Idle → Stopping → Stopped
- [ ] Show job status transitions: pending → running → succeeded/failed
- [ ] Show `stage_timings_ms` breakdown: planner, evidence sections, draft, verify
- [ ] Show `merge_telemetry` in retrieval output: pre/post rerank scores, threshold, accepted count

## 8. Safety and PHI

- [ ] Grep logs for `patient_id` — confirm zero occurrences (only `patient_ref` hash appears)
- [ ] Show `SensitiveLogPolicy` stripping forbidden keys from log context
- [ ] Ask an out-of-scope clinical advice question — confirm refusal response
- [ ] Delete/retract a document — confirm its facts are excluded from subsequent retrieval
- [ ] Show `no_phi_in_logs` rubric at 100% in eval results

## 9. Post-Demo Verification

- [ ] Run `bash agent-forge/scripts/health-check.sh` — all checks pass
- [ ] Run `bash agent-forge/scripts/verify-deployed.sh` — full verification chain passes
- [ ] Capture screenshot of final eval results
- [ ] Save recording file with audio confirmed
- [ ] Verify deployed link is accessible from a fresh browser / incognito session

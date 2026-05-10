# Completed Epics Log

Compressed history of all completed AgentForge epics. Policy decisions
are in [DECISIONS.md](DECISIONS.md). Original epic files are preserved
in git history (deleted 2026-05-07).

---

### Epic 1 — Submission Gate Checklist
Proof index verifying AUDIT.md, USERS.md, ARCHITECTURE.md exist and meet SPECS.txt content requirements. All checks pass at commit `205a70299`.

### Epic 2 — Deployment & Runtime Proof
Deployment tooling for `openemr.titleredacted.cc`. Deploy, rollback, health-check, seed, and verify scripts captured on `gauntlet-mgh` 2026-04-30. MariaDB bootstrap fragility documented. H2/H3 manual UI verification 2026-05-07: 12→2 document-fact citations after deletion, retraction confirmed.

### Epic 3 — Demo Data & Eval Ground Truth
Fake demo patient (pid=900001, Alex Testpatient) with falsifiability as design principle. Known missing microalbumin and unsupported metformin-increase request are intentional test cases. Chart render contract proven by `verify-demo-data.sh`.

### Epic 4 — Agent Request Shell
Patient-chart request shell with fail-closed authorization gate. Three relationship shapes only; everything else deferred and refused. Output buffering prevents accidental prior output from corrupting JSON.

### Reviewer Submission Packaging
Root-level AUDIT.md, USERS.md, ARCHITECTURE.md as canonical reviewer artifacts. Reviewer guide separates implemented proof from planned remediation.

### Adversarial Demo Patients
Stress-test patients: Riley Medmix (900002, medication boundary) and Jordan Sparsechart (900003, missing-section transparency). Inactive warfarin excluded from active meds; sparse chart reports missing labs as missing.

### Allergy & Vital Sign Evidence Tools
Allergy keyword routing takes precedence over medication routing. Model verification failures can fall back to deterministic evidence-line output for real drafts only.

### Conversation Scope & Citation Surfacing
v1 is single-shot constrained RAG. Persistent PHI conversation storage deferred. Multi-turn contract: prior answer is context only; every follow-up claim needs current citations.

### Cost Analysis & Scale Tiers
Production cost analysis at 100/1K/10K/100K user tiers. 100K requires architecture redesign. Docker full clean sweep has pre-existing failures outside this epic.

### Evaluation Honesty
Eval tier taxonomy (Tiers 0–4) preventing fixture-only green from being described as live-agent proof. No phantom green: result files only created when runner executes.

### High-Signal Evidence Coverage
Expanded evidence for visit-briefing and follow-up. Every fact must be source-carrying. Stale vitals labeled explicitly. Inactive meds separated. Broad chart search, full med reconciliation, stale-vital interpretation deliberately deleted from scope.

### Medication & Auth Index Remediation
Active medication coverage across prescriptions, lists, lists_medication. Authorization expansion remains fail-closed. Composite-index candidates documented but migration deferred (requires EXPLAIN proof).

### Model Drafting & Verification
Draft/verifier architecture. `procedure_result` lacks stable `external_id`; lab source IDs use seeded demo `comments` with fallback. Default gpt-4o-mini pricing built-in.

### Observability — Cost & Eval
PSR request telemetry at warning level for Docker logger visibility. Apache referer `set_pid` gap known. Deploy script defect fixed: sources `.env` before validating model config.

### Observability — Latency & Audit Logs
Sensitive audit-log policy with PHI-minimized allowed/forbidden field lists. Measured baselines: local 2,989ms, VM 10,693ms. Eight latency optimizations shipped May 2026. Production readiness blocked until operational access and retention resolved.

### Promoted Data Retraction & Audit
Retraction = deactivation, never hard-delete. Append-only audit trail. Lists get activity=0, procedure_result marked corrected/excluded. Browser deletion proof 2026-05-07.

### Read-Only Evidence Tools
Evidence contract: unauthorized clinical notes and standalone form_encounter without linked notes not surfaced. `procedure_result` external_id gap documented.

### Reviewer Entry Point & Submission Map
Restored missing AGENTFORGE-REVIEWER-GUIDE.md link. Verified by `ReviewerGuideDocumentTest`.

### Seeded SQL Evidence Eval Tier
Tier 1 SQL eval runner using real SqlChartEvidenceRepository against demo data. 7 cases including authorization allow/deny. Encounter reason-for-visit evidence surfaced.

### Server-Bound Multi-Turn Conversation State
Server-issued conversation ID bound to session user+patient. No persistent transcript. Every turn re-fetches evidence. Provider timeout falls back to deterministic evidence.

### Verifier Hardening & Tool Routing
Verifier distrusts model labels. Token-set matching via EvidenceMatcher. Date canonicalization to ISO. Unsupported factual tails blocked. Semantic paraphrase verification deferred.

### Source Review & Citation Rendering (Epic 9)
Typed `ReviewLocatorKind` enum replaces `review_mode` string. Five locator kinds: `image_region` (page + bbox), `page_quote` (page, no bbox), `text_anchor` (DOCX sections), `table_cell` (XLSX cells), `message_field` (HL7 segments). `SourceReviewPresenter` maps doc_type → locator kind and constructs all review URLs. Template JS dispatches on `locator.kind`. Non-page formats (DOCX, XLSX, HL7) show metadata + quote only — no document viewers. Evidence metadata carries `review_url`, `open_source_url`, `inline_marker`, `locator` fields.

### Visual PDF Source Review & Retraction UX
Source-review modal with citation metadata, quote text, bounding-box highlight. No iframe embed. PDF page-image is CSS placeholder. Full retraction cascade proven locally and deployed VM (12→2 citations after deletion).

### End-To-End Gate And Documentation (Epic 10)
Per-format rubric pass rates surfaced in eval summary (`doc_type_rubrics` in summary.json). Gate script prints format coverage table after eval. Cost/latency report includes format coverage and per-format dimensions. AGENTFORGE-REVIEWER-GUIDE.md documents six-format matrix and known limitations. HL7 v2 ADT added to deployed smoke for non-PDF coverage. W2_ACCEPTANCE_MATRIX.md expanded with per-format acceptance rows.

### Final Submission Infrastructure
Observability trace report (`show-request-traces.php`) backed by `AuditLogTransport` interface (local/SSH/docker-compose strategies) and `AuditLogEntryParser`. Citation density safety net in eval runner catches missing citation expectations on `ok` responses. Latency SLO targets documented in `LATENCY-RESULTS.md`. `preflight-final-submission.sh` runs all gates in one command with `LOCAL_ONLY` mode for host-only checks. VM compatibility fixes: Composer process timeout for PHPStan (300s→900s), Docker fallback for PHPUnit when host lacks extensions, Imagick delegate skip guards in tests. Tier 2 evals 13/14 (1 LLM behavior flake on prompt injection). Deployed smoke 4/4. Latency p95 under 10s budget.

# AgentForge Memory

Cross-epic carry-forward only. If it's derivable from code, git, or the epic
file where it was proven, it does not belong here.

## Admission Test

Before adding an entry, it must pass ALL of these:

1. **Would deleting this cause a future mistake?** If no, don't add it.
2. **Is this already in code, tests, or an epic file?** If yes, don't duplicate it here.
3. **Is this a completed task or proof log?** If yes, it belongs in the epic, not here.
4. **Will this matter in 30 days?** If not, it's ephemeral — skip it.

Corollary: when a milestone ships, prune entries it made obsolete.

---

## Guardrails

These are active constraints across all milestones.

- Implemented code is not acceptance-complete until safety proof is present.
- OpenEMR core workflows must not depend on AgentForge success. Fail safe.
- Patient/user scope must be resolved before patient data is read.
- Model-facing clinical behavior must be grounded in evidence, citations, and deterministic checks.
- Unsupported or unsafe clinical requests must fail closed with a safe refusal.
- Logs and telemetry must avoid raw PHI. Forbidden keys: `quote`, `quote_or_value`, `raw_value`, `document_text`, `document_image`, `extracted_fields`.
- LLM keys are server env only; never committed. `deploy-vm.sh` fails fast when the chosen provider's key is missing.
- Document upload must succeed even if all AgentForge code fails.
- Category mappings determine document-ingestion eligibility — not filenames, MIME types, or heuristics.
- Do not promote extracted values into OpenEMR clinical tables without full provenance: `document_id`, `job_id`, fact id, citation, confidence, promotion status.
- Source document deletion triggers retraction of all derived AgentForge content. Retracted content must not appear as active evidence.
- Durable schema names describe the clinical domain, not the product (`clinical_document_*`, not `agentforge_*`).

## Architecture Decisions

Contracts that downstream code depends on.

- `clinical_document_type_mappings` maps OpenEMR document categories to clinical document types. One category → one doc type.
- `clinical_document_processing_jobs` is the durable queue. Job enqueue is idempotent for `(patient_id, document_id, doc_type)`. `retracted` is terminal.
- `clinical_document_retractions` is append-only audit. Prior/new state JSON, action, actor, reason, timestamp.
- `clinical_document_facts` + `clinical_document_fact_embeddings` are the patient-document fact store. Guideline vectors are separate in `clinical_guideline_chunk_embeddings`.
- `clinical_document_identity_checks` gates between extraction and trusted facts. Only `identity_verified` and `identity_review_approved` are trusted.
- SQL repositories go through the AgentForge `DatabaseExecutor` boundary.
- Value objects reject non-positive IDs at the boundary.
- Bounding boxes use `{x, y, width, height}` (not `[x0, y0, x1, y1]`).
- Upload enqueue dispatches once in `C_Document::upload_action_process()`.
- Multi-turn state stored via `SessionUtil::setSensitiveSession` under `agent_forge_conversations`.

## Stakeholder Clarifications (2026-05-05)

Normative for assignment interpretation (from course Slack alignment):

- MVP demo: Lab PDF + intake ingestion demonstrated on **deployment**, not localhost-only.
- Clinical-guideline corpus: organization-approved reference material the team selects; use a minimal intentional set.
- Retrieval vs patient data: hybrid sparse+dense+rerank applies to guideline corpus only; patient facts belong in structured OpenEMR persistence.
- May 5 checkpoint emphasizes ingestion + retrieval on deploy; broader recommended steps should be taking shape.
- Resolving Week 1 feedback before adding surface area is guidance, not a hard gate.

## Bugs That Could Recur

### `documents.deleted` vs `lists.activity`

The `documents` table uses `deleted` (nullable); the `lists` table uses `activity` (1/0). Five source files and five test files once used `d.activity = 1` on `documents`, causing 500 errors. Filter documents with `d.deleted IS NULL OR d.deleted = 0`.

### `ic.identity_status` not `ic.status`

`SqlDocumentRetractionRepository::auditIdentityChecks()` referenced `ic.status` instead of `ic.identity_status`. The SQL error caused the entire retraction transaction to silently `ROLLBACK` — deletion appeared to succeed but no retraction cascade ran. Silent transaction rollback is the failure mode to watch for in any new retraction SQL.

### Evidence `source_type` must be `'document'`

Evidence tools must set `source_type` to `'document'` (not `doc_type` like `'lab_pdf'`). The Twig template's `canFetchSourceReview()` checks `source_type === 'document'` to render clickable citation links. `doc_type` is a separate field.

### `InstallationCheck` `$config` global collision

`library/sql.inc.php` line 59 overwrites `global $config` (set to `1` by `sites/default/sqlconf.php`) with a `DatabaseConnectionOptions` object during bootstrap. Fixed 2026-05-07: `InstallationCheck` now reads the sqlconf file as text. Lesson: never rely on `global $config` after OpenEMR bootstrap.

## Open Caveats

- Default document fact embedding provider is deterministic/fixture-backed. Semantic/live provider selection is future hardening before depending on dense document-vector ranking.
- Deployed VM smoke artifact (`clinical-document-deployed-smoke-*.json`) pending after `InstallationCheck` fix is pushed and deployed.
- CI vs release proof: PR green on Tier 0/1 does not imply Tier 4 deployed smoke or full `check-agentforge.sh`.

## Week 1 Foundation (Summary)

Week 1 established the chart-agent baseline: server-bound clinical assistant that answers chart-grounded questions only when authorization, patient context, evidence, and deterministic safety checks all pass. Later work builds on this but Week 1 docs are not automatically controlling for newer milestones.

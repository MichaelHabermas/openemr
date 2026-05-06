# Epic M5A - Document Identity Verification And Wrong-Patient Safeguards

Status: Completed.

Completed on 2026-05-06.

Summary:

- Added cited `patient_identity` candidates to strict `lab_pdf` and
  `intake_form` extraction.
- Added deterministic identity verification against OpenEMR `patient_data`.
- Added `clinical_document_identity_checks` install/upgrade schema and SQL
  repositories.
- Gated queued extraction in `IntakeExtractorWorker` after schema validation
  and before job success.
- Added direct `AttachAndExtractTool` identity parity for deterministic eval/tool
  paths.
- Added focused tests for verifier policy, SQL repositories, and worker
  ambiguous-identity blocking.

Verification:

- `composer phpunit-isolated -- tests/Tests/Isolated/AgentForge/Document tests/Tests/Isolated/AgentForge/Eval/ClinicalDocument`
  passed: 189 tests, 714 assertions, 1 skipped.
- `agent-forge/scripts/check-clinical-document.sh` passed outside the sandbox:
  499 tests, 2345 assertions, 1 skipped; eval verdict `baseline_met`.

Carry-forward:

- M5B/M5 must treat only `identity_verified` and explicit
  `identity_review_approved` as eligible for active facts, embeddings, promoted
  rows, and evidence bundle items.
- M5A intentionally does not build the human review UI, promote facts, create
  embeddings, or make document facts retrievable.

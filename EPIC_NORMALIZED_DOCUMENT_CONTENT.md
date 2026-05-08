# Epic: Normalized Document Content Layer

**Generated:** 2026-05-08
**Scope:** backend
**Status:** Complete

---

## Overview

Epic 3 inserts a normalized-content seam before extraction while preserving current `lab_pdf` and `intake_form` behavior. DOCX, XLSX, TIFF, and HL7 v2 remain contract-only runtime targets and still fail closed before provider extraction.

---

## Tasks

### Task 1.1: Content Normalization Boundary
**Status:** [x] Complete
**Description:** Add immutable normalized-content contracts, coded warnings, safe telemetry, and a registry that selects the first supporting normalizer.
**Acceptance Map:** Adds `DocumentContentNormalizer`, `NormalizedDocumentContent`, and registry; supports source metadata, rendered pages, text/table/message placeholders, warnings, and PHI-safe telemetry.
**Proof Required:** Content value-object and registry PHPUnit.

**Subtasks:**
- [x] Add content normalization interfaces and value objects under `src/AgentForge/Document/Content`.
- [x] Add coded warning and safe telemetry value objects.
- [x] Add registry unsupported-MIME failure handling.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `feat(agentforge): add document content normalization boundary`

### Task 1.2: PDF And Image Normalizers
**Status:** [x] Complete
**Description:** Move current PDF/image preparation into content normalizers without changing current extraction behavior.
**Acceptance Map:** Existing PDF rendering and PNG/JPEG/WEBP image behavior pass through the new layer; source SHA-256 metadata is preserved.
**Proof Required:** PDF/image normalizer tests and OpenAI provider payload parity tests.

**Subtasks:**
- [x] Add `PdfDocumentContentNormalizer` using the existing `PdfPageRenderer`.
- [x] Add `ImageDocumentContentNormalizer` for PNG/JPEG/WEBP.
- [x] Preserve current OpenAI content payload shape.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `refactor(agentforge): normalize pdf and image extraction input`

### Task 1.3: Provider And Telemetry Integration
**Status:** [x] Complete
**Description:** Wire OpenAI extraction through the registry while keeping the provider interface stable and fixture extraction SHA-keyed.
**Acceptance Map:** `DocumentExtractionProvider::extract(DocumentLoadResult ...)` remains unchanged; OpenAI provider consumes normalized content internally; fixture provider behavior remains unchanged; logs include only safe aggregate normalization telemetry.
**Proof Required:** Factory/provider/worker tests and no-PHI telemetry assertions.

**Subtasks:**
- [x] Refactor `OpenAiVlmExtractionProvider` to build content parts from `NormalizedDocumentContent`.
- [x] Wire factory-created OpenAI providers with PDF/image normalizers.
- [x] Extend safe extraction logs with aggregate normalization telemetry.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `refactor(agentforge): route extraction through normalized content`

### Task 1.4: Documentation And Gate Proof
**Status:** [x] Complete
**Description:** Update architecture/planning docs and run the required gates.
**Acceptance Map:** Docs state Epic 3 is a seam-only implementation with no new runtime support for DOCX/XLSX/TIFF/HL7; current clinical-document gate remains green.
**Proof Required:** Focused PHPUnit, clinical eval, clinical-document gate, PHPStan, and broader AgentForge gate if feasible.

**Subtasks:**
- [x] Update multi-format plan, Week 2 architecture, Memory, and clinical golden README if needed.
- [x] Run focused PHPUnit and clinical eval.
- [x] Run full clinical-document gate and static analysis.
- [x] Add or update proof for each acceptance criterion this task claims.
- [x] Update this epic file with completed proof or an explicit gap.

**Suggested Commit:** `docs(agentforge): record normalized content layer proof`

---

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps.
- [x] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are created or explicitly assigned as tasks.

---

## Change Log

- 2026-05-08: Added normalized-content contracts, PDF/image normalizers, OpenAI provider integration, safe normalization telemetry, and focused PHPUnit proof (`28 tests / 165 assertions`).
- 2026-05-08: Clinical eval passed: `php agent-forge/scripts/run-clinical-document-evals.php` -> `baseline_met`, artifact `agent-forge/eval-results/clinical-document-20260508-195203`.
- 2026-05-08: Full clinical-document gate passed: `bash agent-forge/scripts/check-clinical-document.sh` -> 715 tests / 3403 assertions / 1 skipped, clinical eval `baseline_met`, PHPStan clean, PHPCS clean, artifact `agent-forge/eval-results/clinical-document-20260508-195422`.
- 2026-05-08: Comprehensive AgentForge gate passed: `bash agent-forge/scripts/check-agentforge.sh` -> baseline AgentForge check PASS, deterministic evals 32 passed / 0 failed, clinical harness self-tests 62 tests / 266 assertions, nested clinical-document gate PASS, artifact `agent-forge/eval-results/clinical-document-20260508-195604`.
- 2026-05-08: Post-implementation audit fixed provider serialization for text/table/message normalized content, centralized default registry construction, constrained normalization telemetry to safe aggregate keys, removed speculative PDF page-limit warnings, and mapped worker provider failures to stable safe messages. Focused PHPUnit proof now `39 tests / 224 assertions`.
- 2026-05-08: Re-ran full clinical-document gate after audit fixes: `bash agent-forge/scripts/check-clinical-document.sh` -> 721 tests / 3433 assertions / 1 skipped, clinical eval `baseline_met`, PHPStan clean, PHPCS clean, artifact `agent-forge/eval-results/clinical-document-20260508-201113`.
- 2026-05-08: Re-ran comprehensive AgentForge gate after audit fixes: `bash agent-forge/scripts/check-agentforge.sh` -> baseline AgentForge check PASS, deterministic evals 32 passed / 0 failed, clinical harness self-tests 62 tests / 266 assertions, nested clinical-document gate PASS, artifact `agent-forge/eval-results/clinical-document-20260508-201326`.

---

## Acceptance Matrix

- [x] Add content normalization seam -> `DocumentContentNormalizer`, registry, normalized content/source/page/warning/telemetry value objects, and focused content tests.
- [x] Preserve current PDF/image behavior -> PDF/image normalizers and OpenAI payload parity tests; text/table/message content is serialized explicitly when future normalizers produce it.
- [x] Keep provider interface stable -> `DocumentExtractionProvider::extract(DocumentLoadResult ...)` unchanged; OpenAI normalizes internally.
- [x] Keep fixture behavior SHA-keyed -> `FixtureExtractionProvider` unchanged and clinical golden eval remains `baseline_met`.
- [x] Keep DOCX/XLSX/TIFF/HL7 contract-only -> runtime type guard remains before provider extraction; focused worker tests remain green.
- [x] Add PHI-safe telemetry -> normalization telemetry allowlist plus response-level safe aggregate filtering and worker log test proving forbidden raw content is sanitized.
- [x] Document seam-only scope and deferrals -> multi-format plan, Week 2 architecture, Memory, clinical golden README, and this epic file updated.

---

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes
- Required manual checks executed and captured? yes; no manual UI checks required for this backend seam
- Required fixtures/data/users for proof exist? yes
- Security/privacy/logging/error-handling requirements verified? yes
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

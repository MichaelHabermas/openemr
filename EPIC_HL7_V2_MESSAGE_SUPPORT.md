# Epic: HL7 v2 Message Support

**Generated:** 2026-05-09
**Scope:** backend runtime ingestion, deterministic extraction, tests, and docs
**Status:** Complete

---

## Overview

Epic 7 moves `hl7v2_message` from strict fixture-backed contract coverage to
bounded runtime support for deterministic HL7 v2 message ingestion. The target
is ADT and ORU message support through the existing OpenEMR upload,
category-mapped job queue, identity gate, cited fact store, and retrieval
pipeline.

Current implementation adds bounded HL7 v2 content normalization, deterministic
ADT/ORU extraction, worker/fact-persistence wiring, segment/field citation
coverage, and documentation updates. The comprehensive AgentForge gate passed
with `baseline_met` clinical-document eval proof.

---

## Current Proof Boundary

- Existing: `DocumentType::Hl7v2Message` is an allowed document type.
- Existing: demo category mapping can enqueue `HL7 v2 Message -> hl7v2_message`.
- Existing: strict schema and fixture-backed golden coverage exist for ADT and
  ORU contract cases.
- Implemented: worker tests now prove `hl7v2_message` reaches provider
  extraction and counts/persists generic document facts.
- Implemented: runtime content normalizer for bounded `.hl7` messages.
- Implemented: deterministic parser for segment, field, component, repetition,
  and message-control-id handling.
- Implemented: runtime extraction/provider wiring that does not require a model
  for supported ADT/ORU shapes.
- Implemented: fact mapper support through generic `ExtractedClinicalFact`
  persistence for visit/order context, observations, and notes.
- Implemented: source-review citation proof for segment/field anchors.
- Deferred operational step: historical `unsupported_doc_type` job replay is
  not automatic and must be an explicit migration/requeue decision if needed.

---

## Tasks

### Task 1.1: HL7 v2 Normalized Content
**Status:** [x] Complete
**Description:** Add `Hl7v2DocumentContentNormalizer` for bounded `.hl7` message content.
**Acceptance Map:** Message bytes become normalized message segments with stable source metadata, segment ids, field paths, coded warnings, and PHI-safe telemetry.
**Proof Required:** `Hl7v2DocumentContentNormalizerTest`.

**Subtasks:**
- [x] Support category-driven `hl7v2_message` only; do not route by filename.
- [x] Parse MSH encoding characters and segment separators.
- [x] Preserve segment order and stable segment ids.
- [x] Preserve field, component, subcomponent, and repetition anchors.
- [x] Enforce source byte and segment count limits.
- [x] Fail closed on empty, malformed, unsupported, or oversized messages.
- [x] Prove normalizer failures do not expose raw HL7 or extracted field values.

### Task 1.2: Deterministic ADT And ORU Extraction
**Status:** [x] Complete
**Description:** Add deterministic extraction for supported ADT and ORU fixture shapes.
**Acceptance Map:** Supported message types produce strict `Hl7v2MessageExtraction` output without a model call.
**Proof Required:** Parser and extraction-provider tests for ADT and ORU.

**Subtasks:**
- [x] Add targeted support for `MSH`, `PID`, `PV1`, `ORC`, `OBR`, `OBX`, and `NTE`.
- [x] Extract patient identity evidence from PID with cited field anchors.
- [x] Extract ADT visit/context facts from MSH/PV1 where present.
- [x] Extract ORU order and observation facts from ORC/OBR/OBX.
- [x] Extract NTE message notes as reviewable cited document facts.
- [x] Reject unsupported message types with a PHI-safe error.
- [x] Prove duplicate OBX handling is deterministic and cited.

### Task 1.3: Runtime Worker And Fact Mapping
**Status:** [x] Complete
**Description:** Enable `hl7v2_message` through the existing worker, identity, and document-fact persistence path.
**Acceptance Map:** HL7 jobs no longer fail with `unsupported_doc_type`; supported facts remain cited document facts only unless a later approved promotion rule exists.
**Proof Required:** Worker, identity, fact mapper, retraction, and no-promotion tests.

**Subtasks:**
- [x] Flip `DocumentType::Hl7v2Message->runtimeIngestionSupported()` only after deterministic extraction is wired.
- [x] Add `Hl7v2MessageExtraction` to runtime parsing/fact-count unions where needed.
- [x] Map message metadata, visit context, observations, and notes into generic document facts.
- [x] Keep HL7 facts document-fact-only; do not promote HL7 labs into chart tables in this epic.
- [x] Ensure identity mismatch/ambiguity gates block trusted evidence.
- [x] Ensure source document deletion retracts HL7-derived facts and embeddings through the existing generic document-fact path.
- [x] Decide and document whether historical `unsupported_doc_type` jobs need explicit replay.

### Task 1.4: Segment/Field Citations And Review Metadata
**Status:** [x] Complete
**Description:** Make HL7 citations stable, normalized, and source-reviewable without exposing raw whole messages.
**Acceptance Map:** Citation anchors such as `MSH[1].10`, `PID[1].5`, `OBX[3].5`, and `NTE[1].3` normalize consistently.
**Proof Required:** Citation normalizer/source review tests.

**Subtasks:**
- [x] Extend shared citation normalization for HL7 message and field anchors.
- [x] Preserve message control id in citation metadata when available.
- [x] Render review metadata for segment/field citations without dumping raw HL7.
- [x] Prove citation links remain gated by patient, ACL, job status, identity status, document state, and fact activity through the existing source-review gate tests plus HL7 anchor coverage.

### Task 1.5: Documentation And Gates
**Status:** [x] Complete
**Description:** Update carry-forward docs only after runtime proof exists.
**Acceptance Map:** Docs state HL7 bounded runtime support only after code and gates prove it.
**Proof Required:** Focused PHPUnit, clinical-document evals, clinical gate, PHPStan, and comprehensive AgentForge gate.

**Subtasks:**
- [x] Update the exact doc lines listed below after runtime proof exists.
- [x] Run focused PHPUnit for HL7 normalizer/parser/provider/worker/citation tests.
- [x] Run clinical-document evals and record the `baseline_met` artifact.
- [x] Run `agent-forge/scripts/check-clinical-document.sh`.
- [x] Run `agent-forge/scripts/check-agentforge.sh`.
- [x] Record any sandbox or environment blockers without marking acceptance complete.

---

## Runtime Support Doc Updates

- [x] `W2_ARCHITECTURE.md` now includes bounded deterministic HL7 v2 ADT/ORU runtime support.
- [x] `agent-forge/docs/MULTI-FORMAT-DOCUMENT-INGESTION-PLAN.md` now marks Epic 7 implemented and gated, with bounded supported ADT/ORU scope and document-fact-only caveats.
- [x] `agent-forge/docs/MEMORY.md` now records HL7 runtime support, document-fact-only policy, and the historical replay caveat.

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

- 2026-05-09: Drafted Epic 7 tracker from current code/doc state. No runtime support is claimed.
- 2026-05-09: Implemented HL7 v2 normalizer, deterministic ADT/ORU extraction provider, runtime worker/fact-promotion wiring, source-review citation coverage, and documentation updates. Focused isolated proof passed: 50 tests, 344 assertions, 1 skipped.
- 2026-05-09: Broader proof passed: AgentForge document isolated suite 301 tests/1674 assertions, clinical document gate `baseline_met`, and comprehensive AgentForge check.

---

## Acceptance Matrix

- [x] ADT and ORU fixture shapes extract cited facts in deterministic runtime mode.
- [x] HL7 extraction does not call a model for supported fixture shapes.
- [x] Segment/field citations are stable and human-reviewable.
- [x] Identity verification gates trusted evidence before persistence/retrieval.
- [x] HL7 facts remain cited document facts only.
- [x] Malformed, missing-PID, unsupported-type, duplicate-OBX, and oversized-message cases are covered.
- [x] Logs and telemetry exclude raw HL7, raw field values, quotes, extracted values, and full message text.
- [x] Historical `unsupported_doc_type` replay is documented as explicit operational work if needed.

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes
- Required manual checks executed and captured? not required for this backend deterministic parser epic
- Required fixtures/data/users for proof exist? yes for isolated and golden fixture proof
- Security/privacy/logging/error-handling requirements verified? yes
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

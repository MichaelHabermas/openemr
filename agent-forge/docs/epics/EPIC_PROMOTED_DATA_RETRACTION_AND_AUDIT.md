# Epic M5C - Promoted Data Retraction And Audit

Status: Implemented with automated proof.

Owner note: this file tracks M5C acceptance and proof only. Manual/browser
review proof remains optional follow-up; automated M5C gate proof is captured
below.

## Goal

Invalidate all active AgentForge evidence derived from a deleted source
document while preserving legal/chart audit history for facts, embeddings, and
promoted OpenEMR rows.

## Acceptance Mapping

| Acceptance requirement | Planned proof | Current proof status |
| --- | --- | --- |
| Deleted-source content cannot remain retrievable or promoted as active evidence. | Focused isolated tests plus clinical document eval case for wrong-document deletion/retraction. | Proven by focused isolated tests and `agent-forge/scripts/check-clinical-document.sh` on 2026-05-06. |
| Retraction is append-only from an audit perspective and inactive from an evidence perspective. | `clinical_document_retractions` migration/repository tests proving prior state, new state, action, actor, timestamp, and reason are recorded. | Implemented with row-level `clinical_document_retractions` schema and audited SQL retraction repository tests. |
| Deleted-source content remains historically reviewable but excluded from active chart evidence, document search, vector rows, and final-answer citations. | Browser or endpoint proof plus evidence-tool tests for facts, embedding deactivation, lab evidence, and final answers. | Automated SQL evidence gates and no-active-embedding-row behavior are proven. A document-fact vector retrieval path is not currently implemented, so retrieval-specific vector proof remains future-facing. Manual/browser historical review proof was not run in this implementation pass. |

## Scope

- Add `DocumentRetractionService` and SQL repository for append-only retraction audit.
- Add `clinical_document_retractions`.
- Extend source-document deletion handling from job/fact suppression to audited
  fact, embedding, promotion, and promoted-row handling.
- Define per-table promoted-row policy before hiding or deactivating any
  OpenEMR clinical row.

## Out Of Scope

- Hard-deleting clinical history.
- Polished PDF source overlay UX; that belongs to H2.
- New extraction, identity, guideline retrieval, or vector-corpus behavior.

## Proof Placeholders

- Automated gate: `agent-forge/scripts/check-clinical-document.sh` passed on
  2026-05-06 with artifact
  `agent-forge/eval-results/clinical-document-20260506-191013`.
- Focused isolated tests: `./vendor/bin/phpunit -c phpunit-isolated.xml`
  against the M5C document/evidence subset passed with 70 tests and 520
  assertions.
- Clinical document eval artifact: `clinical-document-20260506-191013`,
  verdict `baseline_met`.
- Manual/browser deletion proof: not run; remaining optional H2/submission-polish
  proof should capture screenshots or SQL before/after rows if needed.
- Reviewer note: M5C now has the audit table, dedicated service/repository,
  stale evidence gates, and full clinical-document gate proof.

## Current Caveat

The implementation preserves rows and deactivates/excludes them for AgentForge
evidence. It does not hard-delete facts, embeddings, promotions, or native
OpenEMR rows. Legacy `clinical_document_promoted_facts` compatibility remains
covered until that table is removed by a future cleanup.

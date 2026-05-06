---
source_title: OpenEMR Local Demo Guideline Boundary Note
source_url_or_file: agent-forge/fixtures/clinical-guideline-corpus/openemr-local-refusal-calibration.md
---

## Out Of Corpus Boundary

If a user asks for guideline content that is not present in the approved local corpus, AgentForge should say the guideline was not found in the corpus.

## Patient Specific Advice Boundary

AgentForge can summarize retrieved guideline evidence with citations, but it should not invent patient-specific treatment advice or cite sources that were not retrieved.

## Retrieval Transparency

Guideline answers should expose the retrieved source title, stable section, chunk identifier, and retrieval or rerank score when available.

## Corpus Privacy Boundary

The guideline corpus must not contain patient-specific facts, document extraction output, raw PHI, or uploaded clinical document text.

## Refusal Citation Boundary

When no approved corpus chunk supports the requested topic, AgentForge should not fabricate a citation or convert unrelated chunks into evidence.

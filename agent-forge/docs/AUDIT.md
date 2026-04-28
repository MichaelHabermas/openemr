# Audit

> **Guidance for contributors (human or agent).** Read before adding to this document.
>
> **Purpose.** This is the Stage 3 hard-gate deliverable. It must contain a full audit
> of the OpenEMR system as it stands, before any AI work is layered on top. Findings
> here are the input to `ARCHITECTURE.md` — every agent design decision should be
> traceable back to something documented in this file.
>
> **Required structure.**
> 1. **One-page summary (~500 words) at the very top.** This is a hard requirement.
>    The brevity is intentional: surface only the most impactful findings, the ones
>    that will actually change how the agent gets built. Do not dump everything you
>    found into the summary — that is what the body sections are for.
> 2. **Body sections**, one per audit pass:
>    - **Security** — auth/authorization risks, data exposure vectors, PHI handling, HIPAA-relevant gaps.
>    - **Performance** — bottlenecks, data structure costs, anything that will affect agent latency.
>    - **Architecture** — how the system is organized, where data lives, layer boundaries, integration points for new capabilities.
>    - **Data Quality** — completeness, consistency, missing fields, duplicates, stale data — anything that becomes an agent failure mode.
>    - **Compliance & Regulatory** — audit logging requirements, retention, breach notification, BAA implications of sending PHI to an LLM.
>
> **Tone.**
> - Direct and specific. Cite files, tables, endpoints, line numbers when possible.
> - No hedging filler. If a finding is uncertain, say what would confirm it.
> - Impact first, then mechanism, then evidence. A reader should know within one sentence whether a finding matters.
> - Distinguish what was observed from what was inferred.
>
> **What not to include.**
> - Generic security/HIPAA background a reader can find anywhere.
> - Restating OpenEMR features without an audit angle.
> - Recommendations for the AI agent — those belong in `ARCHITECTURE.md`.
>
> Remove this guidance block before final submission if it gets in the way; otherwise leave it for future contributors.

## Summary

_TODO: ~500 words, key findings only._

## Security

_TODO_

## Performance

_TODO_

## Architecture

_TODO_

## Data Quality

_TODO_

## Compliance & Regulatory

_TODO_

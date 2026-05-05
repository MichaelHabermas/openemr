# Week 2 — Multimodal Evidence Agent

This folder is the **index** for Week 2 (Clinical Co-Pilot) work: document ingestion, worker graph, RAG, eval gate, and submission artifacts. Implementation code and tests live elsewhere; this is navigation and defense material.

## Requirements (source of truth)

- [Week 2 project requirements (text)](../SPECS-W2.txt) — same content as the PDF, easy to diff.
- [Week 2 requirements (PDF)](../reference/Week-2-AgentForge-Clinical-Co-Pilot.pdf) — original Gauntlet document.

**Not Week 2:** [../PRD.md](../PRD.md) and [../PLAN.md](../PLAN.md) are **Week 1** documents (grounded in [../SPECS.txt](../SPECS.txt)). Do not assume they describe multimodal/RAG/worker-graph work unless you fork or extend them explicitly (e.g. `PRD-W2.md`).

## Architecture (assignments + repo)

| Document | Purpose |
|----------|---------|
| [W2_ARCHITECTURE.md](../../../W2_ARCHITECTURE.md) (repository root) | **Week 2 defense artifact** required by the course: ingestion flow, worker graph, RAG design, eval gate, risks, tradeoffs. Filled in — covers the 9 required sections. Update as implementation lands. |
| [ARCHITECTURE.md](../../../ARCHITECTURE.md) (repository root) | Ongoing system architecture (Week 1 baseline + evolving product story). Week 2 multimodal details should either stay in `W2_ARCHITECTURE.md` or be summarized here with a pointer to avoid drift. |

## Where work accumulates

- **Epics** — Add Week 2 slices under [../epics/](../epics/) with an obvious prefix (e.g. `EPIC_W2_*`) or a dedicated epic file per stage; link them from this README as you create them.
- **Eval golden set** — [../../fixtures/clinical-document-golden/README.md](../../fixtures/clinical-document-golden/README.md) — 50-case gate per Week 2 spec; dataset files and judge config land here or beside existing eval fixtures as you wire CI.
- **Operations / cost** — Extend [../operations/](../operations/) for Week 2 latency and cost reports when measured.

## AgentForge gates

Use this command as the comprehensive AgentForge gate across Week 1, Week 2, and future AgentForge work:

```bash
agent-forge/scripts/check-agentforge.sh
```

Keep this script current as epics add or change required checks. A reviewer should not need to remember a growing checklist of separate commands.

## clinical document gate

Use this command as the single local/CI clinical document gate:

```bash
agent-forge/scripts/check-clinical-document.sh
```

M1 intentionally makes the gate fail at the `Run Clinical document evals` step because the production implementation is not connected yet. Syntax checks, harness tests, and artifact writing should pass; the eval threshold failure is the regression-blocking signal that later epics must turn green.

## Week 2 stages (from spec)

1. Ingest lab PDF and intake form (attach, extract, FHIR/OpenEMR integrity). — see `W2_ARCHITECTURE.md` §1–2.
2. Hybrid RAG + rerank over a general primary-care guideline corpus (lipids, glycemia, BP, USPSTF). — §3.
3. Supervisor + two workers (Extractor, EvidenceRetriever); logged handoffs in PHP state machine. — §4.
4. Eval-driven CI: 50 cases, 5 boolean rubrics, PR-blocking via `agentforge-evals.yml`. — §6.
5. Integrate, deploy behind `AGENTFORGE_CLINICAL_DOCUMENT_ENABLED`, demo video, cost/latency report. — §7, §9.

**Scope reminder.** The agent is disease-agnostic — extraction, retrieval, and verification are general. The corpus is bounded to common outpatient conditions; out-of-corpus questions return a deterministic refusal.

## README and env

The **repository root** `README.md` should clearly separate **Week 1 baseline** vs **Week 2** behavior, branches if any, and environment variables. Update [../../.env.sample](../../.env.sample) alongside new Week 2 settings.

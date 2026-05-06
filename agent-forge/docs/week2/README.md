# Week 2 — Multimodal Evidence Agent

This folder is the **index** for Week 2 (Clinical Co-Pilot) work: document ingestion, worker graph, RAG, eval gate, and submission artifacts. Implementation code and tests live elsewhere; this is navigation and defense material.

## AgentForge Memory Protocol

Before starting or resuming Week 2 work, read [../MEMORY.md](../MEMORY.md). It is the durable AgentForge memory for cross-epic lessons, guardrails, proof caveats, and carry-forward notes that must survive `CURRENT-EPIC.md` rewrites.

Update [../MEMORY.md](../MEMORY.md) when Week 2 work discovers durable information future epics must preserve, such as safety/privacy rules, architecture decisions, recurring bugs, gate caveats, or deferred proof gaps. Do not use it as a task tracker; active execution remains in the current epic, this plan, specs, and code.

## Requirements (source of truth)

- [Week 2 project requirements (text)](SPECS-W2.md) — maintained Week 2 spec (editable source of truth; may diverge from PDF when clarified).
- [Week 2 requirements (PDF)](Week-2-AgentForge-Clinical-Co-Pilot.pdf) — original Gauntlet document.

**Not Week 2:** [../week1/PRD.md](../week1/PRD.md) and [../week1/PLAN.md](../week1/PLAN.md) are **Week 1** documents (grounded in [../week1/SPECS.txt](../week1/SPECS.txt)). Do not assume they describe multimodal/RAG/worker-graph work unless you fork or extend them explicitly (e.g. `PRD-W2.md`).

## Architecture (assignments + repo)

| Document | Purpose |
|----------|---------|
| [PLAN-W2.md](PLAN-W2.md) | Current Week 2 implementation order and epic scope. Use this for sequencing active work. |
| [W2_ARCHITECTURE.md](../../../W2_ARCHITECTURE.md) (repository root) | **Week 2 defense artifact** required by the course: ingestion flow, worker graph, RAG design, eval gate, risks, tradeoffs. Keep aligned with `PLAN-W2.md` as implementation lands. |
| [ARCHITECTURE.md](../../../ARCHITECTURE.md) (repository root) | Ongoing system architecture (Week 1 baseline + evolving product story). Week 2 multimodal details should either stay in `W2_ARCHITECTURE.md` or be summarized here with a pointer to avoid drift. |

## Where work accumulates

- **Epics** — Add Week 2 slices under [../epics/](../epics/) with an obvious prefix (e.g. `EPIC_W2_*`) or a dedicated epic file per stage; link them from this README as you create them.
- **Eval golden set** — [../../fixtures/clinical-document-golden/README.md](../../fixtures/clinical-document-golden/README.md) — current eight-case checkpoint gate, expanding to the 50-case Week 2 submission gate in H1.
- **Operations / cost** — [../operations/CLINICAL-DOCUMENT-COST-LATENCY.md](../operations/CLINICAL-DOCUMENT-COST-LATENCY.md) is the current Week 2 clinical-document cost/latency report; [../operations/](../operations/) also contains shared Week 1 cost and deployed latency baselines.

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

The gate now passes for the implemented M4/M5A/M6 checkpoint path: strict
fixture-backed document extraction, identity gating, real guideline retrieval
for guideline cases, boolean rubrics, and artifact writing. It is still not the
final 50-case Week 2 submission gate; H1 expands the golden set after M7 wires
the supervisor/final-answer path.

## Week 2 stages (from spec)

1. Ingest lab PDF and intake form (attach, extract, FHIR/OpenEMR integrity). — see `PLAN-W2.md` for implementation order and `W2_ARCHITECTURE.md` §1–2 for defense architecture.
2. Hybrid RAG + rerank over a general primary-care guideline corpus (lipids, glycemia, BP, USPSTF). — §3.
3. Supervisor + two workers (Extractor, EvidenceRetriever); logged handoffs in PHP state machine. — §4.
4. Eval-driven CI: current checkpoint gate under `check-clinical-document.sh`, expanding to 50 cases and PR-blocking CI/Git Hook in H1. — §6.
5. Integrate, deploy behind `AGENTFORGE_CLINICAL_DOCUMENT_ENABLED`, demo video, cost/latency report. — §7, §9.

**Scope reminder.** The agent is disease-agnostic — extraction, retrieval, and verification are general. The corpus is bounded to common outpatient conditions; out-of-corpus questions return a deterministic refusal. Hybrid RAG indexes **only** that guideline corpus; patient document flows use structured extraction into chart/FHIR paths (see [../MEMORY.md](../MEMORY.md) §Week 2 stakeholder clarifications).

## README and env

The **repository root** `README.md` should clearly separate **Week 1 baseline** vs **Week 2** behavior, branches if any, and environment variables. Update [../../.env.sample](../../.env.sample) alongside new Week 2 settings.

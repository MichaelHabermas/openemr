# AgentForge Documentation

AgentForge docs are organized for reviewer-first navigation. The canonical gate documents live at the repository root; supporting proof, evaluation, operations, and submission material lives in this folder and its focused subfolders.

## Which document is which (Week 1 vs Week 2)

| Scope | Requirements source | Product / execution docs | Architecture |
|--------|----------------------|---------------------------|--------------|
| **Week 1** | [SPECS.txt](week1/SPECS.txt) | [PRD.md](week1/PRD.md), [PLAN.md](week1/PLAN.md), most of [epics/](epics/) | [../../ARCHITECTURE.md](../../ARCHITECTURE.md) (evolved from Week 1; still the live system overview) |
| **Week 2** | [SPECS-W2.md](week2/SPECS-W2.md) | [week2/PLAN-W2.md](week2/PLAN-W2.md), [week2/README.md](week2/README.md), [epics/](epics/) | [../../W2_ARCHITECTURE.md](../../W2_ARCHITECTURE.md) (Week 2 defense artifact) |

**Rule of thumb:** If it cites **`week1/SPECS.txt`** and the Week 1 chart agent story, it is Week 1. If it cites **`week2/SPECS-W2.md`**, documents, RAG, or the 50-case Week 2 gate, it is Week 2. **`week1/PRD.md` and `week1/PLAN.md` are Week 1–scoped** until you add separate Week 2 planning files.

## Start Here

- [MEMORY.md](MEMORY.md) - Durable AgentForge memory: cross-epic lessons, guardrails, proof caveats, and carry-forward notes that survive active epic rewrites.
- [SPECS.txt](week1/SPECS.txt) - Week 1 / original project requirements.
- [SPECS-W2.md](week2/SPECS-W2.md) - Week 2 project requirements (multimodal documents, RAG, supervisor/workers, eval gate).
- [week2/README.md](week2/README.md) - **Week 2 index** (links to spec, PDF, eval golden set, architecture deliverable).
- [operations/CLINICAL-DOCUMENT-COST-LATENCY.md](operations/CLINICAL-DOCUMENT-COST-LATENCY.md) - Week 2 clinical-document cost/latency report and measurement caveats.
- [../../AUDIT.md](../../AUDIT.md) - Codebase audit and constraints.
- [../../USERS.md](../../USERS.md) - Target user, workflow, and agent-justified use cases.
- [../../ARCHITECTURE.md](../../ARCHITECTURE.md) - Current architecture, trust boundaries, and known limitations.
- [../../W2_ARCHITECTURE.md](../../W2_ARCHITECTURE.md) - Week 2 architecture defense document (ingestion, graph, RAG, eval, risks); required course deliverable; fill in as Week 2 ships.
- [PRD.md](week1/PRD.md) - **Week 1** product requirements (`week1/SPECS.txt`).
- [PLAN.md](week1/PLAN.md) - **Week 1** execution plan and epic map (`week1/SPECS.txt`).

## Supporting Folders

- [epics/](epics/) - Epic narratives, proof notes, and acceptance traces.
- [evaluation/](evaluation/) - Evaluation tier taxonomy, instructor reviews, and captured test output.
- [operations/](operations/) - Cost analysis, Week 2 clinical-document cost/latency report, latency proof, and operational facts/needs.
- [submission/](submission/) - Reviewer packaging pointers and demo submission script.
- [week2/](week2/) - Week 2 navigation hub with the source spec, PDF/text assignment copies, implementation plan, fixture pointers, and root `W2_ARCHITECTURE.md` defense artifact.

## Memory Protocol

For AgentForge work, read [MEMORY.md](MEMORY.md) before changing code or rewriting an active epic file. Update it only with durable cross-epic memory: architecture decisions, safety/privacy guardrails, bugs that could reappear, proof gaps, gate caveats, and carry-forward notes. Do not use it as a task tracker.

## Canonical Versus Supporting Docs

The repository-root `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` are the canonical working docs for the AgentForge submission. Files in this folder are supporting evidence, implementation history, review material, or operational planning.

**Week 2:** The course requires a separate **`W2_ARCHITECTURE.md`** at the repository root for the Week 2 defense (document pipeline, worker graph, RAG, eval gate). That file is the Week 2-specific submission artifact. Keep `ARCHITECTURE.md` as the live system overview; avoid duplicating long sections—summarize in `ARCHITECTURE.md` and point to `W2_ARCHITECTURE.md` for Week 2 depth, or vice versa, as long as graders can find both quickly.

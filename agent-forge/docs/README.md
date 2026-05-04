# AgentForge Documentation

AgentForge docs are organized for reviewer-first navigation. The canonical gate documents live at the repository root; supporting proof, evaluation, operations, and submission material lives in this folder and its focused subfolders.

## Start Here

- [SPECS.txt](SPECS.txt) - Week 1 / original project requirements.
- [SPECS-W2.txt](SPECS-W2.txt) - Week 2 project requirements (multimodal documents, RAG, supervisor/workers, eval gate).
- [week2/README.md](week2/README.md) - **Week 2 index** (links to spec, PDF, eval golden set, architecture deliverable).
- [../../AUDIT.md](../../AUDIT.md) - Codebase audit and constraints.
- [../../USERS.md](../../USERS.md) - Target user, workflow, and agent-justified use cases.
- [../../ARCHITECTURE.md](../../ARCHITECTURE.md) - Current architecture, trust boundaries, and known limitations.
- [../../W2_ARCHITECTURE.md](../../W2_ARCHITECTURE.md) - Week 2 architecture defense document (ingestion, graph, RAG, eval, risks); required course deliverable; fill in as Week 2 ships.
- [PRD.md](PRD.md) - Product requirements and acceptance framing.
- [PLAN.md](PLAN.md) - Execution plan, epic map, and remediation backlog.

## Supporting Folders

- [epics/](epics/) - Epic narratives, proof notes, and acceptance traces.
- [evaluation/](evaluation/) - Evaluation tier taxonomy, instructor reviews, and captured test output.
- [operations/](operations/) - Cost analysis and operational facts/needs.
- [submission/](submission/) - Reviewer packaging pointers and demo submission script.
- [week2/](week2/) - Week 2 navigation hub (stages, pointers to fixtures and root `W2_ARCHITECTURE.md`).
- [reference/](reference/) - Original PDFs and other immovable reference artifacts.

## Canonical Versus Supporting Docs

The repository-root `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` are the canonical working docs for the AgentForge submission. Files in this folder are supporting evidence, implementation history, review material, or operational planning.

**Week 2:** The course requires a separate **`W2_ARCHITECTURE.md`** at the repository root for the Week 2 defense (document pipeline, worker graph, RAG, eval gate). That file is the Week 2-specific submission artifact. Keep `ARCHITECTURE.md` as the live system overview; avoid duplicating long sections—summarize in `ARCHITECTURE.md` and point to `W2_ARCHITECTURE.md` for Week 2 depth, or vice versa, as long as graders can find both quickly.

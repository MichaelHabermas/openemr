---

## parent: 500k-frame.md
status: locked
last_updated: 2026-04-29

# 50,000 ft — Strategy

## Purpose

Defines HOW the 500k frame gets executed within the ~36-hour clock. Order of work, foundation discipline, time budget. All lower-altitude decisions inherit from here.

## Strategy: Foundation Before Construction

`REQUIREMENTS.md`, `PLAN.md`, and `PRD.md` are load-bearing artifacts, not ceremony. They make the project:

- Resumable across multiple sessions
- Defensible to a reviewer
- Recoverable if context is lost or compacted

**Discipline:** we do not enter Phase N+1 until Phase N's artifact exists and is good enough to be load-bearing.

## Phase Order

1. **`REQUIREMENTS.md`** — prerequisites contract: what must be true before code begins. Four sections: `PRE-DATA-*` (test patients, fixtures), `PRE-ENV-*` (read-only DB user, JWT secret, deploy target), `PRE-FLOW-*` (user-flow map: auth path, where the agent lives in the UI, click path), `PRE-VERIFY-*` (smoke test that counts as "system is alive"). Binary: each prereq is done or it isn't.
2. **`PLAN.md`** — Epic skeleton of the PRD. Capped at ~50 lines / ~30 min. Contains: Epic list (3–5 Epics, name + one-line purpose), dependency graph (which Epic blocks which), prereq → Epic mapping, eval coverage map (which eval categories cover which Epic). Nothing else. **Kill clause:** if Epic structure is immediately obvious from `REQUIREMENTS.md` + `ARCHITECTURE.md`, skip PLAN and go directly to PRD.
3. **`PRD.md`** — informed by `PLAN.md` (or directly by REQUIREMENTS + ARCH if PLAN was skipped); absorbs upstream into a single standalone artifact. Contains: Epics → User Stories → Features → Tasks, Epic-level DoD, references to eval cases by stable ID, status checkboxes. Product requirements (what the agent does/refuses) are absorbed from `ARCHITECTURE.md` + `USERS.md` — not re-authored here.
4. **Strip scaffolding** — `REQUIREMENTS.md` and `PLAN.md` removed from repo (preserved in git history). PRD stands alone.
5. **Eval cases** — separate machine-readable file (e.g. `evals/cases.yaml`), referenced from PRD by stable ID.
6. **Code** — built only to make eval cases pass. Nothing else.
7. **Align upstream docs** — `AUDIT.md` / `USERS.md` / `ARCHITECTURE.md` updated to reflect what actually shipped.
8. **Deploy + `COST-ANALYSIS.md` + `README.md`**.
9. **Demo video**.

## Time Budget (~36 hr to deadline)


| Phase                              | Hours  |
| ---------------------------------- | ------ |
| Foundation docs (REQ + PLAN + PRD) | ~4     |
| Eval cases                         | ~2     |
| Build to evals                     | ~12–14 |
| Align upstream docs                | ~2     |
| Deploy + COST + README             | ~4     |
| Demo video                         | ~3     |
| Sleep + buffer                     | ~6–8   |


## Doc Conventions (Apply to All Lower-Altitude Docs)

- Stable IDs throughout: `REQ-01`, `EPIC-2`, `F-12`, `E-07`.
- Predictable section headers: Purpose / Decisions / Out of Scope / Open Questions.
- YAML front-matter at top of every doc: `parent`, `status`, `last_updated`.
- GitHub-flavored task checkboxes: `[ ]` / `[x]`.
- Per-Epic status field: `Status: Not Started | In Progress | Done | Blocked`.
- DoD lives at Epic/Story level, not Task level.
- Docs must be AI-agent-readable AND human-evaluator-clear: structured, scannable, no clever formatting.

## Risk Model

Project death mode is **scope, not difficulty.** Every hour spent on something not graded is an hour stolen from demo + deploy. Demo and deploy are the only things a reviewer literally clicks.

## Out of Scope (At This Altitude)

- Tactical decisions (live at 5k and below)
- Specific tools, models, libraries (live at 500 ft and below)
- Eval case content (lives in `evals/cases.yaml`)

## Open Questions

(none — strategy is locked)
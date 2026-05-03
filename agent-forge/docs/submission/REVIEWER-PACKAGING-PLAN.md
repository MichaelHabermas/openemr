# Reviewer packaging plan

**Canonical narrative:** Full reviewer-packaging scope, proof commands, and acceptance checklist live in [EPIC8-REVIEWER-SUBMISSION-PACKAGING.md](../epics/EPIC8-REVIEWER-SUBMISSION-PACKAGING.md). This file is a short entry point only.

**Reviewer landing:** Start at repository-root `AGENTFORGE-REVIEWER-GUIDE.md` when that packaging artifact is present.

**Reviewer navigation checklist:**

- Start at repository-root `README.md`.
- Open `AGENTFORGE-REVIEWER-GUIDE.md` from the `AgentForge Reviewer Entry Point` link.
- Confirm root `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` exist.
- Confirm the reviewer guide links resolve from the repository root.
- Confirm the guide exposes the documented deployed URL, fake patient, demo path, seed command, eval command, cost analysis, implemented proof, and known blockers without needing tribal knowledge.

**Root artifact check** (required docs are canonical at the repository root):

```sh
test -f AUDIT.md && test -f USERS.md && test -f ARCHITECTURE.md
```

Open production-readiness blockers include cost tiers, live-path evals, and production readiness proof. The execution backlog is tracked in [PLAN.md](../PLAN.md).

# Reviewer packaging plan

**Canonical narrative:** Full reviewer-packaging scope, proof commands, and acceptance checklist live in [EPIC8-REVIEWER-SUBMISSION-PACKAGING.md](../epics/EPIC8-REVIEWER-SUBMISSION-PACKAGING.md). This file is a short entry point only.

**Reviewer landing:** Start at repository-root `AGENTFORGE-REVIEWER-GUIDE.md` when that packaging artifact is present.

**Root artifact drift check** (required docs must match canonical copies under `agent-forge/docs/`):

```sh
cmp AUDIT.md agent-forge/docs/AUDIT.md
cmp USERS.md agent-forge/docs/USERS.md
cmp ARCHITECTURE.md agent-forge/docs/ARCHITECTURE.md
```

Open production-readiness blockers include cost tiers, live-path evals, and production readiness proof. The execution backlog is tracked in [PLAN.md](../PLAN.md).

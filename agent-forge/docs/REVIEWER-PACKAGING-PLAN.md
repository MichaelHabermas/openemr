# Reviewer packaging plan

**Canonical narrative:** Full Epic 8 scope, tasks, proof commands, and acceptance checklist live in [EPIC8-REVIEWER-SUBMISSION-PACKAGING.md](EPIC8-REVIEWER-SUBMISSION-PACKAGING.md). This file is a short entry point only.

**Reviewer landing:** Start at repository-root [AGENTFORGE-REVIEWER-GUIDE.md](../../AGENTFORGE-REVIEWER-GUIDE.md).

**Root artifact drift check** (required docs must match canonical copies under `agent-forge/docs/`):

```sh
cmp AUDIT.md agent-forge/docs/AUDIT.md
cmp USERS.md agent-forge/docs/USERS.md
cmp ARCHITECTURE.md agent-forge/docs/ARCHITECTURE.md
```

Pending remediation items called out in Epic 8 and the reviewer guide (cost tiers, live-path evals, production readiness) remain tracked in [PLAN.md](PLAN.md).

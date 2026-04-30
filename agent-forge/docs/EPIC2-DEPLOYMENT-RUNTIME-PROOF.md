# Epic: Deployment And Runtime Proof

**Generated:** 2026-04-30
**Scope:** deployment tooling and operational verification
**Status:** In Progress

---

## First-Principles Plan

The target outcome is a public OpenEMR deployment that can be checked, updated, and re-seeded reliably for demo purposes.

This is a fake-data demo. The deploy is destructive by design: every deploy resets the MySQL volume and reloads fake demo data. There is no database rollback in this project — recovery is by re-seed, not by snapshot.

Hard constraints:

- The public app URL is `https://openemr.titleredacted.cc/`.
- The public readiness endpoint is expected at `https://openemr.titleredacted.cc/meta/health/readyz` when exposed.
- The VM repository path is `~/repos/openemr`.
- The compose directory is `docker/development-easy/`.
- The compose command is `docker compose` (verified).
- Only fake demo data is permitted in the deployed database.
- Demo data must be re-seedable from scratch on every deploy.

What this epic does NOT do:

- No real PHI is ever loaded into the deployed database.
- No claim is made that a prior database state can be restored — there is no snapshot or backup.
- No deploy proceeds without a post-deploy health check.

---

## Tasks

### Task 2.1.1: Define Health Check Script Before Deploy Automation

**Status:** [x] Complete
**Description:** Create the smallest runnable proof that the public app URL and readiness endpoint are reachable.

**Subtasks:**

- [x] Add a script that checks `https://openemr.titleredacted.cc/`.
- [x] Add a script check for `https://openemr.titleredacted.cc/meta/health/readyz`.
- [x] Ensure pass and fail output identifies the endpoint.
- [x] Allow endpoint overrides through environment variables.

**Commit:** `chore(agent-forge): add deployment health checks`

### Task 2.1.2: Capture VM Deployment Facts

**Status:** [ ] Partial — branch, remote, TLS, env vars still pending observation
**Description:** Record verified deployment facts on the VM.

**Subtasks:**

- [x] Compose command: `docker compose` (verified — operator workflow uses it directly).
- [x] Docker permission: deploy user runs `docker compose` without sudo (verified — operator workflow uses it directly).
- [x] Volume behavior: volumes are reset on every deploy by design (`docker compose down -v`).
- [x] Repo path: `~/repos/openemr` (verified — operator workflow uses it directly).
- [ ] Active deployment branch: run `git -C ~/repos/openemr rev-parse --abbrev-ref HEAD` and record.
- [ ] Git remote: run `git -C ~/repos/openemr remote -v` and record.
- [ ] TLS termination: identify whether TLS terminates in the OpenEMR container, a host reverse proxy, or upstream (Cloudflare/load balancer).
- [ ] Required environment variables: capture any non-default values from `docker/development-easy/.env` or the deploy shell.

**Commit:** `docs(agent-forge): record deployment fact checklist`

### Task 2.1.3: Demo Deploy Script (Reset-And-Seed Model)

**Status:** [x] Code complete; one captured run still required as evidence
**Description:** Encode the operator's actual deploy workflow with health checks and seeded re-load of fake data.

**Subtasks:**

- [x] Pull `--ff-only` before bringing the stack down (fail fast on merge/network issues).
- [x] Run `docker compose down -v` and `docker compose up -d` from `docker/development-easy/`.
- [x] Wait for the public app to return 2xx/3xx before seeding.
- [x] Invoke the demo data seed script if present; warn loudly if not.
- [x] Print old commit, new commit, and rollback target.
- [ ] Capture one real deploy transcript on the VM and add it under Deploy Evidence below.

**Commit:** `chore(agent-forge): align deploy script with reset-and-seed workflow`

### Task 2.1.4: Code Rollback (Re-Seed Model)

**Status:** [x] Code rollback scripted; database rollback is intentionally not provided
**Description:** Roll back code to a prior commit and re-seed fake demo data. There is no database rollback because every deploy resets data.

**Subtasks:**

- [x] Require an explicit rollback commit.
- [x] Reset the stack and bring it up at the target commit.
- [x] Run health checks after rollback.
- [x] Re-seed fake demo data after rollback.
- [x] State explicitly that database rollback to a prior point in time is not implemented.
- [ ] Capture one real rollback transcript on the VM and add it under Rollback Evidence below.

**Commit:** `docs(agent-forge): document reset-and-seed rollback`

---

## VM Deployment Fact Checklist

| Fact | Status | How to verify | Result |
| --- | --- | --- | --- |
| Repo path | Verified | Operator deploy workflow uses `~/repos/openemr` | `~/repos/openemr` |
| Compose command | Verified | Operator deploy workflow uses `docker compose` | `docker compose` |
| Compose directory | Verified | Operator deploy workflow uses `docker/development-easy/` | `docker/development-easy/` |
| Docker permission | Verified | Operator deploy workflow runs `docker compose` without sudo | No sudo required |
| Volume behavior | Verified | Operator deploy workflow uses `down -v` deliberately | Reset on deploy by design |
| Active deployment branch | Pending | `git -C ~/repos/openemr rev-parse --abbrev-ref HEAD` | Pending VM observation |
| Git remote | Pending | `git -C ~/repos/openemr remote -v` | Pending VM observation |
| TLS termination | Pending | Inspect `docker ps` ports, host listeners on 80/443, and any reverse-proxy config | Pending VM observation |
| Environment variables | Pending | `cat ~/repos/openemr/docker/development-easy/.env` and `env` on deploy shell | Pending VM observation |
| Public app health | Verified | `agent-forge/scripts/health-check.sh` | HTTP 200 on 2026-04-30 |
| Public readiness health | Pending re-check | `agent-forge/scripts/health-check.sh` | Endpoint is treated as informational; pass not required |

The deploy script accepts these optional overrides; defaults are correct for the standard VM:

```bash
export AGENTFORGE_REPO_DIR="$HOME/repos/openemr"
export AGENTFORGE_COMPOSE_DIR="docker/development-easy"
export AGENTFORGE_APP_URL="https://openemr.titleredacted.cc/"
export AGENTFORGE_READYZ_URL="https://openemr.titleredacted.cc/meta/health/readyz"
export AGENTFORGE_SEED_SCRIPT="agent-forge/scripts/seed-demo-data.sh"
```

---

## Rollback Proof

Rollback in this project is **code rollback plus re-seed**. There is no database rollback to a prior point in time, by design.

Code rollback is available after every deploy because `deploy-vm.sh` prints the pre-deploy commit as the rollback target.

Rollback command sequence on the VM:

```bash
agent-forge/scripts/rollback-vm.sh "<rollback-commit-from-deploy-output>"
```

This will: switch to the target commit, `docker compose down -v`, `docker compose up -d`, run health checks, and re-seed fake demo data. Demo data is re-loaded from scratch — any state created in the rolled-back deploy (test answers, audit log entries) is lost. This is acceptable because no real PHI is ever stored.

---

## Health Check Evidence

Run from this workspace on 2026-04-30:

```text
Checking public app: https://openemr.titleredacted.cc/
PASS public app: HTTP 200
Checking readiness endpoint: https://openemr.titleredacted.cc/meta/health/readyz
PASS readiness endpoint: HTTP 200
Health check passed.
```

---

## Review Checkpoint

- [x] One command exists for public app and readiness health checks.
- [x] Deploy script encodes the operator's actual workflow (pull, `down -v`, `up -d`, health, seed).
- [x] Rollback script requires an explicit commit, reruns health checks, and re-seeds fake data.
- [x] Deploy and rollback scripts both call the seed script and warn if it is absent.
- [x] Database rollback is documented as intentionally not implemented.
- [ ] One real deploy transcript captured under Deploy Evidence.
- [ ] One real rollback transcript captured under Rollback Evidence.
- [ ] Active deployment branch, git remote, TLS termination, and env vars observed and recorded in the fact checklist.
- [ ] Seed script (`agent-forge/scripts/seed-demo-data.sh`) exists — tracked under Epic 3.

---

## Commit Log

_Commits will be logged here as tasks complete._

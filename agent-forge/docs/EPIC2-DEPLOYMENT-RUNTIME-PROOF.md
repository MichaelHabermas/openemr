# Epic: Deployment And Runtime Proof

**Generated:** 2026-04-30
**Scope:** deployment tooling and operational verification
**Status:** In Progress

---

## First-Principles Plan

The target outcome is a public OpenEMR deployment that can be checked, updated, and rolled back without relying on unverified VM assumptions.

The premise to reject is that "a deploy script" is the first deliverable. A deploy script is dangerous until the branch, remote, compose command, Docker permissions, TLS shape, environment variables, and volume safety are known. The safe sequence is health proof first, VM fact capture second, guarded deploy third, rollback proof fourth.

Hard constraints:

- The public app URL is `https://openemr.titleredacted.cc/`.
- The public readiness endpoint is expected at `https://openemr.titleredacted.cc/meta/health/readyz` when exposed.
- The VM repository path is expected to be `~/repos/openemr`.
- The compose file is `docker/development-easy/docker-compose.yml`.
- Docker volumes must not be deleted.
- Unknown VM facts must remain unknown instead of being guessed.

What this epic deletes:

- No `docker compose down -v`.
- No database reset.
- No assumption about active branch or remote.
- No assumption about `docker compose` versus `docker-compose`.
- No claim that data rollback exists until backup or snapshot behavior is verified.

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

### Task 2.1.2: Verify VM Deployment Unknowns

**Status:** [ ] Pending VM access
**Description:** Record deployment facts before permitting deploy automation.

**Subtasks:**

- [x] Define the required fact checklist.
- [ ] Verify active deployment branch.
- [ ] Verify git remote name.
- [ ] Verify whether the VM uses `docker compose` or `docker-compose`.
- [ ] Verify Docker permissions for the deploy user.
- [ ] Verify TLS termination and public readiness behavior.
- [ ] Verify required environment variables.
- [ ] Verify Docker volume preservation requirements.

**Commit:** `docs(agent-forge): record deployment fact checklist`

### Task 2.1.3: Create Non-Destructive Deploy Script

**Status:** [x] Complete
**Description:** Add guarded deploy automation that refuses to run until VM facts are explicitly supplied.

**Subtasks:**

- [x] Print current branch, old commit, new commit, compose file, health result, and rollback target.
- [x] Pull with `--ff-only` from the verified remote and branch.
- [x] Restart the compose stack without deleting volumes.
- [x] Run public health checks after restart.
- [x] Exit non-zero on missing facts or failed health checks.

**Commit:** `chore(agent-forge): add guarded vm deploy script`

### Task 2.1.4: Verify Rollback Path Before Demo Recording

**Status:** [x] Code rollback scripted, data rollback pending VM proof
**Description:** Document and script code rollback, while refusing to imply database rollback exists before backup behavior is known.

**Subtasks:**

- [x] Require an explicit rollback commit.
- [x] Restart the compose stack after switching to the rollback commit.
- [x] Run health checks after rollback.
- [x] Mark database rollback as unavailable until VM backup or snapshot facts are verified.

**Commit:** `docs(agent-forge): document rollback proof`

---

## VM Deployment Fact Checklist

| Fact | Status | How to verify | Result |
| --- | --- | --- | --- |
| Active deployment branch | Unknown | `cd ~/repos/openemr && git rev-parse --abbrev-ref HEAD` | Pending VM access |
| Git remote name | Unknown | `cd ~/repos/openemr && git remote -v` | Pending VM access |
| Compose command | Unknown | `docker compose version` then `docker-compose version` if needed | Pending VM access |
| Docker permission | Unknown | Run the verified compose command against `docker/development-easy/docker-compose.yml` | Pending VM access |
| TLS termination | Unknown | Compare container ports, public URL behavior, and any reverse proxy config | Pending VM access |
| Environment variables | Unknown | `env | grep -E '^(WT_HTTP_PORT|WT_HTTPS_PORT|OPENEMR_DIR)='` | Pending VM access |
| Volume preservation | Unknown | `docker volume ls` and compose service volume mapping review | Pending VM access |
| Public app health | Verified | `agent-forge/scripts/health-check.sh` | HTTP 200 on 2026-04-30 |
| Public readiness health | Verified | `agent-forge/scripts/health-check.sh` | HTTP 200 on 2026-04-30 |

The deploy script requires these environment variables once facts are verified:

```bash
export AGENTFORGE_DEPLOY_BRANCH="<verified-branch>"
export AGENTFORGE_GIT_REMOTE="<verified-remote>"
export AGENTFORGE_COMPOSE_COMMAND="<docker compose-or-docker-compose>"
export AGENTFORGE_DOCKER_PERMISSION_VERIFIED="yes"
export AGENTFORGE_VOLUME_PRESERVATION_VERIFIED="yes"
```

Optional overrides:

```bash
export AGENTFORGE_REPO_DIR="$HOME/repos/openemr"
export AGENTFORGE_COMPOSE_FILE="docker/development-easy/docker-compose.yml"
export AGENTFORGE_APP_URL="https://openemr.titleredacted.cc/"
export AGENTFORGE_READYZ_URL="https://openemr.titleredacted.cc/meta/health/readyz"
```

---

## Rollback Proof

Code rollback is available after every deploy because `deploy-vm.sh` prints the pre-deploy commit as the rollback target.

Rollback command sequence on the VM:

```bash
cd ~/repos/openemr
export AGENTFORGE_COMPOSE_COMMAND="<verified-compose-command>"
agent-forge/scripts/rollback-vm.sh "<rollback-commit-from-deploy-output>"
```

Data rollback is **not verified**. Do not claim database rollback is available until the VM backup or snapshot mechanism is identified and tested. Docker volumes must be preserved unless an operator explicitly approves otherwise.

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
- [x] Deploy automation refuses to run until VM facts are explicit.
- [x] Deploy automation avoids `down -v` and does not delete Docker volumes.
- [x] Rollback path requires an explicit commit and reruns health checks.
- [ ] VM checklist is completed with observed facts.
- [x] Public health check is run from an environment with network access.

---

## Commit Log

_Commits will be logged here as tasks complete._

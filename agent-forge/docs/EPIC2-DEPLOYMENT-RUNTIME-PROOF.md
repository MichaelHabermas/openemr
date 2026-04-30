# Epic: Deployment And Runtime Proof

**Generated:** 2026-04-30
**Scope:** deployment tooling and operational verification
**Status:** In Progress

---

## First-Principles Plan

The target outcome is a public OpenEMR deployment that can be checked, updated, and re-seeded reliably for demo purposes.

This is a fake-data demo. The deploy preserves Docker volumes and relies on the idempotent demo-data seed (`agent-forge/scripts/seed-demo-data.sh`) to restore known demo state for `pid=900001` on every deploy. Volumes are *not* wiped because the upstream MariaDB 11.8.6 image's first-init is fragile on the demo VM — see "Known VM Bootstrap Fragility" below. There is no point-in-time database rollback in this project — recovery is by re-seed, not by snapshot.

Hard constraints:

- The public app URL is `https://openemr.titleredacted.cc/`.
- The public readiness endpoint is expected at `https://openemr.titleredacted.cc/meta/health/readyz` when exposed.
- The VM repository path is `~/repos/openemr`.
- The compose directory is `docker/development-easy/`.
- The compose command is `docker compose` (verified).
- Only fake demo data is permitted in the deployed database.
- Demo data must be re-seedable from scratch on every deploy.
- Deploys must not wipe Docker volumes (no `down -v`) — see "Known VM Bootstrap Fragility".

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

**Status:** [x] Complete — all facts observed on `gauntlet-mgh` 2026-04-30
**Description:** Record verified deployment facts on the VM.

**Subtasks:**

- [x] Compose command: `docker compose` (verified — operator workflow uses it directly).
- [x] Docker permission: deploy user runs `docker compose` without sudo (verified — operator workflow uses it directly).
- [x] Volume behavior: volumes are preserved across deploys (`docker compose down`, no `-v`) — see "Known VM Bootstrap Fragility".
- [x] Repo path: `~/repos/openemr` (verified — operator workflow uses it directly).
- [x] Active deployment branch: `master`.
- [x] Git remote: `git@github.com:MichaelHabermas/openemr.git` (origin, fetch+push).
- [x] TLS termination: Cloudflare edge in front (server: cloudflare, cf-ray header); OpenEMR container also serves :443 as origin.
- [x] Required environment variables: no `docker/development-easy/.env` file present; stack runs on compose defaults only.

**Commit:** `docs(agent-forge): record deployment fact checklist`

### Task 2.1.3: Demo Deploy Script (Reset-And-Seed Model)

**Status:** [x] Code complete; one captured run still required as evidence
**Description:** Encode the operator's actual deploy workflow with health checks and seeded re-load of fake data.

**Subtasks:**

- [x] Pull `--ff-only` before bringing the stack down (fail fast on merge/network issues).
- [x] Run `docker compose down` and `docker compose up -d` from `docker/development-easy/` (volumes preserved — see "Known VM Bootstrap Fragility").
- [x] Wait for the public app to return 2xx/3xx before seeding.
- [x] Invoke the demo data seed script if present; warn loudly if not.
- [x] Print old commit, new commit, and rollback target.
- [ ] Capture one real deploy transcript on the VM and add it under Deploy Evidence below.

**Commit:** `chore(agent-forge): align deploy script with reset-and-seed workflow`

### Task 2.1.4: Code Rollback (Re-Seed Model)

**Status:** [x] Code rollback scripted; database rollback is intentionally not provided
**Description:** Roll back code to a prior commit and re-seed fake demo data. Volumes are preserved across rollbacks (see "Known VM Bootstrap Fragility"); the idempotent seed restores known demo state for `pid=900001`. There is no point-in-time database rollback.

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
| Volume behavior | Verified 2026-04-30 | Deploy script uses `docker compose down` (no `-v`); seed restores demo state | Volumes preserved across deploys |
| Active deployment branch | Verified 2026-04-30 | `git -C ~/repos/openemr rev-parse --abbrev-ref HEAD` | `master` |
| Git remote | Verified 2026-04-30 | `git -C ~/repos/openemr remote -v` | `git@github.com:MichaelHabermas/openemr.git` (origin) |
| TLS termination | Verified 2026-04-30 | `docker ps` shows OpenEMR container binding :80/:443 directly; `curl -sI https://openemr.titleredacted.cc/` returns `server: cloudflare` and `cf-ray` headers | Cloudflare edge in front; OpenEMR container also serves :443 as origin |
| Environment variables | Verified 2026-04-30 | `cat ~/repos/openemr/docker/development-easy/.env` | No `.env` file; compose defaults only |
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

This will: switch to the target commit, `docker compose down`, `docker compose up -d` (volumes preserved), run health checks, and re-seed fake demo data. The seed deletes and re-inserts only `pid=900001`, restoring known demo state without dropping tables or wiping volumes. Any state created in the rolled-back deploy outside the seeded patient is left in place; this is acceptable because no real PHI is ever stored.

---

## Known VM Bootstrap Fragility

Observed on `gauntlet-mgh` 2026-04-30. The upstream MariaDB 11.8.6 image's first-init does not reliably complete on this VM. When the database volume is wiped (`docker compose down -v`) and the stack is brought back up, the failure mode is:

1. The first `mariadbd` start aborts before initialization finishes (observed at ~35s into startup).
2. On the next start, the container sees a partially-initialized data directory and skips the first-init path. `MYSQL_ROOT_PASSWORD` only fires on a true first init, so the root password is never set on the second pass.
3. `MARIADB_AUTO_UPGRADE` is not set, so the healthcheck user (`mysql@localhost`) is also never created on the second pass.
4. The mysql container reports `unhealthy`. The openemr container's `auto_configure.php` then fails with `unable to connect to database as root` (a generic error from `Installer.class.php` that masks the underlying credential failure).

Recovery from this state requires hand-fixing the database (set the root password via socket auth, create the healthcheck user) before the stack will come back up — not acceptable for an automated deploy.

The mitigation is structural: the deploy and rollback scripts use `docker compose down` (no `-v`), which preserves the volume that was successfully bootstrapped on first install. Demo state is restored through the idempotent seed (`agent-forge/scripts/seed-demo-data.sh`), which deletes and re-inserts only `pid=900001` and never drops tables or volumes. This is sufficient for the demo because no real PHI is ever stored and the seed is the source of truth for known demo state.

The volume bootstrap is required exactly once, by hand, when the VM is first provisioned. After that, deploys and rollbacks must not wipe it.

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

## Epic 3 Demo Data — Deployed Verification

Captured 2026-04-30 on the demo VM (`gauntlet-mgh`) after `git pull` brought the Epic 3 seed and verifier scripts onto the host. The seed is idempotent and the verifier asserts the chart-render contract documented in `agent-forge/docs/EPIC3-DEMO-DATA-AND-EVAL-GROUND-TRUTH.md`.

Seed run:

```text
root@gauntlet-mgh:~/repos/openemr# agent-forge/scripts/seed-demo-data.sh
Seeding AgentForge demo data from agent-forge/sql/seed-demo-data.sql
PASS seed: fake demo patient pid=900001 loaded.
```

Verifier run:

```text
root@gauntlet-mgh:~/repos/openemr# agent-forge/scripts/verify-demo-data.sh
PASS demographics: 1
PASS active problems: 2
PASS active medications: 2
PASS recent encounter: 1
PASS last plan note: 1
PASS a1c lab trend: 2
PASS known missing microalbumin: 0
PASS encounter linked into forms: 1
PASS clinical note linked to forms row: 1
PASS a1c result chain (order to report to result): 2
PASS no contradicting metformin titration: 0
PASS verify: all AgentForge demo data checks passed.
```

This closes the PLAN Task 3.1.2 requirement that the seed work in both local and deployed demo environments, and satisfies the SPECS hard gate that the deployed app must work for every submission.

---

## Review Checkpoint

- [x] One command exists for public app and readiness health checks.
- [x] Deploy script encodes the operator's actual workflow (pull, `down`, `up -d`, health, seed); volumes preserved per "Known VM Bootstrap Fragility".
- [x] Rollback script requires an explicit commit, reruns health checks, and re-seeds fake data.
- [x] Deploy and rollback scripts both call the seed script and warn if it is absent.
- [x] Database rollback is documented as intentionally not implemented.
- [ ] One real deploy transcript captured under Deploy Evidence.
- [ ] One real rollback transcript captured under Rollback Evidence.
- [x] Active deployment branch, git remote, TLS termination, and env vars observed and recorded in the fact checklist.
- [x] Seed script (`agent-forge/scripts/seed-demo-data.sh`) exists and runs green on the deployed VM — see Epic 3 Demo Data — Deployed Verification above.

---

## Commit Log

_Commits will be logged here as tasks complete._

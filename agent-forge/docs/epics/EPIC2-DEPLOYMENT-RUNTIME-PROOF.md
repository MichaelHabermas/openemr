# Epic: Deployment And Runtime Proof

**Generated:** 2026-04-30
**Scope:** deployment tooling and operational verification
**Status:** Complete

---

## First-Principles Plan

The target outcome is a public OpenEMR deployment that can be checked, updated, and re-seeded reliably for demo purposes.

This is a fake-data demo. The deploy preserves Docker volumes and relies on the idempotent demo-data seed (`agent-forge/scripts/seed-demo-data.sh`) to restore known demo state for fixed AgentForge demo patients (`pid` in `900001`–`900006` and `900101`, etc.—see `agent-forge/sql/seed-demo-data.sql`) on every deploy. Volumes are *not* wiped because the upstream MariaDB 11.8.6 image's first-init is fragile on the demo VM — see "Known VM Bootstrap Fragility" below. There is no point-in-time database rollback in this project — recovery is by re-seed, not by snapshot.

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

**Status:** [x] Complete — deploy transcript captured 2026-04-30
**Description:** Encode the operator's actual deploy workflow with health checks and seeded re-load of fake data.

**Subtasks:**

- [x] Pull `--ff-only` before bringing the stack down (fail fast on merge/network issues).
- [x] Run `docker compose down` and `docker compose up -d` from `docker/development-easy/` (volumes preserved — see "Known VM Bootstrap Fragility").
- [x] Wait for the public app to return 2xx/3xx before seeding.
- [x] Invoke the demo data seed script if present; warn loudly if not.
- [x] Print old commit, new commit, and rollback target.
- [x] Capture one real deploy transcript on the VM and add it under Deploy Evidence below.

**Commit:** `chore(agent-forge): align deploy script with reset-and-seed workflow`

### Task 2.1.4: Code Rollback (Re-Seed Model)

**Status:** [x] Complete — rollback transcript captured 2026-04-30; point-in-time database rollback intentionally not provided
**Description:** Roll back code to a prior commit and re-seed fake demo data. Volumes are preserved across rollbacks (see "Known VM Bootstrap Fragility"); the idempotent seed restores known demo state for the fixed AgentForge demo `pid` set (see `seed-demo-data.sql`). There is no point-in-time database rollback.

**Subtasks:**

- [x] Require an explicit rollback commit.
- [x] Reset the stack and bring it up at the target commit.
- [x] Run health checks after rollback.
- [x] Re-seed fake demo data after rollback.
- [x] State explicitly that database rollback to a prior point in time is not implemented.
- [x] Capture one real rollback transcript on the VM and add it under Rollback Evidence below.

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
| Public app health | Verified | `agent-forge/scripts/health-check.sh` | HTTP 200 on 2026-04-30; H3 now also requires runtime readiness |
| Public readiness health | H3 contract implemented 2026-05-06; `InstallationCheck` `$config` collision fixed 2026-05-07 | `agent-forge/scripts/health-check.sh` | Required full-health gate: `/readyz` must report `status=ready`, MariaDB 11.8+, fresh `agentforge-worker` heartbeat, and healthy clinical-document queue |
| Deploy branch reattach | Implemented in `deploy-vm.sh` | After `rollback-vm.sh` leaves a detached HEAD, deploy runs `git switch` to `AGENTFORGE_DEPLOY_BRANCH` (default `master`) before `git pull --ff-only` | Avoids deploying from the wrong commit |
| OpenAI key for default provider | Implemented in `deploy-vm.sh` | When `AGENTFORGE_DRAFT_PROVIDER` is `openai` (default), `AGENTFORGE_OPENAI_API_KEY` or `OPENAI_API_KEY` must be set in the shell or in `docker/development-easy/.env` | Deploy fails fast with a clear message if missing |
| Anthropic key when provider is anthropic | Implemented in `deploy-vm.sh` | When `AGENTFORGE_DRAFT_PROVIDER` is `anthropic`, `AGENTFORGE_ANTHROPIC_API_KEY` or `ANTHROPIC_API_KEY` must be set (same locations as OpenAI) | Deploy fails fast with a clear message if missing |
| Post-`up` health polling | Implemented in `deploy-vm.sh` | `AGENTFORGE_HEALTH_TIMEOUT_SECONDS` (default 300) and `AGENTFORGE_HEALTH_INTERVAL_SECONDS` (default 5) bound the wait for the public app URL | Tunable when Cloudflare origin recovery is slow |

The deploy script accepts these optional overrides; defaults are correct for the standard VM:

```bash
export AGENTFORGE_REPO_DIR="$HOME/repos/openemr"
export AGENTFORGE_COMPOSE_DIR="docker/development-easy"
export AGENTFORGE_APP_URL="https://openemr.titleredacted.cc/"
export AGENTFORGE_READYZ_URL="https://openemr.titleredacted.cc/meta/health/readyz"
export AGENTFORGE_SEED_SCRIPT="agent-forge/scripts/seed-demo-data.sh"
export AGENTFORGE_DEPLOY_BRANCH="master"
export AGENTFORGE_HEALTH_TIMEOUT_SECONDS="300"
export AGENTFORGE_HEALTH_INTERVAL_SECONDS="5"
export AGENTFORGE_DRAFT_PROVIDER="openai"
# export AGENTFORGE_OPENAI_API_KEY="..."   # or OPENAI_API_KEY; or set in docker/development-easy/.env
```

### Deploy script behavior (aligned with `deploy-vm.sh`)

- **Compose `.env`:** If `docker/development-easy/.env` exists, the deploy script sources it before validating model config, so keys and provider can live in that file.
- **Model config gate:** With `AGENTFORGE_DRAFT_PROVIDER=openai`, deploy exits with an error unless an OpenAI API key is available (env or loaded `.env`). With `AGENTFORGE_DRAFT_PROVIDER=anthropic`, deploy exits unless an Anthropic API key is available. Override the provider only if the stack is intentionally fixture-only. (Runtime inference when `AGENTFORGE_DRAFT_PROVIDER` is unset—e.g. Anthropic key selecting Anthropic—is handled inside OpenEMR; see `agent-forge/.env.sample`.)
- **Branch:** If the current branch name is not `AGENTFORGE_DEPLOY_BRANCH`, deploy prints a switch message and runs `git switch` to that branch before pulling.

### `health-check.sh` vs `deploy-vm.sh`

- **`health-check.sh`** requires the public app URL to return 2xx/3xx, requires `/meta/health/readyz` to return success, and validates the readiness JSON with PHP. The required H3 runtime payload includes `components.agentforge_runtime.mariadb`, `components.agentforge_runtime.worker`, and `components.agentforge_runtime.queue`; the payload must stay PHI-safe.
- **`deploy-vm.sh`** starts `mysql`, `openemr`, and `agentforge-worker`, waits for full `health-check.sh` success after `docker compose up -d`, runs the idempotent demo seed, then waits for full health again. Deploy now fails if the runtime readiness contract does not become healthy within the timeout.
- **`rollback-vm.sh`** preserves volumes, recreates the runtime, conditionally starts `agentforge-worker` when the rollback target defines it, runs full health, re-seeds fake demo data, then runs full health again.
- **Shared implementation:** both scripts source `agent-forge/scripts/lib/deploy-common.sh` so curl timeouts and HTTP success rules stay aligned.

### Automation parity (EPIC2 operator ritual vs CI Tier 4)

EPIC2 is an **operator checklist** (deploy transcript, rollback transcript, health-check, seed/verify). Tier 4 (`agent-forge/scripts/run-deployed-smoke.php` via `.github/workflows/agentforge-deployed-smoke.yml`) is **scheduled / manual dispatch** and exercises the full browser-like chat HTTP path (session, CSRF, `agent_request.php`, optional SSH audit-log grep). Week 2 adds a separate clinical-document deployed smoke (`agent-forge/scripts/run-clinical-document-deployed-smoke.php`) that proves upload -> worker job -> cited clinical answer -> PHI-safe artifact. These are complementary, not duplicates.

| Proof surface | EPIC2 / VM scripts | Automated (CI) |
| --- | --- | --- |
| Public URL returns 2xx/3xx | `health-check.sh`, deploy/rollback wait loops | Tier 4 HTTP checks against `AGENTFORGE_DEPLOYED_URL` |
| Readiness URL and runtime components | Required by `health-check.sh`; deploy/rollback block on it after compose and after seed | Not required for the older Tier 4 chat smoke; required before recording H3 deployment proof |
| Code + compose recycle | `deploy-vm.sh`, `rollback-vm.sh` | Not run on PRs |
| Demo SQL contract | `seed-demo-data.sh` + `verify-demo-data.sh` | Tier 1: `seed-demo-data.sql` + `verify-demo-data.sh` with `AGENTFORGE_VERIFY_TRANSPORT=direct` before SQL evidence evals |
| Tier 0 fixture evals | Local / optional | `agentforge-evals.yml` job `tier0-fixtures` |
| Live LLM (Tier 2) | Documented env + `run-tier2-evals.php` | `agentforge-tier2.yml` (not PR-blocking) |
| Cross-patient / audit correlation | Manual checklist items | Tier 4 scenarios + optional `AGENTFORGE_VM_SSH_HOST` |
| Week 2 clinical document runtime | `run-clinical-document-deployed-smoke.php` after full health | Manual deployed proof runner; writes `clinical-document-deployed-smoke-*.json` |

**Intentional drift:** PR merges can be green on Tier 0/1 + global isolated tests while Tier 4 or `agent-forge/scripts/check-agentforge.sh` has not run. Treat Tier 4 and the comprehensive local gate as **release** or **demo** proofs, not as substitutes for each other.

---

## Rollback Proof

Rollback in this project is **code rollback plus re-seed**. There is no database rollback to a prior point in time, by design.

Code rollback is available after every deploy because `deploy-vm.sh` prints the pre-deploy commit as the rollback target.

Rollback command sequence on the VM:

```bash
agent-forge/scripts/rollback-vm.sh "<rollback-commit-from-deploy-output>"
```

This will: switch to the target commit, `docker compose down`, `docker compose up -d` (volumes preserved), run health checks, and re-seed fake demo data. The seed deletes and re-inserts only the AgentForge demo `pid` rows (see `agent-forge/sql/seed-demo-data.sql`), restoring known demo state without dropping tables or wiping volumes. Any state created in the rolled-back deploy outside the seeded patient is left in place; this is acceptable because no real PHI is ever stored.

---

## Known VM Bootstrap Fragility

Observed on `gauntlet-mgh` 2026-04-30. The upstream MariaDB 11.8.6 image's first-init does not reliably complete on this VM. When the database volume is wiped (`docker compose down -v`) and the stack is brought back up, the failure mode is:

1. The first `mariadbd` start aborts before initialization finishes (observed at ~35s into startup).
2. On the next start, the container sees a partially-initialized data directory and skips the first-init path. `MYSQL_ROOT_PASSWORD` only fires on a true first init, so the root password is never set on the second pass.
3. `MARIADB_AUTO_UPGRADE` is not set, so the healthcheck user (`mysql@localhost`) is also never created on the second pass.
4. The mysql container reports `unhealthy`. The openemr container's `auto_configure.php` then fails with `unable to connect to database as root` (a generic error from `Installer.class.php` that masks the underlying credential failure).

Recovery from this state requires hand-fixing the database (set the root password via socket auth, create the healthcheck user) before the stack will come back up — not acceptable for an automated deploy.

The mitigation is structural: the deploy and rollback scripts use `docker compose down` (no `-v`), which preserves the volume that was successfully bootstrapped on first install. Demo state is restored through the idempotent seed (`agent-forge/scripts/seed-demo-data.sh`), which deletes and re-inserts only the AgentForge demo `pid` set and never drops tables or volumes. This is sufficient for the demo because no real PHI is ever stored and the seed is the source of truth for known demo state.

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

## Deploy Evidence

Captured 2026-04-30 on the demo VM (`gauntlet-mgh`) at commit `6323b43ad` with the aligned `deploy-vm.sh`. Cloudflare's edge takes ~90 seconds to re-establish the origin connection after the stack cycles, which is why the wait loop sees several `HTTP 521` responses before the public app returns 200.

```text
root@gauntlet-mgh:~/repos/openemr# agent-forge/scripts/deploy-vm.sh
Repo: /root/repos/openemr
Branch: master
Old commit: 6323b43ad92fb25812672379f622820646a42387
Already up to date.
New commit: 6323b43ad92fb25812672379f622820646a42387
Compose dir: docker/development-easy
[+] down 8/8
 ✔ Container development-easy-mailpit-1    Removed
 ✔ Container development-easy-openemr-1    Removed
 ✔ Container development-easy-selenium-1   Removed
 ✔ Container development-easy-phpmyadmin-1 Removed
 ✔ Container development-easy-couchdb-1    Removed
 ✔ Container development-easy-openldap-1   Removed
 ✔ Container development-easy-mysql-1      Removed
 ✔ Network development-easy_default        Removed
[+] up 8/8
 ✔ Network development-easy_default        Created
 ✔ Container development-easy-mysql-1      Healthy
 ✔ Container development-easy-selenium-1   Started
 ✔ Container development-easy-couchdb-1    Started
 ✔ Container development-easy-openldap-1   Started
 ✔ Container development-easy-mailpit-1    Started
 ✔ Container development-easy-phpmyadmin-1 Started
 ✔ Container development-easy-openemr-1    Started
WAIT public app: curl exit 0, HTTP 521
WAIT public app: curl exit 0, HTTP 521
WAIT public app: curl exit 0, HTTP 521
WAIT public app: curl exit 0, HTTP 521
WAIT public app: curl exit 0, HTTP 521
WAIT public app: curl exit 0, HTTP 521
WAIT public app: curl exit 0, HTTP 521
WAIT public app: curl exit 0, HTTP 521
PASS public app: HTTP 200
PASS readiness endpoint: HTTP 200
Seeding fake demo data: agent-forge/scripts/seed-demo-data.sh
Seeding AgentForge demo data from agent-forge/sql/seed-demo-data.sql
PASS seed: fake demo patient pid=900001 loaded.
Deploy succeeded.
Rollback target: 6323b43ad92fb25812672379f622820646a42387
```

---

## Rollback Evidence

Captured 2026-04-30 on the demo VM (`gauntlet-mgh`). Rollback target was `bfd666d56` (the demo-data scripts commit). The script reattaches HEAD to the target, recreates the stack, polls health-check until the Cloudflare edge sees the origin again, then re-seeds. The intermediate `Health check failed` lines are expected output from the polling loop, not failures of the rollback itself.

```text
root@gauntlet-mgh:~/repos/openemr# agent-forge/scripts/rollback-vm.sh bfd666d56
Current commit: 6323b43ad92fb25812672379f622820646a42387
Rollback target: bfd666d56
HEAD is now at bfd666d56 feat(agent-forge): add demo data generation and verification scripts
[+] down 8/8
 ✔ Container development-easy-phpmyadmin-1 Removed
 ✔ Container development-easy-couchdb-1    Removed
 ✔ Container development-easy-openldap-1   Removed
 ✔ Container development-easy-mailpit-1    Removed
 ✔ Container development-easy-openemr-1    Removed
 ✔ Container development-easy-selenium-1   Removed
 ✔ Container development-easy-mysql-1      Removed
 ✔ Network development-easy_default        Removed
[+] up 8/8
 ✔ Network development-easy_default        Created
 ✔ Container development-easy-selenium-1   Started
 ✔ Container development-easy-couchdb-1    Started
 ✔ Container development-easy-openldap-1   Started
 ✔ Container development-easy-mailpit-1    Started
 ✔ Container development-easy-mysql-1      Healthy
 ✔ Container development-easy-phpmyadmin-1 Started
 ✔ Container development-easy-openemr-1    Started
Checking public app: https://openemr.titleredacted.cc/
FAIL public app: HTTP 521
Checking readiness endpoint: https://openemr.titleredacted.cc/meta/health/readyz
FAIL readiness endpoint: HTTP 521
Health check failed: 2 endpoint(s) failed.
Health check not ready yet; retrying in 5s...
[ ... wait loop continues for ~90s while Cloudflare edge re-establishes origin connection ... ]
Health check not ready yet; retrying in 5s...
Checking public app: https://openemr.titleredacted.cc/
PASS public app: HTTP 200
Checking readiness endpoint: https://openemr.titleredacted.cc/meta/health/readyz
PASS readiness endpoint: HTTP 200
Health check passed.
Seeding fake demo data: agent-forge/scripts/seed-demo-data.sh
Seeding AgentForge demo data from agent-forge/sql/seed-demo-data.sql
PASS seed: fake demo patient pid=900001 loaded.
Rollback completed to bfd666d56.
```

**Operational note about rollback targets:** the rollback script checks out an older commit, which means the rolled-back tree contains the version of `agent-forge/scripts/` that existed at that target. If a rollback target predates a fix to one of those scripts, the script behavior on the rolled-back tree will reflect the older version. After a rollback, `git switch master` followed by `agent-forge/scripts/deploy-vm.sh` is the correct way to roll forward; this restores the latest scripts before deploying. This was observed during the 2026-04-30 capture and is documented here so future operators are not surprised by it.

---

## Epic 3 Demo Data — Deployed Verification

Captured 2026-04-30 on the demo VM (`gauntlet-mgh`) after `git pull` brought the Epic 3 seed and verifier scripts onto the host. The seed is idempotent and the verifier asserts the chart-render contract documented in `agent-forge/docs/epics/EPIC3-DEMO-DATA-AND-EVAL-GROUND-TRUTH.md`.

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

## H2/H3 Deployed Verification — 2026-05-07

Captured on the demo VM (`gauntlet-mgh`) after deploying the clinical-document smoke fix (citation parser), guideline corpus indexing in `deploy-vm.sh`, and DeterministicReranker score-dilution fix.

### Deploy pipeline

Deploy now includes guideline corpus indexing between schema upgrade and seed:

```text
Applying schema upgrades...
  8_1_0-to-8_1_1_upgrade.sql: 137 directives processed, 131 already present
Indexing clinical guideline corpus...
Indexed 25 guideline chunks with embeddings.
Seeding fake demo data...
PASS seed: fake demo patient pid=900001 loaded.
```

### Automated checks (`verify-deployed.sh`)

```text
agent-forge/scripts/health-check.sh      — PASS (runtime readiness: MariaDB, worker, queue)
agent-forge/scripts/verify-demo-data.sh  — PASS (65 demo data checks)
clinical-document-deployed-smoke          — 1/1 passed (document + guideline citations)
```

The smoke previously failed with `guideline: 0` due to three bugs fixed in this deploy:
1. Citation parser read `citations` (opaque `list<string>`) instead of `citation_details` (arrays with `source_type`).
2. Guideline corpus was never indexed on VM — `index-clinical-guidelines.php` not called from `deploy-vm.sh`.
3. `DeterministicReranker` score dilution below 0.4 threshold for long queries — denominator changed to `min(queryTokens, chunkTokens)`.

### Manual UI verification

Patient: Margaret Chen (pid 900101, BHS-2847163).

| Check | Result |
| --- | --- |
| Co-Pilot cited answer | Response included 12 `clinical_document_facts` citations (IDs 17, 16, 14, 13, 11, 10, 8, 7, 5, 4, 2, 1), vitals, intake findings, clinical notes, and 3 guideline citations (ACC/AHA cholesterol, USPSTF colorectal, hypertension follow-up) |
| Source review overlay | Clicked `document:clinical_document_facts/10` and `/16` → modal rendered citation metadata and quoted fact text ("LDL Cholesterol 158 mg/dL"). PDF page-image area is a CSS-grid placeholder; actual PDF embed is future work |
| Document deletion + retraction | Deleted 4 documents via Documents tab. Re-asked same question: response dropped from 12 to 2 document-fact citations, confirming `PatientDocumentFactsEvidenceTool.php:87` filter on `d.deleted = 0` plus `f.retracted_at IS NULL` and `j.retracted_at IS NULL` gates |

---

## Review Checkpoint

- [x] One command exists for public app and readiness health checks.
- [x] Deploy script encodes the operator's actual workflow (pull, `down`, `up -d`, health, seed); volumes preserved per "Known VM Bootstrap Fragility".
- [x] Rollback script requires an explicit commit, reruns health checks, and re-seeds fake data.
- [x] Deploy and rollback scripts both call the seed script and warn if it is absent.
- [x] Database rollback is documented as intentionally not implemented.
- [x] One real deploy transcript captured under Deploy Evidence.
- [x] One real rollback transcript captured under Rollback Evidence.
- [x] Active deployment branch, git remote, TLS termination, and env vars observed and recorded in the fact checklist.
- [x] Seed script (`agent-forge/scripts/seed-demo-data.sh`) exists and runs green on the deployed VM — see Epic 3 Demo Data — Deployed Verification above.

---

## Commit Log

- `466bf079d` chore(agent-forge): add deployment runtime proof
- `3a6716970` chore(agent-forge): refine deployment and rollback processes for demo environment
- `6323b43ad` fix(agent-forge): make rollback wait for health and let deploy reattach
- `6b36945ff` docs(agent-forge): capture deploy and rollback transcripts as Epic 2 evidence

# Known Facts And Needs

This document contains only facts we currently know and work we know we need. It is not an architecture plan.

## Known Facts

1. The deployment uses Docker Compose.

2. The compose file is:

   `docker/development-easy/docker-compose.yml`

3. The compose profile is the OpenEMR `development-easy` setup.

4. The deployed application is on a Linux VM.

5. The deployed application URL is:

   `https://openemr.titleredacted.cc/`

6. The repository path on the VM is:

   `~/repos/openemr`

7. The compose file defines `openemr`, `mysql`, and `agentforge-worker` services.

8. The `openemr` service exposes HTTP and HTTPS ports through `WT_HTTP_PORT` and `WT_HTTPS_PORT`, defaulting to `80` and `443`.

9. The `openemr` service has a container healthcheck that calls the liveness endpoint:

   `https://localhost/meta/health/livez`

10. The deployed operator health gate is `agent-forge/scripts/health-check.sh`, which checks the public app and `/meta/health/readyz` readiness contract. The readiness contract includes MariaDB 11.8+, a fresh `agentforge-worker` heartbeat, and clinical-document queue health.

## Known Needs

1. We need a deploy script for use after SSHing into the Linux VM.

2. The deploy script must pull the latest code from the git repository.

3. The deploy script must bring the Docker Compose stack down.

4. The deploy script must bring the Docker Compose stack back up.

5. The deploy script must check whether the application is working after restart.

6. The health check should include the deployed public URL:

   `https://openemr.titleredacted.cc/`

7. The health check should also use the OpenEMR readiness endpoint when possible:

   `https://openemr.titleredacted.cc/meta/health/readyz`

8. We need sample patient data for local development, deployment validation, and demo use.

9. The sample data may be fake.

## Deploy Script Requirements

The deploy script should do the following, in order:

1. Stop on errors.
2. Move to `~/repos/openemr` on the VM.
3. Show the current git branch and commit.
4. Pull the latest code from the configured remote.
5. Show the new git commit.
6. Run Docker Compose using `docker/development-easy/docker-compose.yml`.
7. Bring the stack down.
8. Bring the stack up in detached mode.
9. Wait for services to become healthy.
10. Check the public application URL.
11. Check the public readiness endpoint.
12. Print a clear success or failure message.

## Unknowns To Verify On The VM

1. Git branch used for deployment.
2. Git remote name.
3. Whether the VM uses `docker compose` or `docker-compose`.
4. Whether the deploy user has passwordless Docker access.
5. Whether TLS is terminated by the OpenEMR container, a reverse proxy, or VM-level infrastructure.
6. Whether environment variables such as `WT_HTTP_PORT`, `WT_HTTPS_PORT`, or `OPENEMR_DIR` are set on the VM.
7. Whether Docker volumes must be preserved across deploys.
8. How sample data will be created, loaded, and reset.
9. Which sample patients and clinical facts are needed for the demo.

## Do Not Assume Yet

1. Do not assume the active branch.
2. Do not assume the reverse proxy setup.
3. Do not assume database reset is acceptable.
4. Do not use `docker compose down -v`. It is confirmed unsafe on the demo VM because MariaDB first-init has been fragile in this environment. Deploys and rollbacks must use `docker compose down` (no `-v`).
5. Do not use real patient data.
6. Do not assume fake data is already present in the deployed database.

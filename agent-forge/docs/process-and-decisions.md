# Process and Decisions

This document outlines the process and decisions made during the development of the OpenEMR Agent Forge project.

## Setup process — what / why

- **Installed Composer (via Homebrew):** needed PHP and Composer on the machine; README build steps depend on them.
- **Installed `ext-redis` (PECL):** `composer install` failed without it; the project lists Redis as a required extension.
- **Ran `composer install --no-dev`:** pull production PHP dependencies as the upstream docs describe for building from the repo.
- **Ran** `npm install`**,** `npm run build`, and `composer dump-autoload -o`**:** optimize Composer autoload after the build, as the README’s “from repo” sequence specifies.
- **Started the Easy Development Docker environment** (`docker/development-easy`, `docker compose up --detach --wait`): the composer/npm steps only build the tree; this runs OpenEMR with MySQL and the rest of the dev stack so the app is actually reachable, not just compiled on disk.
- **Opened the running instance in the browser** (e.g. `http://localhost:8300/`, or `https://localhost:9300/`): confirmed the project is up; default dev access is documented in `CLAUDE.md` / `CONTRIBUTING.md` (e.g. `admin` / `pass`).

## Git remotes — GitLab and GitHub

Changes are pushed to **both** the GitLab and GitHub remotes.

- **GitLab** is the target Gauntlet expects.
- **GitHub** is kept in sync because deployments and related workflows are easier there.

## Deployment — what we tried and where it landed

- **Tried Railway first:** ran into difficulty getting the build to work and could not get past it, so abandoned the platform.
- **Tried DigitalOcean Droplets next:** payment failed because the Ramp card wasn't accepted for some reason, which blocked provisioning, so abandoned that route as well.
- **Landed on a Vultr Linux VM:** spun up a Linux virtual machine on Vultr, installed Docker and the rest of the required dependencies, pulled the repo, and deployed the app there.
- **Domain from Namecheap:** registered a domain through Namecheap and pointed it at the Vultr VM. That is the live deployment.

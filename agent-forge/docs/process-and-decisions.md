# Process and Decisions

This document outlines the process and decisions made during the development of the OpenEMR Agent Forge project.

## Setup process — what / why

- **Installed Composer (via Homebrew):** needed PHP and Composer on the machine; README build steps depend on them.
- **Installed `ext-redis` (PECL):** `composer install` failed without it; the project lists Redis as a required extension.
- **Ran `composer install --no-dev`:** pull production PHP dependencies as the upstream docs describe for building from the repo.
- **Ran** `npm install`**,** `npm run build`, and `composer dump-autoload -o`**:** optimize Composer autoload after the build, as the README’s “from repo” sequence specifies.
- **Started the Easy Development Docker environment** (`docker/development-easy`, `docker compose up --detach --wait`): the composer/npm steps only build the tree; this runs OpenEMR with MySQL and the rest of the dev stack so the app is actually reachable, not just compiled on disk.
- **Opened the running instance in the browser** (e.g. `http://localhost:8300/`, or `https://localhost:9300/`): confirmed the project is up; default dev access is documented in `CLAUDE.md` / `CONTRIBUTING.md` (e.g. `admin` / `pass`).

## Quick start

From repo root:

```bash
cd docker/development-easy
docker compose up --detach --wait
```

## Git remotes — GitLab and GitHub

Changes are pushed to **both** the GitLab and GitHub remotes.

- **GitLab** is the target Gauntlet expects.
- **GitHub** is kept in sync because deployments and related workflows are easier there.

## Deployment — what we tried and where it landed

- **Tried Railway first:** ran into difficulty getting the build to work and could not get past it, so abandoned the platform.
- **Tried DigitalOcean Droplets next:** payment failed because the Ramp card wasn't accepted for some reason, which blocked provisioning, so abandoned that route as well.
- **Landed on a Vultr Linux VM:** spun up a Linux virtual machine on Vultr, installed Docker and the rest of the required dependencies, pulled the repo, and deployed the app there.
- **Domain from Namecheap:** registered a domain through Namecheap and pointed it at the Vultr VM. That is the live deployment.

## Stage 4 — Users & Use Cases

- **User:**
  - Geriatric-leaning polypharmacy PCP,
  - multi-provider clinic,
  - ~2,000 panel,
  - 18–22 visits/day,
  - 15-min slots.
  - Desktop/WOW, not tablet.
- **Anchor moment:**
  - Chart-open, every visit.
  - Pre-computed at front-desk check-in (~15 min lead) so no LLM call inside chart-open.
- **Agent shape:**
  - Two surfaces, one backend.
  - Card = always-on summary, row-cited.
  - Chat = multi-turn drill-down on the same data.
  - Why: satisfies SPECS multi-turn rule honestly — chat is optional drill-down on a card that works without it.
- **No-inference rule:**
  - Chart-cited facts only.
  - No recommendations, diagnoses, dose changes, or causal reasoning.
  - Why: clinical safety; verification only works when every claim is a row lookup.
- **Retrieval shape:**
  - Tool-grounded over structured EHR (typed tools return rows + IDs).
  - RAG-over-text reserved for unstructured (pnotes, OCR).
- **Placement:**
  - `interface/modules/custom_modules/clinical-copilot/`;
  - agent service is a separate process.
- **UC-1 — Pre-visit "what changed" 4-line card:**
  - Last plan, what's new, trends, meds + flags.
- **UC-2 — Polypharmacy interaction/duplication flag:**
  - Curated JSON rule set (~50–100), flag-only, never "stop X."
  - Why JSON over RxNorm: zero external deps, demo-stable, swappable later.
- **UC-3 — Lab/vital trend drill-down:**
  - Dated values with units, ranges, row IDs.
  - Multi-turn pivots on the same series.
- **UC-4 — "What changed in meds/labs in last 30 days.":**
  - Explicit events only — row-gone ≠ stopped.
  - Why strict: snapshot-diff is inference.
- **Tolerances:**
  - Forgive: "I don't know" with links to what was checked.
  - Project-killer: wrong/fabricated/unsourced med, dose, or lab.
- **Demo data:**
  - Synthea FHIR R4 onto the 14 named pids.
  - Why: keeps the recognizable cast, multi-year course per patient, defensible tool.
- **Killed:**
  - Care-gap dashboard (sorted list does it).
  - Note/portal drafting (generation crosses no-inference).
  - Inbox triage (wrong anchor).
  - Open-ended Q&A standalone (kept only as drill-down surface).
  - "Questions to ask" (inference).
  - Transition-of-care (Stage 6, needs CCDA).

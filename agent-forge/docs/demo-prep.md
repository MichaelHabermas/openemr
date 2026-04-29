# Demo Preparation

Parallel-to-build work that supports the demo video but is **not gating** for
the PRD's eval thresholds. Tracked here so it doesn't fall off the floor
during the demo-video phase.

## Synthea seed for the 14 named patients

[USERS.md §"Demo data"](USERS.md) commits to generating Synthea FHIR R4
bundles re-keyed onto the existing 14 named PIDs:

Farrah Rolle, Ted Shaw, Eduardo Perez, Brent Perez, Wallace Buckley,
Jim Moses, Richard Jones, Ilias Jenane, Jason Binder, John Dockerty,
James Janssen, Jillian Mahoney, Robert Dickey, Nora Cohen.

The eval suite uses three hand-curated fixtures (US-1.1 in
[PRD.md](../planning/PRD.md)) and does **not** depend on Synthea. The demo
video, however, will likely show charts beyond the three eval fixtures.
Without Synthea those charts are demographics-only.

### Tracking

- [ ] Synthea bundles generated for an aligned demographic profile.
- [ ] Bundles re-keyed onto the 14 named PIDs.
- [ ] Loaded on the deployed VM via `agent-forge/scripts/deploy.sh` after the
  hand-curated fixtures.
- [ ] Spot-check: each named patient has ≥1 row in `prescriptions`,
  `procedure_result`, `form_encounter`.

Owner: parallel work — not blocking on PRD Epics. If the demo video can be
shot using the three eval fixtures alone, this work is skippable.

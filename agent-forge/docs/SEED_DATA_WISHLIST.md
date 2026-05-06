# Seed Data Wishlist for Patient Dashboard Modernization

This is a handoff note for creating richer fake OpenEMR test data. The dashboard can be built against sparse data, but these records will make implementation, QA, and demo review much stronger.

## High Priority

- Add at least one patient with 3-5 populated encounters in Visit History, including different dates, reasons/forms, provider names, billing state, and insurance state.
- Add at least one patient with a populated Care Team row, including type, member, role, facility, since date, status, and note.
- Add at least one patient with both populated Medications and populated Prescriptions where the values intentionally differ, so the dashboard can keep medication history and prescription orders separate.
- Add at least one patient with patient header identity fields filled across name, DOB, sex/birth sex, MRN or external ID, and active/inactive status.
- Add at least one patient with realistic allergies, including severity and reaction details. Example: Penicillin, moderate, rash, confirmed; Shellfish, mild, hives, confirmed.
- Add at least one patient with realistic problems, including active and resolved/inactive statuses. Example: Type 2 diabetes mellitus active; Essential hypertension active; Hyperlipidemia resolved/inactive.

## Medium Priority

- Add encounters spanning multiple clinical contexts, such as annual physical, diabetes follow-up, medication reconciliation, urgent visit, and telehealth follow-up.
- Add prescriptions with varied statuses and refill counts, including active, stopped/discontinued, and older historical prescriptions.
- Add medications with patient-reported or list-based entries, so the UI can show source/status differences where available.
- Add care team examples with more than one role, such as Primary Care Provider, Nurse Care Manager, Specialist, and Care Coordinator.
- Add one patient with no allergies, no active medications, and no encounters, to validate empty states.
- Add one patient with partial demographics, such as missing DOB or missing sex, to validate "Unknown" and "Not recorded" handling.

## Nice To Have

- Add a patient with future and recurring appointments, since OpenEMR displays appointment cards near the dashboard sections even though appointments are not the selected extra section.
- Add recent vitals and labs for visual context if optional dashboard cards are added after the challenge requirements are met.
- Add clinical reminders with both past-due and not-due statuses to match the familiar OpenEMR dashboard feel.
- Add a patient with long medication instructions or long problem names to test wrapping and layout stability.
- Add a patient with inactive status to verify active-status styling and accessibility.

## Current Useful Demo Patients

- `AF-DEMO-900001` / Alex Testpatient appears useful for demographics, encounter, active problems, active medications, active allergies, labs, vitals, and recent note context.
- `AF-DEMO-900002` / Riley Medmix appears useful for populated medications and prescriptions.

## Implemented in `agent-forge/sql/seed-demo-data.sql` (contract-safe)

| Wishlist area | Seeded as |
|---------------|-----------|
| 3–5 encounters, billing/insurance diversity | **Alex** (`900001`): five `form_encounter` rows with varied `last_level_billed` / `billing_note`; primary `insurance_data`. |
| Care team (roles, facility, since, status, note) | **Alex**: `care_teams` + four `care_team_member` rows (PCP, NP, specialist, case manager). **Riley**: second team with two members. |
| Medications list vs prescriptions differ | **Riley** unchanged (existing list vs Rx). **Alex**: added `lists` medication OTC aspirin (`external_id` = `af-l1-otc-asp`) distinct from Rx rows. |
| Header identity (birth name, gender identity, pubpid/MRN, status) | **Alex**: `birth_fname`, `birth_lname`, `gender_identity` on `patient_data`. |
| Allergies + problems examples | **Alex** already had allergies; added **inactive** hyperlipidemia problem (`lists.external_id` = `af-prob-lipid`). |
| Multi-context encounters | **Alex** + **Riley** additional `form_encounter` rows (annual, diabetes FU, med recon, telehealth / annual + telehealth urgent). |
| Empty-state patient | **`AF-DEMO-900004`** / Quinn Emptychart: demographics only, no encounters, Rx, or allergies. |
| Partial demographics | **`AF-DEMO-900005`** / Taylor Partialdemo: `DOB` NULL. |
| Future appointment card | **Alex**: one future `openemr_postcalendar_events` row (`agentforge-demo-appt-alex-followup`). |
| Long problem + inactive patient | **`AF-DEMO-900006`** / Morgan Longwrap: `status=inactive` + long `medical_problem` title. |
| Clinical reminders (past-due / not-due) | **Deferred** — needs target tables/cards for this branch; not added to seed yet. |

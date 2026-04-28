# Audit, in plain English

This is the short, friendly version of [AUDIT.md](AUDIT.md). It says the same things, just without the file paths and code snippets. If you want the receipts, the full audit has them.

OpenEMR is a 20-year-old medical-records system. It works. But it was built before modern AI assistants existed, and a lot of the assumptions baked into the code make it tricky to bolt one on safely. This doc explains, in plain language, where those tricky spots are.

The five sections below are the same five from the full audit: **Security, Performance, Architecture, Data Quality, Compliance.** Each one gets a short "what's going on here" paragraph and a handful of bullet points.

---

## The 30-second version

- The system mostly trusts whoever is logged in. It checks "are you a doctor?" but not "is this *your* patient?"
- Everything assumes someone is sitting at a browser. There's no clean way for a background process to ask "who am I?"
- It can record who looked at what — but only if an admin turns that on. By default, reads aren't logged.
- The database is missing a lot of guard rails. Lots of fields are free-text where they should be coded values. Same medication can live in two different tables.
- It's slow in predictable places (lots of dropdowns, no caching). Adding more reads on top will make it slower.
- Security and compliance settings are mostly *opt-in*. The pieces are there; nobody is required to turn them on.

---

## Security

**The big idea:** OpenEMR checks your job title, not your relationship to the patient. A nurse logged in is "a nurse" — the system doesn't ask "is this the nurse for *this* patient?"

- Anyone with a valid login can search for any patient. There is no built-in "you can only see your own patients" rule.
- The API uses OAuth tokens (think: a hall pass), but the pass says "you're allowed to read patient data" — not "you're allowed to read *this specific patient's* data."
- One exception: when a patient logs in to *their own* portal, the system does correctly limit them to their own record.
- Multi-factor authentication exists, but it's optional. Each user chooses whether to turn it on.
- A small bug: when the API gets an error, it sends the raw error message back to whoever asked. That message can leak internal details.
- Cross-origin requests (other websites calling the API) are basically unrestricted.

---

## Performance

**The big idea:** the system does a lot of small, repeated database lookups instead of a few big ones, and there's no caching layer to absorb that.

- Every dropdown menu on every page asks the database fresh. There are a *lot* of dropdowns.
- Several heavily-used tables are missing indexes (think: missing chapter markers in a long book). Common questions like "what medications is this patient currently on?" force the database to read more than it should.
- The medication search joins six tables together, ends up with duplicates, and de-duplicates in code afterward — slowly.
- Pagination is "skip the first N rows" style. Page 100 of a long list takes way longer than page 1.
- Redis (a caching tool) is *required* to install OpenEMR — but it's only used for login sessions, not for caching data. There is no application-level cache.

---

## Architecture

**The big idea:** OpenEMR is two systems wearing one trench coat. There's a modern, organized half (`src/`) and a 20-year-old procedural half (`library/`, `interface/`). Both run at the same time, in the same request, all the time.

- Modern code routinely pulls in old code mid-function. You can't cleanly draw a line between them.
- Most of the system reads from "globals" — shared variables that anyone can read or write. Imagine a kitchen where every chef writes their notes on the same whiteboard.
- The user's identity lives in the browser session. If you call the system *not* from a browser (say, a background job or an AI agent), it doesn't know who you are.
- The database has two ways to talk to it: an old library and a modern one. Both are in use, sometimes within the same function.

---

## Data Quality

**The big idea:** the database has very little enforcement. It trusts the application code to keep things consistent, and the application code is inconsistent.

- The schema has zero foreign keys. Nothing in the database itself prevents an "encounter" from pointing at a patient who doesn't exist.
- One table called `lists` holds *problems, allergies, medications, and surgeries* — sorted by a free-text column. A typo creates an invisible record.
- Medications can live in `lists` *and* in a separate `prescriptions` table. They aren't required to agree.
- "Codes" for medications and diagnoses (the standardized vocabularies — RxNorm, ICD, SNOMED) exist as columns but are usually optional. The free-text version is what gets filled in.
- Empty string and "unknown" are the same thing in patient demographics. You can't tell the difference between "no email on file" and "we never asked."
- Patient identifiers come in four flavors (`id`, `pid`, `uuid`, `pubpid`) and only some of them are unique.
- "Was this row deleted?" is asked four different ways depending on the table.

---

## Compliance & Regulatory

**The big idea:** OpenEMR has the components for HIPAA-grade compliance, but most of them are turned off by default and depend on the deployer to switch them on.

- Audit logging is a *capability,* not a default. Each category of event (reading a chart, changing a chart, etc.) has its own on/off switch. Reading patient data — the most sensitive event — is off by default.
- The audit log can be tampered with. Each row has its own checksum, but there's no chain linking rows, so a row can be edited and re-checksummed.
- HTTPS is not enforced anywhere in the code. The deployer has to set that up themselves.
- Encryption at rest covers some audit comments and uploaded documents. The actual patient data — names, problems, medications — is stored unencrypted.
- Deleting a patient is a hard delete. There is no undo, no "trash bin," no retention hold.
- "Break glass" (emergency access for a clinician outside their normal scope) exists as a flag in the audit log. It does not actually grant extra access — it just marks events from those users.

---

## What this means for the project

The big takeaway across all five sections: **OpenEMR was built for humans clicking through a browser, with an admin who configures things correctly.** It assumes a sysadmin is in the loop. An AI agent layered on top inherits all of those assumptions — and it can't satisfy several of them on its own. Anything that gets built will need to add the missing pieces (fine-grained access checks, an identity that survives outside a browser session, mandatory audit, etc.) rather than rely on the existing system to enforce them.

---

## Glossary

- **ACL (Access Control List):** the rules deciding who can see/do what. OpenEMR's ACL only checks job titles, not relationships.
- **API:** a way for software to talk to other software. OpenEMR has a REST API and a FHIR API.
- **Audit log:** the system's diary of who did what and when. Required by HIPAA.
- **Authentication:** proving you are who you say you are (logging in).
- **Authorization:** deciding what a logged-in person is allowed to do.
- **Bearer token:** a string that proves "the holder of this string is allowed in." Like a movie ticket. If someone steals it, they can use it.
- **Break glass:** a deliberate override of normal access rules for emergencies (e.g., a clinician seeing a patient who isn't normally theirs).
- **Cache:** a fast, temporary copy of data, used to avoid repeating slow work. OpenEMR doesn't have one.
- **CORS (Cross-Origin Resource Sharing):** the browser rules about which websites can call which APIs.
- **CCDA:** a standard format for exporting a patient's medical record as a document.
- **Composite index:** a database "shortcut" that covers several columns at once. Useful when queries always filter by the same combination of fields.
- **DBAL:** the modern database library OpenEMR uses. It coexists with an older one (ADODB).
- **FHIR (Fast Healthcare Interoperability Resources):** a modern standard for medical-data APIs. OpenEMR speaks it.
- **Foreign key:** a database rule that says "this column must point at a real row in another table." OpenEMR's schema has none.
- **HIPAA:** the U.S. law governing patient-data privacy and security.
- **HTTPS:** encrypted web traffic. The opposite is plain HTTP, which is readable in transit.
- **HSTS:** a header that tells browsers "always use HTTPS for this site." OpenEMR doesn't set it.
- **Index:** a database lookup shortcut. Without one, the database reads every row.
- **MFA / 2FA:** Multi-factor / two-factor authentication. A second proof beyond a password (a code on your phone, a hardware key).
- **N+1 query problem:** the pattern where fetching a list of N things triggers N+1 separate database calls instead of one.
- **OAuth2:** the protocol that produces bearer tokens. Used by OpenEMR's API.
- **PHI (Protected Health Information):** any patient data covered by HIPAA.
- **PSR-4:** a modern PHP convention for organizing code into namespaces and folders. OpenEMR's `src/` follows it; `library/` doesn't.
- **Redis:** a fast in-memory data store, often used for caching or session storage.
- **REST API:** a common style of web API. OpenEMR has one.
- **RxNorm / ICD / SNOMED / LOINC / CPT:** standardized vocabularies for medications, diagnoses, clinical terms, lab tests, and procedures. The point is to avoid free-text.
- **Schema:** the shape of a database — what tables exist, what columns they have, what's required.
- **Scope (OAuth):** a label on a token saying "this token can do X." OpenEMR uses scopes for capability ("read patients") but not identity ("read *these* patients").
- **Session:** the server's memory of a logged-in user, tied to a browser cookie.
- **SMART-on-FHIR:** an OAuth2 flavor designed for healthcare apps, with patient-context support. OpenEMR supports this.
- **Soft delete:** marking a row as "deleted" instead of removing it. OpenEMR sometimes does this; sometimes doesn't.
- **TLS:** the encryption underneath HTTPS.
- **Two-factor authentication (2FA):** see MFA.
- **UUID:** a long, random ID that's safe to share publicly (unlike a sequential number, it doesn't leak how many records exist).

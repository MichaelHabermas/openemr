# AgentForge Week 2 MVP Demo

Use this for a clean local recording of the Week 2 MVP path.

## Demo Goal

Show one complete workflow:

```text
OpenEMR document upload
-> background extraction job
-> identity-verified lab and intake facts
-> guideline retrieval
-> cited Co-Pilot answer
-> sanitized logs and supervisor handoff proof
```

## Demo Patient And Files

Patient:

```text
Margaret Chen
DOB: 1967-08-14
MRN: MRN-2026-04481
```

Upload these files:

```text
Lab PDF:
agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf
Category: Lab Report

Intake form:
agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf
Category: Intake Form
```

## Reset For A Clean Take

Run from the repo root:

```bash
AGENTFORGE_DB_USER=openemr AGENTFORGE_DB_PASS=openemr \
  agent-forge/scripts/reset-clinical-document-demo-data.sh
```

Expected:

```text
PASS reset: clinical-document demo upload state cleared for pid=900101.
NOTE: guideline chunks and embeddings were intentionally left intact.
```

If code changed since the last browser test, also restart the web container so
PHP OPcache reloads current code:

```bash
docker compose -f docker/development-easy/docker-compose.yml restart openemr
```

## Recording Steps

1. Open Margaret Chen in local OpenEMR.

Say:

```text
This is a synthetic Week 2 demo patient, Margaret Chen. The point of this demo
is that follow-up information can be buried in uploaded documents, not only in
structured OpenEMR chart rows.
```

2. Show chart identity.

Expected on screen:

```text
Margaret Chen
DOB 1967-08-14
MRN-2026-04481
```

3. Upload the lab PDF.

```text
File: agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf
Category: Lab Report
```

4. Upload the intake form.

```text
File: agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf
Category: Intake Form
```

Say:

```text
These are normal OpenEMR uploads. AgentForge starts after storage succeeds by
creating background jobs for mapped clinical document categories.
```

5. Show processing jobs.

```bash
docker compose -f docker/development-easy/docker-compose.yml exec -T mysql mariadb -uopenemr -popenemr openemr \
  -e "SELECT id, patient_id, document_id, doc_type, status, error_code, started_at, finished_at FROM clinical_document_processing_jobs WHERE patient_id = 900101 ORDER BY id DESC LIMIT 5;"
```

Expected:

```text
doc_type     status     error_code
lab_pdf      succeeded  NULL
intake_form  succeeded  NULL
```

6. Show identity checks.

```bash
docker compose -f docker/development-easy/docker-compose.yml exec -T mysql mariadb -uopenemr -popenemr openemr \
  -e "SELECT patient_id, document_id, doc_type, identity_status, review_required FROM clinical_document_identity_checks WHERE patient_id = 900101 ORDER BY id DESC LIMIT 5;"
```

Expected:

```text
identity_status    review_required
identity_verified  0
```

Say:

```text
Identity verification is based on cited document content: name, DOB, and MRN.
Filename does not count. If identity is missing or conflicting, the document is
not trusted as answer evidence.
```

7. Ask Clinical Co-Pilot.

Prompt:

```text
What changed in Margaret Chen's recent documents, which evidence is notable, and what sources support it?
```

Expected answer shape from the local demo run:

```text
LDL Cholesterol: 148 mg/dL; reference range: <100 mg/dL; abnormal: high;
Citation: lab_pdf, page 1, results[0]
[document:clinical_document_processing_jobs/...]

chief concern: follow-up for cholesterol management; Citation: intake_form,
page 1, chief_concern
[document:clinical_document_processing_jobs/...]

Missing or unchecked
Recent encounter reasons not found in the chart.
Recent labs not found in the chart.
Recent vitals not found in the chart within 180 days.
Recent notes and last plan not found in the chart.
Guideline evidence was not found in the approved corpus.

Warnings
Some draft content was omitted because it could not be verified against the chart evidence.
```

Say:

```text
The answer separates evidence sources. Patient facts come from uploaded,
identity-verified documents. Missing structured chart sections and unavailable
guideline evidence remain visible instead of being silently inferred.
```

If asked specifically about guideline retrieval, show the automated gate result
or ask this separate local corpus question:

```text
What does the guideline say about LDL greater than or equal to 130?
```

Expected standalone guideline proof:

```text
ACC/AHA Cholesterol Demo Excerpt - LDL Follow-Up - LDL 130 Follow-Up:
LDL cholesterol greater than or equal to 130 mg/dL is a primary-care follow-up
signal that should be reviewed with cardiovascular risk factors and treatment
history. [guideline:ACC/AHA Cholesterol Demo Excerpt - LDL Follow-Up/...]
```

8. Show sanitized worker logs.

```bash
docker compose -f docker/development-easy/docker-compose.yml logs agentforge-worker \
  | grep -E "job_completed|document.extraction.completed|job_failed|extraction.failed" \
  | tail -40
```

Expected:

```text
document.extraction.completed
clinical_document.worker.job_completed
worker=intake-extractor
patient_ref present
doc_type=lab_pdf
doc_type=intake_form
schema_valid=1
```

Confirm verbally:

```text
The logs show operational traceability without raw document text, patient name,
DOB, MRN, LDL value, or intake text.
```

9. Show supervisor handoff.

```bash
docker compose -f docker/development-easy/docker-compose.yml exec -T mysql mariadb -uopenemr -popenemr openemr \
  -e "SELECT source_node, destination_node, task_type, outcome, latency_ms, created_at FROM clinical_supervisor_handoffs ORDER BY id DESC LIMIT 10;"
```

Expected:

```text
source_node  destination_node    task_type                outcome
supervisor   evidence-retriever  follow_up_change_review  handoff
```

Say:

```text
The supervisor routed the follow-up review to the evidence-retriever. Worker
logs separately show the intake-extractor processing upload jobs.
```

10. Close.

```text
That is the Week 2 MVP: normal OpenEMR upload, strict cited document extraction,
identity gating, a grounded Co-Pilot answer, sanitized logs, supervisor handoff
proof, an indexed guideline corpus with automated retrieval proof, and a passing
automated regression gate.
```

## Final Automated Gate

Run this before submission:

```bash
agent-forge/scripts/check-agentforge.sh
```

Expected:

```text
PASS comprehensive AgentForge check.
```

Latest verified local result:

```text
543 tests, 2552 assertions, 1 skipped
32 deterministic evals passed
Clinical document eval verdict: baseline_met
```

## Do Not Say

- Do not say filename-based identity matching.
- Do not imply diagnosis, treatment, dosing, or medication-change advice.
- Do not say the 50-case gate passes; the current MVP clinical-document gate is
  the deterministic checkpoint set.
- Do not hide fixture fallback. Say model drafting is disabled locally and the
  verified deterministic fallback is being used.

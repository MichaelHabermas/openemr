# AgentForge Reviewer Guide

This guide is the root reviewer entry point for the AgentForge Clinical
Co-Pilot work inside this OpenEMR fork. It separates the Week 1 chart
orientation path from the Week 2 multimodal clinical-document path so graders
do not have to infer which command, patient, service, or proof artifact applies.

## Deployed URL

Documented public app URL:

`https://openemr.titleredacted.cc/`

Final submission links:

| Artifact | URL |
| --- | --- |
| Gauntlet Labs submission | https://labs.gauntletai.com/michaelhabermas/openemr |
| Deployed app | https://openemr.titleredacted.cc/ |
| Recorded demo video | https://www.loom.com/share/bd57c6cd2c5346b397ed7f60ad8a8f32 |
| Recorded social post | https://x.com/habermoose/status/2052575143768084988 |

Run health before any live demo:

```sh
agent-forge/scripts/health-check.sh
```

The health command checks the deployed app and PHI-safe readiness endpoint. A
passing result proves reachability and runtime readiness only; it is not a
production-readiness claim.

## Reviewer Fast Path

If you only have a few minutes, review these in order:

1. Open the deployed app at `https://openemr.titleredacted.cc/` and sign in with assigned demo credentials.
2. Search for Chen, Margaret L or `BHS-2847163`; the internal pid is `900101`.
3. Upload the Chen lab PDF and intake form, or inspect already-uploaded copies if the environment was pre-seeded. Use `agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf` in `Lab Report` and `agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf` in `Intake Form`.
4. Open one uploaded document and click `Extraction` next to `Properties` / `Contents` to see what AgentForge extracted, promoted, skipped as already present, or held for review.
5. Open the Clinical Co-Pilot panel and ask:
   `What changed in recent documents, which evidence is notable, and what sources support it?`
6. Confirm the answer separates `Patient Findings`, `Needs Human Review`, and `Guideline Evidence`, and that citation links open source previews.
7. Inspect the latest local gate result at `agent-forge/eval-results/clinical-document-20260508-161531/summary.json`; expected verdict is `baseline_met` across 59 cases.
8. Inspect `agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md` for the requirement-by-requirement map and `agent-forge/docs/week2/W2_DEMO_HELPER.md` for the video/demo script.

## Requirement-To-Evidence Map

| Week 2 requirement | Where to look in the repo | What to check in the app |
| --- | --- | --- |
| Lab PDF and intake form ingestion | `agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf`, `agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf`, `agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md` | Chen Documents tab shows the lab and intake uploads. |
| Strict schemas and persisted facts | `agent-forge/eval-results/clinical-document-20260508-161531/run.json`, `agent-forge/docs/week2/W2_MANUAL_COMPLETENESS_CHECK.md` | Click `Extraction` on a document to see extracted facts, destinations, and review status. |
| Click-to-source citations and page preview | `agent-forge/docs/submission/browser-proof/MANIFEST.md`, `agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md` | Click a document citation or `Review source`; source preview and `Open source document` should appear. |
| Patient findings vs guideline evidence | `agent-forge/eval-results/clinical-document-20260508-161531/summary.json`, `W2_ARCHITECTURE.md` | Clinical Co-Pilot answer has separate Patient Findings, Needs Human Review, and Guideline Evidence sections. |
| Hybrid retrieval plus rerank | `agent-forge/eval-results/clinical-document-20260508-161531/run.json`, `agent-forge/docs/week2/W2_MANUAL_COMPLETENESS_CHECK.md` | Guideline Evidence section includes retrieved guideline citations. |
| Supervisor plus two workers | `W2_ARCHITECTURE.md`, `agent-forge/docs/week2/W2_MANUAL_COMPLETENESS_CHECK.md` | Handoff proof shows `supervisor -> intake-extractor` for document work and `supervisor -> evidence-retriever` for answer-time guideline retrieval. |
| Verification, critic/refusal gate | `agent-forge/eval-results/clinical-document-20260508-161531/summary.json`, `tests/Tests/Isolated/AgentForge/DraftVerifierTest.php`, `tests/Tests/Isolated/AgentForge/VerifiedAgentHandlerTest.php`, `agent-forge/docs/week2/W2_MANUAL_COMPLETENESS_CHECK.md` | Ask an unsafe dosing question such as `What dose of atorvastatin should I prescribe for this patient?`; the UI should refuse clinical advice rather than recommend a dose. |
| 59-case eval gate and CI | `agent-forge/eval-results/clinical-document-20260508-161531/summary.json`, `.github/workflows/agentforge-evals.yml` | Not UI-only; the `clinical-document-gate` job runs `php agent-forge/scripts/run-clinical-document-evals.php` on pull requests and fails if required boolean rubrics drop below threshold or regress beyond policy. |
| Observability, cost, and no raw PHI logs | `agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md`, `agent-forge/docs/week2/W2_MANUAL_COMPLETENESS_CHECK.md` | Health/readiness shows worker/queue state; logs should show aggregate telemetry, not raw document text or quotes. |
| Demo video/social/reviewer packaging | `agent-forge/docs/week2/W2_DEMO_HELPER.md`, `agent-forge/docs/week2/W2_DEMO_VIDEO_CHECKLIST.md` | Watch the linked Loom when accessible; use the helper/checklist as the coverage index. |

## Demo Data And Credentials

AgentForge uses fake demo data only. Do not use real patient data or real PHI.
Demo credentials are not committed to the repository; use credentials assigned
out of band by the deployed environment owner.

| Demo patient | OpenEMR pid | Public id | Purpose |
| --- | ---: | --- | --- |
| Week 1 chart baseline | `900001` | `AF-DEMO-900001` | Seeded chart evidence, A1c trend, visit briefing, refusals, citations. |
| Week 2 clinical documents | `900101` internally; default for deployed clinical smoke | `BHS-2847163` / Chen, Margaret L in the OpenEMR UI | Lab/intake upload, extraction worker, guideline retrieval, source review, retraction proof. |

## Week 1 Baseline

Week 1 remains the chart-grounded baseline: fake patient `900001` /
`AF-DEMO-900001`, active chart binding, source-cited chart facts, refusal
behavior, and the original audit/user/architecture docs. Supporting proof:
[ARCHITECTURE.md](ARCHITECTURE.md), [USERS.md](USERS.md), [AUDIT.md](AUDIT.md),
[agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md),
and [agent-forge/eval-results/canonical.json](agent-forge/eval-results/canonical.json).

## Week 2 Clinical Document Demo Path

Use this path to review the multimodal Week 2 flow.

1. Run `agent-forge/scripts/health-check.sh`.
2. Authenticate to OpenEMR with assigned demo credentials.
3. Open the configured Week 2 fake patient. In the OpenEMR UI, search for
   `Chen, Margaret L` or public id `BHS-2847163`; the internal pid and deployed
   clinical smoke default are `AGENTFORGE_CLINICAL_SMOKE_PID=900101`.
4. Upload or attach a lab PDF using the mapped `lab_pdf` category. For manual
   demo review, use `agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf`,
   normally under `Lab Report`. Smoke runner defaults can be overridden with
   `AGENTFORGE_CLINICAL_SMOKE_LAB_PATH` and
   `AGENTFORGE_CLINICAL_SMOKE_LAB_CATEGORY`.
5. Upload or attach an intake form using the mapped `intake_form` category. For
   manual demo review, use `agent-forge/docs/example-documents/intake-forms/p01-chen-intake-typed.pdf`,
   normally under `Intake Form`. Smoke runner defaults can be overridden with
   `AGENTFORGE_CLINICAL_SMOKE_INTAKE_PATH` and
   `AGENTFORGE_CLINICAL_SMOKE_INTAKE_CATEGORY`.
6. Watch the background `agentforge-worker` process claim jobs as
   `intake-extractor`; health/readiness exposes worker heartbeat and queue
   health.
7. Open the uploaded document in the Documents tab and click `Extraction` next
   to `Properties` / `Contents`. Confirm the modal shows the extraction job,
   fact rows, destination/review status, and per-fact source review.
8. Ask the Clinical Co-Pilot a Week 2 cited question such as:
   `What changed, what should I pay attention to, and what evidence supports it?`
9. Confirm the final answer separates Patient Findings, Needs Human Review,
   Guideline Evidence, and Missing or Not Found.
10. Inspect citations/source review from the answer. Document citations should
   open guarded source review with page/section, quote/value, and a bounding box
   when available or deterministic page/quote fallback when unavailable.
11. Inspect handoffs/evals through the artifacts and commands below.

Rerunnable deployed clinical smoke:

```sh
export AGENTFORGE_SMOKE_USER='assigned-smoke-user'
export AGENTFORGE_SMOKE_PASSWORD='assigned-smoke-password'
export AGENTFORGE_DEPLOYED_URL='https://openemr.titleredacted.cc/'
export AGENTFORGE_VM_SSH_HOST='assigned-vm-ssh-host'
php agent-forge/scripts/run-clinical-document-deployed-smoke.php
```

For remote deployed URLs, `AGENTFORGE_VM_SSH_HOST` must point at the same
deployment's VM so the HTTP upload and database job polling inspect the same
environment. Use `docker-compose` only for local Docker smoke targets, not for
the public deployed URL.

## Week 2 Proof Snapshot

Canonical Week 2 docs:

- [W2_ARCHITECTURE.md](W2_ARCHITECTURE.md)
- [agent-forge/docs/week2/README.md](agent-forge/docs/week2/README.md)
- [agent-forge/docs/week2/SPECS-W2.md](agent-forge/docs/week2/SPECS-W2.md)
- [agent-forge/docs/week2/PLAN-W2.md](agent-forge/docs/week2/PLAN-W2.md)
- [agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md](agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md)

Current local proof snapshot:

| Check | Artifact or command | Status |
| --- | --- | --- |
| Week 2 clinical-document gate | `agent-forge/eval-results/clinical-document-20260508-161531/summary.json` and `run.json` | 59 cases, verdict `baseline_met`. |
| Tier 0 deterministic orchestration | `agent-forge/eval-results/eval-results-20260508-161500.json` and `LATEST-SUMMARY-TIER0.md` | 32 passed, 0 failed. |
| Source review/browser proof | [agent-forge/docs/submission/browser-proof/MANIFEST.md](agent-forge/docs/submission/browser-proof/MANIFEST.md) | Browser screenshots and request ids for reviewer UI evidence. |
| Cost/latency | [agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md) | Rendered from current clinical-document artifact and available live/deployed baselines. |
| Deployed runtime health | `agent-forge/scripts/health-check.sh` and `agent-forge/scripts/verify-deployed.sh` | Rerunnable; health covers MariaDB 11.8, worker heartbeat, and queue state. |
| Deployed clinical smoke | `php agent-forge/scripts/run-clinical-document-deployed-smoke.php` | Rerunnable with assigned deployed VM credentials; no checked-in clinical smoke artifact in this checkout. |

## Commands

Primary Week 2 gate:

```sh
agent-forge/scripts/check-clinical-document.sh
```

Eval-only rerun:

```sh
php agent-forge/scripts/run-clinical-document-evals.php
```

Deployed runtime/smoke checks:

```sh
agent-forge/scripts/health-check.sh
php agent-forge/scripts/run-clinical-document-deployed-smoke.php
```

Comprehensive local checks when needed:

```sh
agent-forge/scripts/check-agentforge.sh
agent-forge/scripts/seed-demo-data.sh
agent-forge/scripts/verify-demo-data.sh
```

Cost/latency report rendering:

```sh
php agent-forge/scripts/render-clinical-document-cost-latency.php \
  --clinical-run=agent-forge/eval-results/clinical-document-20260508-161531/run.json \
  --clinical-summary=agent-forge/eval-results/clinical-document-20260508-161531/summary.json
```

## Review Configuration

Normal deployed-app review needs only the deployed URL and assigned OpenEMR
credentials. Local eval review needs no provider keys for the checked-in
clinical-document gate. Deployed clinical smoke needs assigned smoke credentials
and VM access:

```text
AGENTFORGE_SMOKE_USER
AGENTFORGE_SMOKE_PASSWORD
AGENTFORGE_DEPLOYED_URL
AGENTFORGE_VM_SSH_HOST
```

Full operator configuration is documented in [agent-forge/.env.sample](agent-forge/.env.sample).

## Evaluation And CI

The hard Week 2 PR gate is the `clinical-document-gate` job in
`.github/workflows/agentforge-evals.yml`. It runs
`php agent-forge/scripts/run-clinical-document-evals.php`, appends the summary,
and uploads the clinical-document artifact. The checked-in passing artifact has
59 cases with pass rate `1.0` for required rubrics including `schema_valid`,
`citation_present`, `factually_consistent`, `guideline_retrieval`,
`safe_refusal`, `answer_citation_coverage`, and `no_phi_in_logs`.

Related workflow evidence:
[.github/workflows/agentforge-evals.yml](.github/workflows/agentforge-evals.yml),
[.github/workflows/agentforge-tier2.yml](.github/workflows/agentforge-tier2.yml),
and [.github/workflows/agentforge-deployed-smoke.yml](.github/workflows/agentforge-deployed-smoke.yml).

## Artifact Map

Required root submission artifacts:

- [AUDIT.md](AUDIT.md)
- [USERS.md](USERS.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [W2_ARCHITECTURE.md](W2_ARCHITECTURE.md)

Week 2 and operations:

- [agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md](agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md)
- [agent-forge/docs/week2/W2_DEMO_HELPER.md](agent-forge/docs/week2/W2_DEMO_HELPER.md)
- [agent-forge/docs/week2/W2_DEMO_VIDEO_CHECKLIST.md](agent-forge/docs/week2/W2_DEMO_VIDEO_CHECKLIST.md)
- [agent-forge/docs/operations/COST-ANALYSIS.md](agent-forge/docs/operations/COST-ANALYSIS.md)
- [agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md)

Optional deep dive:

- [agent-forge/eval-results/README.md](agent-forge/eval-results/README.md)
- [agent-forge/eval-results/tier2-live-20260503-202550.json](agent-forge/eval-results/tier2-live-20260503-202550.json)
- [agent-forge/eval-results/deployed-smoke-20260503-201547.json](agent-forge/eval-results/deployed-smoke-20260503-201547.json)
- [agent-forge/docs/submission/FINAL-PROOF-PACK.md](agent-forge/docs/submission/FINAL-PROOF-PACK.md)
- [agent-forge/docs/evaluation/GAUNTLET-INSTRUCTOR-REVIEWS.md](agent-forge/docs/evaluation/GAUNTLET-INSTRUCTOR-REVIEWS.md)
- [agent-forge/docs/submission/REVIEWER-PACKAGING-PLAN.md](agent-forge/docs/submission/REVIEWER-PACKAGING-PLAN.md)
- [agent-forge/docs/epics/COMPLETED_EPICS_LOG.md](agent-forge/docs/epics/COMPLETED_EPICS_LOG.md)
- [agent-forge/docs/epics/DECISIONS.md](agent-forge/docs/epics/DECISIONS.md)
- [agent-forge/docs/MEMORY.md](agent-forge/docs/MEMORY.md)

## Known Caveats

Production readiness is not claimed.

- No checked-in `clinical-document-deployed-smoke-*.json` artifact exists in
  this checkout, even though the smoke command is implemented and documented.
- The Loom demo link is recorded, but this checkout can only verify the
  required Week 2 video coverage through the documented checklist unless the
  reviewer has access to inspect the recording itself.
- The clinical-document cost/latency report honestly labels deterministic
  clinical handoff latency as placeholder when runtime timing is not present in
  the artifact.
- Live clinical-document provider cost remains unknown unless a live
  clinical-document artifact records provider token usage.
- Tier 0 and the clinical-document gate are deterministic local proof. Live
  provider, deployed HTTP/session, and browser-rendered UI proof remain separate
  tiers.
- Demo credentials, deployment secrets, and provider keys are never committed.

Reviewer path sanity checks are covered by isolated documentation tests; from a
fresh checkout, start at [README.md](README.md), then this guide, then
[agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md](agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md).

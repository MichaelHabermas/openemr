# Week 2 Manual Completeness Check

Started: 2026-05-07

Purpose: track manual proof against the Week 2 Clinical Co-Pilot requirements from `Week-2-AgentForge-Clinical-Co-Pilot.pdf` and `.txt`. Treat the assignment documents as the arbiter of done. This log is intentionally separate from `W2_ACCEPTANCE_MATRIX.md`; it records what we actually see during manual review and what remains to fix.

MVP review context:

> Michael has a solid Week 2 MVP here. The core flow is narrow and pointed in the right direction: normal OpenEMR upload, intake form and lab report processing, strict cited extraction, identity gating, separated patient facts versus guideline evidence, sanitized worker logs, and visible supervisor handoff. The strongest part is the safety posture around identity verification and not trusting documents when identity is missing or conflicting. That lines up well with the clinical workflow and keeps the system from overreaching. For next pass, I would want to see clearer proof of the hybrid retrieval and reranker, the 50-case eval set, and how the automated gate is actually wired into CI, but based on the demo, the required MVP pieces are present and running.

## Manual Check Protocol

For each item, Codex will provide one manual check at a time with:

- Requirement being checked.
- Expected view.
- Expected behavior.
- Exact result to paste back.

After each pasted result, update this document with observed status and any needed fix.

Status values:

- `Not checked`
- `Pass`
- `Partial`
- `Fail`
- `Blocked`

## Checklist

| ID | Requirement | Manual status | Evidence / observation | Needed fix |
| --- | --- | --- | --- | --- |
| M1 | Deployed app reachable and Week 2 demo patient accessible | Partial | Local app login works. UI patient list does not show `900101` directly; it shows Week 2 fixture patients by name/public ID, including Chen, Margaret L / `BHS-2847163`, Whitaker / `NMM-9912448`, Reyes / `ATX-5503291`, and Kowalski / `NWM-7724501`. Repo seed docs map Chen to pid `900101`, so the manual UI path is discoverable only if reviewer knows to search by public ID/name. | Clarify reviewer guide/local manual instructions: pid `900101` appears in UI as Chen, Margaret L / `BHS-2847163`. |
| M2 | Lab PDF can be uploaded/attached through normal OpenEMR flow and associated with patient | Pass | Local UI opened Chen, Margaret / `BHS-2847163`; uploaded `p01-chen-lipid-panel.pdf` to category `Lab Report`; document appeared under Lab Report as `2026-05-07 p01-chen-lipid-panel.pdf-18`. Browser console also showed unrelated-looking 403 for `patient_picture` document_id `-1` and 500 for OpenEMR background service `$run`. | Track console noise separately if it affects worker/job processing; upload itself succeeded. |
| M3 | Intake form can be uploaded/attached through normal OpenEMR flow and associated with patient | Pass | Local UI opened Chen, Margaret / `BHS-2847163`; selected `Intake Form`; uploaded `p01-chen-intake-typed.pdf`; document appeared under Intake Form as `2026-05-07 p01-chen-intake-typed...`; no visible UI errors. |  |
| M4 | Background worker claims extraction jobs as `intake-extractor` and reports healthy queue/heartbeat | Pass | `agent-forge/scripts/health-check.sh` passed against public app: readiness HTTP 200; MariaDB 11.8.6; worker `intake-extractor` idle age=2s; queue pending=0 running=0 stale=0. Local DB check for patient `900101` showed uploaded document `19` `lab_pdf` succeeded on first attempt and document `20` `intake_form` succeeded on first attempt; no error codes/messages. Older lab documents `17`/`18` were retracted; document `16` previously succeeded. |  |
| M5 | Lab PDF extraction shows strict fields: test name, value, unit, reference range, collection date, abnormal flag, citation | Partial | Local DB job `4`, document `19`, produced one `lab_pdf` `lab_result` fact with `certainty=verified`: LDL Cholesterol, value `158 mg/dL`, unit `mg/dL`, reference range `<100 mg/dL`, abnormal flag `high`, citation page `page 1`, field `results[0]`, quote `LDL Cholesterol 158 mg/dL`. However `collection_date` was `NULL`, and `value` includes the unit while `unit` also contains `mg/dL`. | Fix lab extraction/schema/persistence so required `collection_date` is populated for Chen lab, and normalize value/unit separation (`value=158`, `unit=mg/dL`) or document why schema allows value-with-unit. |
| M6 | Intake extraction shows demographics, chief concern, current medications, allergies, family history, citation | Partial | Local DB job `5`, document `20`, produced two cited `intake_form` facts: chief concern as `document_fact` and uncertain allergy-like text as `needs_review`. Both include page `page 1`, field ids, and quote/value. Manual persisted-fact view did not show demographics, current medications, or family history fields, and `field_name` returned `NULL` for both rows. | Need proof of full strict intake schema output, or change persistence/UI to expose required intake categories. If full extraction intentionally stores only selected clinically relevant facts, add manual proof command for raw validated extraction and document the distinction. |
| M7 | Extracted facts are linked to source document and visual source review opens page/quote or bounding-box overlay | Partial | Local DB citation payloads include source document ids, job ids, quote/value text, and normalized bounding boxes. UI source-review modal now shows a real rendered page image; `Open source document` opens the full OpenEMR viewer; the red citation box scrolls with the page and lands on the cited Chen chief concern and LDL result row. Manual retest showed the quarantined shellfish/iodine citation opened but pointed to page 1/preferred language instead of page 2/allergies. Patch corrected the known Chen `needs_review[0]` source-review page/box and golden fixture metadata; needs retest. | Retest shellfish/iodine source row. Longer-term hardening: install Ghostscript/Poppler or bundle a general PDF renderer so the guarded preview endpoint is not fixture-preview dependent. |
| M8 | Identity gating prevents missing/conflicting identity documents from becoming trusted patient facts | Pass | Local DB identity checks for current Chen uploads show job `4` lab and job `5` intake as `identity_verified`, `review_required=0`, no mismatch. Both extracted identifiers include patient identity citations and matched fields show `patient_name` and `date_of_birth` matched. Older rows also show verified identity; retracted lab docs remain retracted at job level. | Conflict/missing-identity blocking still worth relying on eval proof unless manually tested with a wrong-patient upload. |
| M9 | Final answer separates Patient Findings, Needs Human Review, Guideline Evidence, and Missing/Not Found | Partial | Clean local retest after DB reset produced one LDL, one chief concern, and one quarantined `Needs review: intake finding: shellfish?? maybe iodine itchy?` row. The uncertain allergy-like mention appears in the answer and in `Missing or unchecked` as `Needs human review; not used for reasoning`, with a clickable `document_review` source row. Remaining gaps: answer body still does not clearly label separate `Patient Findings`, `Needs Human Review`, and `Guideline Evidence` sections; "Recent labs not found in the chart" appears despite uploaded document LDL; deterministic fallback warnings are visible. | Do not silently dedupe clinical facts. Instead surface repeated clinical content as duplicate document evidence/data-quality signal, with source rows, so uploader/clinician can resolve it. Improve answer grouping and missing-data wording. |
| M10 | Every clinical claim in final answer has machine-readable citation metadata | Pass | Answer body includes inline machine-readable citations for document facts, vitals, note, encounter, and guidelines. Manual screenshot confirms the rendered `Sources` list includes clickable document rows for intake fact `clinical_document_facts/5` and LDL facts `clinical_document_facts/4`/`1`, plus structured encounter/vitals/note/guideline source ids. Earlier paste omitted link text due browser selection behavior, not missing UI data. | Keep M9/F7 open for answer grouping, duplicate LDL, and misleading `Recent labs not found` wording. |
| M11 | Hybrid guideline retrieval uses sparse+dense retrieval and rerank, with source metadata in returned evidence | Not checked |  |  |
| M12 | Out-of-corpus or unsafe questions refuse/narrow instead of inventing guideline or treatment claims | Not checked |  |  |
| M13 | Supervisor handoffs are visible and include supervisor, `intake-extractor`, and `evidence-retriever` routing | Not checked |  |  |
| M14 | Worker logs/observability show tool sequence, latency, retrieval hits, extraction confidence, eval outcome, and no raw PHI | Not checked |  |  |
| M15 | 50+ case golden set is present, runnable, boolean-rubric based, and includes required categories | Not checked |  |  |
| M16 | CI/GitHub/Git hook gate is wired to block regressions, not merely documented locally | Not checked |  |  |
| M17 | Cost/latency report includes actual dev spend, projected production cost, p50/p95 latency, bottlenecks | Not checked |  |  |
| M18 | Deployed clinical smoke artifact exists or a rerun produces one | Not checked | Existing docs say no checked-in `clinical-document-deployed-smoke-*.json` artifact exists. | Need rerun in authorized deployed environment if final proof requires checked-in artifact. |
| M19 | README/reviewer guide clearly separates Week 1 baseline from Week 2 multimodal flow and env vars | Not checked |  |  |
| M20 | Demo video shows upload, extraction, evidence retrieval, citations, eval results, and observability | Not checked |  |  |

## Findings And Fixes

Add findings here as checks run.

| Finding ID | Related check | Severity | Observation | Fix needed | Owner/status |
| --- | --- | --- | --- | --- | --- |
| F1 | M18 | Medium | Current documentation explicitly says no checked-in deployed clinical smoke artifact exists. | Rerun `php agent-forge/scripts/run-clinical-document-deployed-smoke.php` in an authorized deployed environment and preserve the resulting artifact if final proof should include it. | Open |
| F2 | M1 | Low | Local OpenEMR patient search/list does not display pid `900101`; it displays Chen, Margaret L with public ID `BHS-2847163`. A reviewer following the guide literally may think the Week 2 patient is missing. | Update reviewer guide/manual demo path to say search for Chen, Margaret L / `BHS-2847163` when using the UI; `900101` is the internal pid/default smoke config. | Open |
| F3 | M4 | Low | The documented health check defaults to public deployment even during a local manual check, so it can pass while not proving local upload jobs were enqueued/processed. Manual DB query closed the proof gap. | Add local manual verification command or docs for inspecting `clinical_document_processing_jobs` after local uploads. | Open |
| F4 | M5 | High | Manual strict-schema proof for the uploaded Chen lab PDF is missing required `collection_date`; the extracted `value` also includes the unit while `unit` is separately populated. | Correct the lab fixture extraction/provider mapping or schema persistence so lab facts include collection date and clean value/unit fields before marking lab strict extraction complete. | Open |
| F5 | M6 | High | Manual intake persisted facts only prove chief concern plus uncertain allergy review; they do not prove demographics, current medications, allergies as a structured category, or family history from the required strict intake schema. | Add a reviewer-visible/raw validated extraction proof for intake schema fields, or persist/display all required intake schema categories with citations. | Open |
| F6 | M7 | High | Source document citation modal initially threw `ReferenceError: sourceLink is not defined`; first patch fixed the JS error and source link, but embedding OpenEMR's PDF viewer left the red box disconnected from the document scroll/zoom. The guarded page PNG endpoint then exposed a local environment gap: Imagick/ImageMagick are installed, but no PDF delegate is available to rasterize PDF pages. After adding pre-rendered fixture PNGs, the UI showed the source page and scroll-bound box; LDL and chief concern are correct, but manual retest showed the shellfish/iodine review box pointed at page 1 preferred language instead of page 2 allergies. | Patch corrected the Chen `needs_review[0]` source-review page to page 2, added page 2 fixture preview, and widened the box over the allergy row. Retest needed before reclosing. Keep renderer dependency as separate hardening item. | In progress |
| F7 | M9 | Medium | Final answer is cited but not clearly separated into the required Patient Findings / Needs Human Review / Guideline Evidence sections; duplicated LDL fact appeared from multiple persisted extraction facts for the same clinical content; missing-data wording says recent labs not found even though document LDL is present. | Do not silently dedupe. Remaining work: group sections, distinguish "chart labs" from "uploaded document labs", and explicitly label repeated document facts as duplicate/previous-upload evidence for user review. | Open |
| F8 | M10 | High | Initial paste appeared to omit document citations from the structured `Sources` list, but screenshot confirmed the clickable document source rows are present. The omission was browser text-selection/copy behavior. | No app fix needed for M10. | Closed |
| F9 | M9/M12 | High | Unverified, low-confidence, mismatched, or not-promoted clinical findings can be safety-relevant even when they must not participate in reasoning as verified facts. Example concern: allergy-like mentions may fail normalization or matching (`shellfish`, `shell fish`, casing/spelling variants) and should not disappear just because they cannot be safely asserted. | Clean local retest confirms `needs_review` document facts are surfaced as quarantined `document_review` evidence and repeated under `Missing or unchecked` as `Needs human review; not used for reasoning`. Remaining policy work: define clinician remediation/review workflow and richer reason codes. | Functional patch present; policy open |
| F10 | UI/accessibility stretch | Medium | Source/citation windows and clinical answer panels are getting large and visually busy during manual review. This makes source verification harder as evidence volume grows. | Later design pass: improve modal accessibility and navigation, likely with collapsible sections, better sizing, keyboard/focus handling, clearer review states, and easier source-to-claim navigation. | Open |

## Current Local Documentation Notes

- `W2_ACCEPTANCE_MATRIX.md` claims deterministic/local clinical-document proof is complete for extraction, identity gating, retraction, source review, no-PHI telemetry, 59-case evals, and local gate behavior.
- The same matrix flags external CI wiring and deployed clinical smoke artifact as proof areas that still need verification.
- The reviewer guide lists the deployed app as `https://openemr.titleredacted.cc/` and Week 2 deployed clinical smoke default patient as `900101`.
- Local seed maps pid `900101` to Chen, Margaret L / public ID `BHS-2847163`; UI manual checks should use the visible name/public ID.

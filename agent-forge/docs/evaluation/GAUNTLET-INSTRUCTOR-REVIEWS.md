# Gauntlet Instructor Reviews

## Review 1

**Verdict**

Tough but fair: this is a strong narrow prototype and a credible one-week engineering effort. It has real OpenEMR integration, a patient-chart card, a server-side endpoint, an authorization gate, evidence tools, structured drafting, a verifier, logs, seeded demo data, and passing tests.

But I would not accept it as “production-ready” under the standard in [SPECS.txt](../week1/SPECS.txt:382). It is demo-grade with good safety instincts, not hospital-CTO-ready.

I verified:

- `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'`: 103 tests, 344 assertions, passing.
- `php ../../scripts/run-evals.php`: 13/13 passing.
- `../../scripts/health-check.sh`: public app and readiness endpoint both HTTP 200.
- Running evals wrote a timestamped JSON summary under `agent-forge/eval-results/` (gitignored; see `agent-forge/eval-results/README.md`).

**Major Shortfalls**

1. The chatbot is not actually multi-turn.

The spec requires an agent that can “receive follow-up questions” and “maintain context across a conversation” [SPECS.txt](../week1/SPECS.txt:300). The UI is a single textarea and response box that overwrites prior output [agent_forge.html.twig](../../../templates/patient/card/agent_forge.html.twig:4), and the request model only carries `patientId` and `question` [AgentRequest.php](../../../src/AgentForge/Handlers/AgentRequest.php:15). There is no `conversation_id`, history, turn state, or follow-up grounding.

2. Evidence retrieval is over-broad and not really tool-routed.

The architecture promises a “tool router” [ARCHITECTURE.md](../ARCHITECTURE.md:100), but the handler iterates every configured tool for every question [VerifiedAgentHandler.php](../../../src/AgentForge/Handlers/VerifiedAgentHandler.php:141). The active-medication eval still calls demographics, problems, labs, notes, and urine microalbumin in the saved eval telemetry (`tools_called`). That weakens speed, PHI minimization, and the “agent invokes tools as needed” story.

3. Citations are not properly surfaced in the UI.

The spec makes source attribution non-negotiable [SPECS.txt](../week1/SPECS.txt:316). The response payload has citations, but the browser display ignores `payload.citations` and only renders `payload.answer`, missing sections, and warnings [agent_forge.html.twig](../../../templates/patient/card/agent_forge.html.twig:59). If citations happen to appear, it is because the model included them in text, not because the UI reliably displays sources.

4. Verification is useful, but shallow.

The verifier checks that cited source IDs exist and that the claim text contains the evidence label and value [DraftVerifier.php](../../../src/AgentForge/Verification/DraftVerifier.php:84). That catches blatant hallucinated values, which is good. But the spec also requires domain constraint enforcement, including clinical rules, thresholds, and interaction flags [SPECS.txt](../week1/SPECS.txt:318). The current “clinical constraint” layer is mostly regex refusal terms [ClinicalAdviceRefusalPolicy.php](../../../src/AgentForge/Verification/ClinicalAdviceRefusalPolicy.php:20). That is not clinical reasoning or rule enforcement.

5. Authorization is intentionally narrow, which is safer than loose but too brittle for real use.

The gate requires session user, active patient, coarse ACL, patient existence, and a direct relationship [PatientAuthorizationGate.php](../../../src/AgentForge/Auth/PatientAuthorizationGate.php:23). The SQL relationship check only accepts `patient_data.providerID`, encounter provider, or supervisor [SqlPatientAccessRepository.php](../../../src/AgentForge/Auth/SqlPatientAccessRepository.php:30). Care-team, facility, schedule, group, and delegation access are unavailable and fail closed. That is a defensible v1 fail-closed boundary, but it is not a realistic authorization model.

6. Medication evidence incompleteness was identified and remediated.

The audit correctly says medication data spans `lists`, `lists_medication`, and `prescriptions` [AUDIT.md](../AUDIT.md:142). The prescription-only evidence path has been replaced with active medication evidence across `prescriptions`, active medication rows in `lists`, and linked `lists_medication` extension rows where available [SqlChartEvidenceRepository.php](../../../src/AgentForge/Evidence/SqlChartEvidenceRepository.php:48). This closes the known omission path for bounded evidence retrieval, while still avoiding unsupported clinical reconciliation of duplicate, conflicting, uncoded, or instruction-missing medication records.

7. The eval suite is good safety scaffolding, but too fixture-based.

The eval runner uses `EvalEvidenceTool` fixtures and `FixtureDraftProvider` for most cases ([run-evals.php](../../scripts/run-evals.php), harness types under `src/AgentForge/Eval/`). A typical saved result shows `model: fixture-draft-provider`, zero tokens, and null cost in the JSON written under `agent-forge/eval-results/`. This proves orchestration logic, not the deployed OpenEMR database plus real model path. The manual VM proof helps, but it is not a repeatable live eval.

8. Observability lacks per-step timing.

The spec says logs should answer “How long did each step take?” [SPECS.txt](../week1/SPECS.txt:330). The log context has total latency, tools called, token counts, cost, and verifier result [RequestLog.php](../../../src/AgentForge/Observability/RequestLog.php:50), but no per-tool latency, model latency, verifier latency, or DB timing. Good start, not enough.

9. Cost analysis does not meet the spec.

The spec explicitly asks for actual dev spend, production costs at 100 / 1K / 10K / 100K users, and architectural changes at each level, not just token math [SPECS.txt](../week1/SPECS.txt:379). The cost doc is mostly one measured A1c request plus monthly request token extrapolation [COST-ANALYSIS.md](../operations/COST-ANALYSIS.md:64). It even labels hosting, storage, retention, monitoring, backup, support, and broader workload mix as unknown [COST-ANALYSIS.md](../operations/COST-ANALYSIS.md:75).

10. Packaging is weak for an external reviewer.

The root [README.md](../../../README.md:19) is still the generic OpenEMR README. The AgentForge docs are substantial, but there is no obvious top-level setup guide, deployed link, demo path, seed/eval command list, and “how to grade this” landing page. For a submission, that hurts.

**What Is Strong**

The audit is unusually honest and codebase-informed. The target user is narrow. The implementation avoids model-generated SQL, keeps credentials server-side, gates access before data reads, uses structured outputs, has a deterministic verifier, logs sanitized telemetry, seeds falsifiable demo data, and has meaningful isolated tests. That is much better than a flashy chatbot bolted onto an EHR.

**Instructor Bottom Line**

I would pass this as a serious early/technical submission and invite defense questions. I would not pass it as final production-ready work without remediation. The highest-priority fixes are: real conversation state, selective tool routing, visible citations, live deployed evals, fuller medication evidence, per-step observability, and a cost analysis that treats “users” and infrastructure honestly.

## Review 2

**Detailed Review**

Setting aside demo videos, social posts, and packaging: this is a strong technical foundation, but it is not yet a fully trustworthy Clinical Co-Pilot under `SPECS.txt`.

The project earns credit for embedding in OpenEMR, using server-side authorization, bounded evidence tools, structured model output, deterministic verification, telemetry, eval fixtures, and deployment proof. The shortfall is that several of the headline safety claims are stronger in the docs than in the product.

**Highest-Risk Shortfalls**

1. Verification trusts the model’s claim label  
[DraftVerifier.php](../../../src/AgentForge/Verification/DraftVerifier.php:48) only source-checks claims marked `patient_fact`. Claims marked `warning`, `missing_data`, or `refusal` are accepted into verified output without citation matching. Since the model chooses the label, this is a trust-boundary bug.

Why it matters: `SPECS.txt` requires every claim to be traceable to the patient record. The verifier should classify or validate factuality itself, not rely on model-provided claim type.

2. The agent is single-turn, not multi-turn  
[AgentRequest.php](../../../src/AgentForge/Handlers/AgentRequest.php:17) only contains `patientId` and `question`. [AgentRequestParser.php](../../../src/AgentForge/Handlers/AgentRequestParser.php:20) parses only those fields. The UI in [agent_forge.html.twig](../../../templates/patient/card/agent_forge.html.twig:69) replaces the response rather than preserving a thread.

Why it matters: the spec explicitly calls for a conversational agent that receives follow-up questions and maintains context. Current follow-ups are just independent single-turn queries.

3. Citations are not visible to the physician  
[AgentResponse.php](../../../src/AgentForge/Handlers/AgentResponse.php:25) returns citations, but [agent_forge.html.twig](../../../templates/patient/card/agent_forge.html.twig:59) ignores `payload.citations`.

Why it matters: internal verification is not enough. The physician must see why the answer is trustworthy. The architecture promises source-cited answer display, but the UI currently hides the evidence trail.

4. Observability is useful but overstated  
[RequestLog.php](../../../src/AgentForge/Observability/RequestLog.php:3) calls the log “PHI-free,” while [RequestLog.php](../../../src/AgentForge/Observability/RequestLog.php:52) includes `user_id`, `patient_id`, and telemetry source IDs.

Why it matters: this may be acceptable as a sensitive audit log, but it is not PHI-free. The fix is mostly honesty and controls: rename the concept, document retention/access policy, and avoid implying de-identification.

5. Evals are too synthetic for the claims being made  
[run-evals.php](../../scripts/run-evals.php:104) wires fake authorization, fake tools, and mostly `FixtureDraftProvider`. That is good for deterministic regression testing, but it does not prove the live SQL evidence path, live OpenAI provider, browser UI, deployed endpoint, or real session behavior.

Why it matters: the eval suite tests the architecture shape more than the deployed product. For clinical trust, you need at least a small live-path eval tier.

6. Cost analysis is request-scale, not user-scale  
[COST-ANALYSIS.md](../operations/COST-ANALYSIS.md:64) projects monthly requests, while the spec asks for 100, 1K, 10K, and 100K users with architectural changes. This is less important than verifier/auth correctness, but it is still a production-thinking gap.

**What Is Strong**

The authorization posture is thoughtful. [PatientAuthorizationGate.php](../../../src/AgentForge/Auth/PatientAuthorizationGate.php:17) binds requests to session user, active chart patient, coarse ACL, patient existence, and direct relationship. Narrow and fail-closed is the right first move.

The evidence boundary is good. [SqlChartEvidenceRepository.php](../../../src/AgentForge/Evidence/SqlChartEvidenceRepository.php:17) uses fixed, parameterized, patient-scoped queries rather than model-generated SQL.

The failure behavior is much better than typical student work. [VerifiedAgentHandler.php](../../../src/AgentForge/Handlers/VerifiedAgentHandler.php:68) handles draft provider failures, tool failures, verification failures, and clinical-advice refusals visibly.

**Priority Fixes**

1. Make verifier distrust claim type. Require every displayed sentence to be either non-patient boilerplate from an allowlist or source-supported by evidence. Add a regression test where a patient fact labeled `warning` fails.

2. Display citations in the UI. Even a compact “Sources: lab:..., medication:...” list is better than hiding them.

3. Add conversation state. Minimum viable version: `conversation_id`, server-side turn log or summary, and UI transcript. Keep it current-patient only.

4. Split evals into tiers: deterministic unit evals, real SQL evidence evals against seeded demo data, live model contract evals, and deployed smoke evals.

5. Rename PHI-free logging to PHI-minimized sensitive audit logging, then document retention and access controls.

**Bottom Line**

This is a credible safety-first prototype, not a toy. But the verifier bypass and lack of multi-turn state are core spec misses. I’d grade it as a strong **B** technically: impressive foundation, but not yet defensible as a production-ready clinical co-pilot.

## Review 3

I'll start by reading the SPECS document, then survey the project to evaluate what was actually delivered against requirements.

Now let me survey what was actually built against these requirements.

Historical note: at the time of this review pass, the spec-required `./AUDIT.md`, `./USERS.md`, and `./ARCHITECTURE.md` root files were not yet in their final reviewer-rooted locations. They now exist at the repository root; this section is preserved as review history.

Let me track this multi-part review and continue surveying the actual implementation.

The cost analysis is a major shortfall — let me check what was actually built. I need to find the agent code, verifier, observability, and evals.

# Instructor Review — AgentForge Clinical Co-Pilot

**Reviewer posture:** tough but fair. Scope: project artifacts only (SPECS-required deliverables and the running system). Human-submission items (demo videos, social posts) are excluded per your instruction.

**Headline:** This is one of the more *intellectually honest* submissions I've graded. The team did the hard, unglamorous work of writing a real first-principles audit, building a fail-closed authorization boundary, and standing up a real deployment with rollback. They earn meaningful credit for taking the safety story seriously when it would have been easier to wave hands at it.

But the spec asked for several specific things, and the submission misses or weakens several of them. Below: deliverable-by-deliverable, then the four required components, then strengths and the cited shortfalls.

---

## 1. Required deliverables vs. SPECS

### 1.1 AUDIT.md, USERS.md, ARCHITECTURE.md

**Spec wording:** the rubric lists these at `./AUDIT.md`, `./USERS.md`, `./ARCHITECTURE.md` — repo root.
**Submitted:** [../AUDIT.md](../AUDIT.md), [../USERS.md](../USERS.md), [../ARCHITECTURE.md](../ARCHITECTURE.md).

**Remediated historical shortfall (S-1, gating):** At the time of this review pass, the required documents were not at repo root. They now exist at `./AUDIT.md`, `./USERS.md`, and `./ARCHITECTURE.md`; this finding remains here as historical review context.

**Content quality (separate from location):**

- **AUDIT.md** is genuinely good. It is scoped to *what matters for the agent* (not a generic "OpenEMR security audit"), it ranks findings, and — critically — it includes a self-aware sentence acknowledging that it verifies *schema shape*, not production latency. That kind of honesty is rare and earned.
- **USERS.md** is appropriately narrow: one persona (outpatient PCP), three use cases, an explicit non-goals list. The non-goals list (no diagnosis, no dosing, no treatment) is load-bearing for the rest of the design and is correctly placed up front.
- **ARCHITECTURE.md** has the ~500-word summary the spec asks for and includes a capability-to-use-case traceability table and a trust-boundary-to-audit-finding table. The traceability is unusually disciplined.

### 1.2 Eval Dataset

**Submitted:** [../../fixtures/eval-cases.json](../../fixtures/eval-cases.json) — 13 cases, 9 marked `safety_critical`. Run via [../../scripts/run-evals.php](../../scripts/run-evals.php). Latest result: 13/13 pass.

**Shortfall (S-2, severe):** The eval suite never exercises the real LLM. Twelve of thirteen cases use `FixtureDraftProvider` and one uses `EvalHallucinatingDraftProvider`. The result file confirms it: `model: "fixture-draft-provider"`, `input_tokens: 0`, `output_tokens: 0`, `estimated_cost: null`, `latency_ms: 0` or `1`.

So when you report "13/13 passing safety evals," what you have actually proven is that **the verifier rejects what the fixtures tell it to reject**. That is a test of `DraftVerifier` and `ClinicalAdviceRefusalPolicy`, not a test of the agent. The spec asked for an eval dataset that evaluates the system; you have an eval dataset that evaluates one component of the system with the model stubbed out.

This is a real gap. The right shape is: keep the deterministic fixture suite (it's valuable — it pins verifier behavior), and add a *small* second tier — even 5 cases — that hits the real provider so regressions in prompt, schema, or model upgrade are caught. Right now nothing does that.

**Coverage shortfall (S-3, moderate):** The taxonomy the spec asks for — failure modes, missing data, ambiguous queries, unauthorized access — is mostly represented (`missing_microalbumin`, `unauthorized_patient_request`, `cross_patient_request`, `prompt_injection`, `tool_failure`, `hallucinated_claim`). What's missing: ambiguous queries are thin (`unclear_role_boundary` is one case), and there is no eval for the multi-turn use case the USERS.md doc itself defines (Use Case 2: Follow-Up Drill-Down). You can't evaluate a capability you haven't built (see §2.1).

### 1.3 AI Cost Analysis

**Submitted:** [../operations/COST-ANALYSIS.md](../operations/COST-ANALYSIS.md).

**Shortfall (S-4, severe — this is the one to fix first):** The spec is explicit that the projection should be at 100 / 1,000 / 10,000 / 100,000 *USERS*, with discussion of *architectural changes*, and is "not simply cost-per-token × n users." Your submission does exactly the thing the spec forbids:

- The projection table at [COST-ANALYSIS.md:67-73](../operations/COST-ANALYSIS.md:67) is in *monthly requests*, not users.
- It is mechanically `(request_cost) × n` — the value at 1,000 is exactly 10× the value at 100.
- There is no discussion of what changes architecturally between tiers: caching, batch pricing, model tiering, rate limits, dedicated capacity, fallback providers, on-prem hosting at scale, retention/log volumes, none of it.
- Hosting, monitoring, support, retention costs are correctly listed under "Known Unknowns" — but listing them as unknown does not satisfy a deliverable that explicitly asks for them.

The single real measurement (gpt-4o-mini, 836 in / 173 out, $0.0002292, 10,693 ms on the VM) is good — it's honest and reproducible. But one data point on one prompt shape is a baseline, not an analysis. The cost analysis is the weakest required deliverable.

---

## 2. Required components vs. SPECS

### 2.1 "Agentic chatbot" (multi-turn, follow-ups, tool chaining)

**Submitted:** [src/AgentForge/Handlers/VerifiedAgentHandler.php](../../../src/AgentForge/Handlers/VerifiedAgentHandler.php), [interface/patient_file/summary/agent_request.php](../../../interface/patient_file/summary/agent_request.php), [templates/patient/card/agent_forge.html.twig](../../../templates/patient/card/agent_forge.html.twig).

**Shortfall (S-5, severe):** Calling this "agentic" is generous. It is a fixed-pipeline single-shot RAG system:

- The handler runs *every* evidence tool on *every* request — the model does not select tools. There is no tool chaining; there is tool *concatenation*, decided by deterministic question classification.
- There is no conversation state. The endpoint takes a question and a patient ID; there is no `conversation_id`, no prior-turn context, no follow-up handling. The Twig template is a single textarea and a Send button — submit, render, done.
- This directly contradicts your own [USERS.md](../USERS.md) Use Case 2 ("Follow-Up Drill-Down"), which explicitly requires multi-turn.

To be clear: a single-shot constrained-RAG design is *defensible* for a clinical co-pilot — it's actually the safer choice. But then USERS.md should not promise multi-turn, ARCHITECTURE.md should explicitly say "we do not use a multi-turn agent loop and here is why," and the spec's "agentic" requirement should be addressed head-on. Right now the docs claim a capability the code does not have.

**Shortfall (S-6, moderate):** The question classifier in `VerifiedAgentHandler` is naïve `str_contains` — "metformin" routes to medications, "creatinine" routes to labs. It is brittle to phrasing and unaware of synonyms. Document this as a deliberate floor or strengthen it; right now it is neither.

### 2.2 Verification (source attribution + domain constraints)

**Submitted:** [src/AgentForge/Verification/DraftVerifier.php](../../../src/AgentForge/Verification/DraftVerifier.php), [src/AgentForge/Verification/ClinicalAdviceRefusalPolicy.php](../../../src/AgentForge/Verification/ClinicalAdviceRefusalPolicy.php), [src/AgentForge/ResponseGeneration/OpenAiDraftProvider.php](../../../src/AgentForge/ResponseGeneration/OpenAiDraftProvider.php).

**Strength:** The domain-constraint side is genuinely well done. The OpenAI provider uses `response_format: json_schema` with `strict: true`, temperature 0, `#[SensitiveParameter]` on the API key, and a system prompt that explicitly forbids diagnosis/dosing/notes. The `ClinicalAdviceRefusalPolicy` is applied to both sentences and claims. Refusals are wired through to the evidence layer (`refusalSentences` map) so the policy and the model agree. This is solid boundary discipline.

**Shortfall (S-7, moderate):** Source attribution is implemented as substring matching — [DraftVerifier.php:93-98](../../../src/AgentForge/Verification/DraftVerifier.php:93):

```php
if (
    !str_contains($claimText, $this->normalize($item->displayLabel))
    || !str_contains($claimText, $this->normalize($item->value))
) {
    return false;
}
```

Two failure modes:

- **False negatives (brittle):** A claim that says "HbA1c was 8.2 %" is rejected because the evidence label is "Hemoglobin A1c." Any paraphrase is rejected. Your prompt counters this by instructing the model to copy display_label and value verbatim, which works *most of the time* — but the verifier should not be one prompt regression away from rejecting all legitimate answers.
- **False positives (semantic gap):** A claim like "Hemoglobin A1c was 8.2 % and the patient should start insulin" passes the verifier as long as the label and value substrings are present. The unsupported tail is only blocked if `ClinicalAdviceRefusalPolicy` happens to catch the word "insulin." The verifier does not actually verify that the *whole claim* is grounded — only that the cited substrings appear somewhere in the claim text.

This is the kind of weakness that an instructor review should flag because it directly affects the safety story. The fix is non-trivial (semantic alignment, structural claim parsing, or NLI scoring), but at minimum the limitation should be documented in ARCHITECTURE.md as a known weakness.

**Remediation status (2026-05-02):** Substring matching has been replaced by [`EvidenceMatcher`](../../../src/AgentForge/Verification/EvidenceMatcher.php) token-set matching. All significant tokens of the cited evidence's `display_label` and `value` must appear as whole tokens in the claim text; numeric tokens must match exactly (so the false-negative case "HbA1c was 8.2 %" against label `Hemoglobin A1c` is still rejected on the label side, but the false-positive case where a digit substring like "5" matches inside "5.0 %" no longer passes). English-month -> ISO date canonicalization closes the date-paraphrase failure mode without weakening exact-value enforcement. The unsupported-tail false-positive cited above is now also blocked by claim-coverage enforcement. Broader semantic paraphrase verification remains unavailable.

### 2.3 Observability (request, latency, tool failures, tokens, cost)

**Submitted:** [src/AgentForge/Observability/RequestLog.php](../../../src/AgentForge/Observability/RequestLog.php), [src/AgentForge/Observability/PsrRequestLogger.php](../../../src/AgentForge/Observability/PsrRequestLogger.php).

**Strength:** The log entry is well-shaped: request_id, user_id, patient_id, decision, latency_ms, question_type, tools_called, source_ids, model, input/output tokens, estimated_cost, failure_reason, verifier_result. It is a PHI-minimized sensitive audit log contract, and that contract is enforced by isolated tests. Token and cost capture work for the real provider (the COST-ANALYSIS measurement comes from this path).

**Shortfall (S-8, moderate):** It is one log line per request to apache `error.log`. There is no aggregation, no dashboard, no SLO definition, no alerting. The spec says "observability"; the current system has structured logging, not production observability.

**Shortfall (S-9, moderate):** Measured VM latency is **10,693 ms** (per COST-ANALYSIS.md). USERS.md and ARCHITECTURE.md frame the agent as something a clinician uses to save time mid-visit — that's a "seconds" budget, not "ten seconds." There is one VM measurement. Either accept the budget and document it ("p50 ~10 s on current VM, acceptable for v1 because…") or set a target and don't ship without measuring against it. Right now there is no defined latency budget at all, and the one number you have is borderline-disqualifying for the use case you described.

### 2.4 Evaluation (failure modes, missing data, ambiguous queries, unauthorized access)

Mostly addressed under §1.2. Restating the structural gap because it is the most important single issue: **the evals stub the model.** A 13/13 green build does not mean the agent works; it means the verifier rejects what the test fixtures tell it to reject. Until at least a small tier of evals hits the real provider, this is not an evaluation of the agent.

---

## 3. Other things worth grading

### 3.1 Authorization

[src/AgentForge/Auth/PatientAuthorizationGate.php](../../../src/AgentForge/Auth/PatientAuthorizationGate.php), [src/AgentForge/Auth/SqlPatientAccessRepository.php](../../../src/AgentForge/Auth/SqlPatientAccessRepository.php).

**Strength:** Fail-closed end-to-end. Session user > 0, session patient > 0, requested patient matches active chart, ACL check, patient exists, direct relationship check. RuntimeException → refusal. This is correct.

**Shortfall (S-10, scope-acknowledged):** Only direct provider/encounter relationships count. No care-team, no facility scope, no group, no scheduling-derived authorization. ARCHITECTURE.md acknowledges this honestly, which mitigates the grade — but it does mean the system would be wrong-by-default in any practice with shared-coverage workflows. Don't ship this without that disclosure on every deployment.

### 3.2 Data layer

[src/AgentForge/Evidence/SqlChartEvidenceRepository.php](../../../src/AgentForge/Evidence/SqlChartEvidenceRepository.php).

**Strength:** Parameterized queries throughout, bounded `LIMIT` clauses (max 50), explicit handling of OpenEMR's quirks (the `CAST` workaround for `form_clinical_notes.encounter` being VARCHAR is the right call given the legacy schema).

**Shortfall (S-11, moderate):** AUDIT.md's P1 finding identifies missing composite indexes for the agent's query shapes. No migration has been created. Future index work needs before/after `EXPLAIN`, OpenEMR migration review, and rollback documentation.

### 3.3 Demo data

Single demo patient, `pid=900001`. The eval suite tests safety with fixtures, but human-grade end-to-end exercise of the live system rests on one chart. For a clinical demo, three to five patient profiles representing different chart densities (sparse / typical / complex polypharmacy) would meaningfully strengthen the story.

### 3.4 Deployment

[../epics/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md](../epics/EPIC2-DEPLOYMENT-RUNTIME-PROOF.md), [../../scripts/deploy-vm.sh](../../scripts/deploy-vm.sh), [../../scripts/rollback-vm.sh](../../scripts/rollback-vm.sh).

**Strength:** Real deploy + real rollback, with captured transcripts and a 200 health check. Cloudflare TLS termination. Honest documentation of MariaDB first-init fragility. This is *the* part of the submission where the team most clearly went past the minimum bar.

---

## 4. Strengths summary (unprompted credit)

These are not the focus of the review but they should be on the record:

1. **First-principles audit that is actually scoped to the application.** Most teams submit a generic security audit; you submitted one that justifies the design choices that follow.
2. **Boundary discipline.** Fail-closed authorization, structured-output schema with strict mode, refusal policy applied at sentence and claim layers, and PHI-minimized sensitive audit logging enforced by tests.
3. **Modern PHP done right.** Strict types, readonly classes, PSR-4, DI through constructors, `#[SensitiveParameter]` on the API key, explicit value objects (`PatientId`, `AgentRequest`).
4. **Honest documentation of unavailable scope.** The audit and architecture docs both name what was *not* done, including care-team auth, dashboards, and multi-patient demo data, rather than papering over it. This is the trait that turns a v1 into a real v2.
5. **Reproducible deployment.** Real scripts, real transcripts, real rollback path. Not a screenshot — a script.

---

## 5. Cited shortfalls — ranked

| # | Severity | Shortfall | Where |
|---|---|---|---|
| S-4 | Severe | COST-ANALYSIS measures requests not users, is `cost × n` (the spec explicitly forbids this), no architectural-tier discussion, no infra cost. | [COST-ANALYSIS.md](../operations/COST-ANALYSIS.md) |
| S-2 | Severe | Eval suite stubs the LLM (`fixture-draft-provider`, 0 tokens, 0–1 ms). 13/13 green does not validate the real agent. | [run-evals.php](../../scripts/run-evals.php), `src/AgentForge/Eval/` |
| S-5 | Severe | Single-shot RAG, no tool selection by model, no multi-turn — but USERS.md and the "agentic" framing claim otherwise. | [VerifiedAgentHandler.php](../../../src/AgentForge/Handlers/VerifiedAgentHandler.php), [agent_forge.html.twig](../../../templates/patient/card/agent_forge.html.twig) |
| S-1 | Gating | AUDIT/USERS/ARCHITECTURE are at `../`, not repo root as the spec specifies. | [../](../) |
| S-7 | Moderate (remediated 2026-05-02) | DraftVerifier substring matching replaced by `EvidenceMatcher` token-set matching with ISO-date canonicalization; unsupported-tail false-positive blocked by Task 12.1.2. | [EvidenceMatcher.php](../../../src/AgentForge/Verification/EvidenceMatcher.php), [EPIC_VERIFIER_HARDENING_TOOL_ROUTING.md](../epics/EPIC_VERIFIER_HARDENING_TOOL_ROUTING.md) |
| S-9 | Moderate | Measured VM latency 10,693 ms vs. a "seconds" use case. No defined latency budget. | [COST-ANALYSIS.md:62](../operations/COST-ANALYSIS.md:62) |
| S-3 | Moderate | Eval coverage thin on ambiguous queries; no eval for the multi-turn use case USERS.md defines. | [eval-cases.json](../../fixtures/eval-cases.json) |
| S-6 | Moderate | Naïve `str_contains` question classifier — brittle to phrasing/synonyms. | [VerifiedAgentHandler.php](../../../src/AgentForge/Handlers/VerifiedAgentHandler.php) |
| S-8 | Moderate | "Observability" is structured log lines to apache error.log — no aggregation, no SLO, no alerts. | [PsrRequestLogger.php](../../../src/AgentForge/Observability/PsrRequestLogger.php) |
| S-11 | Moderate | AUDIT P1 (missing composite indexes) identified but not remediated. | [AUDIT.md](../AUDIT.md) |
| S-10 | Acknowledged | Authorization scope: direct provider/encounter relationship only — no care-team / facility / scheduling derivation. | [SqlPatientAccessRepository.php](../../../src/AgentForge/Auth/SqlPatientAccessRepository.php) |

---

## 6. Bottom line

You built a safer-than-average system on top of a hard codebase, you took the audit seriously, and you actually deployed it with a working rollback. That is the spine of a real submission.

The submission falls short on three things the spec asked for and the team can fix without rewriting the system:

1. **Move the required docs to repo root** (or add root-level copies/symlinks). Cheapest fix, biggest grade impact.
2. **Rewrite COST-ANALYSIS to address users-at-tiers with architectural changes**, not requests-times-cost. Even rough numbers with explicit assumptions beat a single point estimate.
3. **Add a small real-LLM tier to the eval suite** — even five cases — and stop calling the fixture-only run a 13/13 pass on the agent. It's a 13/13 pass on the verifier.

Two more I would not ship without addressing, but which require more work:

4. **Either build the multi-turn capability USERS.md promises, or rewrite USERS.md and ARCHITECTURE.md to declare single-shot as a deliberate design.** Right now the docs and the code disagree.
5. **Define a latency budget and either meet it or document acceptance.** 10.7 s on the only measurement is too close to the line for a clinical-co-pilot framing.

Grade-wise: this is in the upper band of submissions because the *thinking* is good, but the rubric is the rubric — the cost analysis, eval suite, "agentic" framing, and document location issues are concrete and should be cited as such on the scorecard.

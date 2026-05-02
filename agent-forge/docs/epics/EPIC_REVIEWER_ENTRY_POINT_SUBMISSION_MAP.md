# Epic 15 - Reviewer Entry Point And Submission Map

**Generated:** 2026-05-02
**Scope:** documentation and reviewer packaging
**Status:** Complete

## Summary

Epic 15 fixes a reviewer-packaging regression: the root `README.md` linked to `AGENTFORGE-REVIEWER-GUIDE.md`, but the guide was absent. The implemented change restores one root reviewer entry point, keeps `SPECS.txt` as the source of truth, and adds isolated document proof so the missing-guide failure cannot silently recur.

No runtime agent behavior, credentials, secrets, or production-readiness claims were added.

## Acceptance Map

| Requirement | Implementation | Proof |
| --- | --- | --- |
| Root README points to an existing reviewer entry point. | Root `AGENTFORGE-REVIEWER-GUIDE.md` exists and `README.md` already links to it. | `test -f AGENTFORGE-REVIEWER-GUIDE.md`; `ReviewerGuideDocumentTest`; `EvaluationTiersDocumentTest`. |
| Guide contains deployed URL, demo path, commands, artifact map, proof, and blockers. | Guide sections cover documented deployed URL, health command, fake patient, demo credentials policy, seed/verify/eval commands, artifact map, implemented proof, blockers, and Production-Readiness caveats. | Focused `rg` proof and isolated document assertions. |
| Links in the reviewer path resolve from the repository root. | Guide uses root-relative markdown links for local artifacts. | `ReviewerGuideDocumentTest` parses local markdown links in README and guide and checks target existence. |
| Reviewer navigation checklist exists and passes manually. | Guide includes `Reviewer Navigation Checklist`. | Manual root navigation check performed by reading README and guide after implementation. |
| No required submission artifact is discoverable only through tribal knowledge. | Guide links root docs, canonical docs, operations, evaluation, submission plan, and key proof records. | Artifact-map assertions in `ReviewerGuideDocumentTest`. |
| No secrets, real PHI, private credentials, or unsupported production-readiness claims. | Guide documents fake patient only, says credentials are out-of-band, and states Production-Readiness is not claimed. | `ReviewerGuideDocumentTest` checks forbidden credential/production-ready claim text. |

## Commands Run

Planned proof commands:

```sh
test -f AGENTFORGE-REVIEWER-GUIDE.md
rg -n "AGENTFORGE-REVIEWER-GUIDE.md|AgentForge Reviewer Entry Point" README.md
rg -n "Documented deployed URL|health-check.sh|900001|seed-demo-data.sh|verify-demo-data.sh|run-evals.php|COST-ANALYSIS.md|EVALUATION-TIERS.md|Production-Readiness" AGENTFORGE-REVIEWER-GUIDE.md
composer phpunit-isolated -- --filter 'ReviewerGuideDocumentTest|EvaluationTiersDocumentTest'
composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'
php agent-forge/scripts/run-evals.php
```

Proof results:

- `test -f AGENTFORGE-REVIEWER-GUIDE.md`: passed.
- `rg -n "AGENTFORGE-REVIEWER-GUIDE.md|AgentForge Reviewer Entry Point" README.md`: passed; README links the guide.
- `rg -n "Documented deployed URL|health-check.sh|900001|seed-demo-data.sh|verify-demo-data.sh|run-evals.php|COST-ANALYSIS.md|EVALUATION-TIERS.md|Production-Readiness" AGENTFORGE-REVIEWER-GUIDE.md`: passed; required reviewer terms are present.
- `composer phpunit-isolated -- --filter 'ReviewerGuideDocumentTest|EvaluationTiersDocumentTest'`: passed, 10 tests / 141 assertions.
- `composer phpunit-isolated -- --filter 'OpenEMR\\Tests\\Isolated\\AgentForge'`: passed, 209 tests / 1066 assertions.
- `php agent-forge/scripts/run-evals.php`: passed, 13 passed / 0 failed; wrote `agent-forge/eval-results/eval-results-20260502-172819.json`.

## Manual Navigation Result

Manual root navigation was checked from repository root:

- `README.md` contains `AgentForge Reviewer Entry Point`.
- The README link target exists at root `AGENTFORGE-REVIEWER-GUIDE.md`.
- The guide contains the deployed URL, fake patient, demo path, seed command, verify command, eval command, artifact map, proof summary, known blockers, and reviewer checklist.
- Required root artifacts `AUDIT.md`, `USERS.md`, and `ARCHITECTURE.md` exist.

## Explicit Gaps

- Live/deployed proof is not rerun by this documentation epic unless VM/network state is available.
- This epic does not automate browser or deployed eval tiers.
- This epic does not add credentials, secrets, production monitoring, or runtime behavior.

## Review Checkpoint

- [x] Every source acceptance criterion has code, test, human proof, or a named gap.
- [x] Every required proof item has an executable path before implementation starts.
- [x] Boundary/orchestration behavior is tested when a boundary changed; no runtime boundary changed.
- [x] Security/logging/error-handling requirements were implemented or explicitly reported as gaps; this epic only changes reviewer-facing docs and tests.
- [x] Human verification items are checked only after they were actually performed.
- [x] Known fixture/data/user prerequisites for manual proof are documented in the reviewer guide.

## Definition Of Done Gate

- Source criteria mapped to code/proof/deferral? yes
- Required automated tests executed and captured? yes
- Required manual checks executed and captured? yes, root navigation inspection performed
- Required fixtures/data/users for proof exist? yes, fake patient `900001` and seed/verify commands are documented
- Security/privacy/logging/error-handling requirements verified? yes for reviewer-facing documentation claims
- Known limitations and deferred relationship/scope shapes documented? yes
- Epic status updated honestly? yes
- Git left unstaged and uncommitted unless user asked otherwise? yes

## Suggested Commit

`docs(agent-forge): restore reviewer entry point`

## Change Log

- 2026-05-02: Restored root `AGENTFORGE-REVIEWER-GUIDE.md`.
- 2026-05-02: Added reviewer navigation checklist and Epic 15 evidence file.
- 2026-05-02: Added isolated reviewer-guide regression proof and tightened evaluation-tier guide existence proof.
- 2026-05-02: Captured focused reviewer proof, full AgentForge isolated PHPUnit proof, and deterministic eval proof.
- 2026-05-02: Rechecked reviewer navigation during Epics 15-17 manual verification. `README.md` links `AGENTFORGE-REVIEWER-GUIDE.md`; the guide includes deployed URL, seed/verify commands, eval command, cost-analysis link, known blockers, production-readiness caveats, and reviewer checklist. `composer phpunit-isolated -- --filter 'ReviewerGuideDocumentTest|EvaluationTiersDocumentTest'` passed: 10 tests, 141 assertions.

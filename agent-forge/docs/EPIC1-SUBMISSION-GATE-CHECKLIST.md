# Epic 1 Submission Gate Checklist

Verification commit context: `205a70299`

## Task 1.1.1 - Required Submission Documents

Check run:

```sh
test -f agent-forge/docs/AUDIT.md && test -f agent-forge/docs/USERS.md && test -f agent-forge/docs/ARCHITECTURE.md
```

Result: pass.

| Required document | Status | Notes |
| --- | --- | --- |
| `AUDIT.md` | Present | Required by `SPECS.txt` hard gate. |
| `USERS.md` | Present | Required by `SPECS.txt` hard gate. |
| `ARCHITECTURE.md` | Present | Required by `SPECS.txt` hard gate. |

## Task 1.1.2 - Audit Readiness Check

Check performed against `agent-forge/docs/AUDIT.md`:

| Requirement | Evidence | Result |
| --- | --- | --- |
| File exists | `agent-forge/docs/AUDIT.md` is present. | Pass |
| Begins with key-findings summary | `## Summary` starts at the top of the document and identifies the five load-bearing findings. | Pass |
| Covers security | `## Security` includes S1-S3. | Pass |
| Covers performance | `## Performance` includes P1-P2. | Pass |
| Covers architecture | `## Architecture` includes A1-A2. | Pass |
| Covers data quality | `## Data Quality` includes D1-D5. | Pass |
| Covers compliance/regulatory | `## Compliance And Regulatory` includes C1-C3. | Pass |
| Cites accepted evidence | Major findings cite repo source files, schema lines, `SPECS.txt`, or observed command output. | Pass |

## Task 1.1.3 - User Document Readiness Check

Check performed against `agent-forge/docs/USERS.md`:

| Requirement | Evidence | Result |
| --- | --- | --- |
| File exists | `agent-forge/docs/USERS.md` is present. | Pass |
| Defines one narrow user | `## Target User` names a primary care physician seeing scheduled outpatient visits. | Pass |
| Documents workflow | `## Workflow` places the agent at chart-open before the physician enters the room. | Pass |
| Documents supported use cases | Use Case 1, Use Case 2, and Use Case 3 cover briefing, drill-down, and missing/unclear data. | Pass |
| Explains why an agent is needed | Each use case includes a `Why an agent` paragraph. | Pass |
| Avoids unsupported users | `## Non-Goals` excludes users other than the chosen primary care physician for v1. | Pass |

## Task 1.1.4 - Architecture Traceability Check

Check performed against `agent-forge/docs/ARCHITECTURE.md`:

| Requirement | Evidence | Result |
| --- | --- | --- |
| File exists | `agent-forge/docs/ARCHITECTURE.md` is present. | Pass |
| Begins with high-level summary | `## Summary` describes user, integration point, authorization, evidence, verification, logging, and tradeoffs before implementation details. | Pass |
| Capabilities trace to users | `Capability -> User Use Case Mapping` maps briefing, follow-up, missing-data behavior, refusals, chart tools, citations, verification, logging, contracts, and eval cases to `USERS.md`. | Pass |
| Trust boundaries trace to audit | `Trust Boundary -> Audit Finding Mapping` maps authorization, session identity, browser trust, integration shape, bounded reads, evidence, missing data, verification, logging, PHI minimization, and failure handling to `AUDIT.md` or `SPECS.txt`. | Pass |
| Human verification questions are answerable | The summary and traceability tables explain the chart endpoint, deterministic verifier, source-carrying evidence, and missing-data behavior. | Pass |

## Epic 1 DoD Verification

- Required documents are present and visible in `agent-forge/docs`: pass.
- Traceability is explicit and reviewable in `ARCHITECTURE.md`: pass.
- Human verification prompts are satisfiable: pass.
  - Reviewer can identify the most important audit finding from `AUDIT.md` summary.
  - Reviewer can point to the use case that requires multi-turn chat in `USERS.md`.
  - Reviewer can explain integration point, verification layer, and missing-data behavior from `ARCHITECTURE.md`.

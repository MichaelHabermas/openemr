# Meta-Plan: AgentForge Architecture Deepening Execution

## Overview
This meta-plan coordinates implementation of all 5 architecture deepening candidates for AgentForge. Execution follows the sensible order (foundations before integration, integration before specialization): **3 → 4 → 1 → 5 → 2**.

## Execution Order Rationale

| Order | Candidate | Why This Position |
|-------|-----------|-----------------|
| 1 | **3: Composition Root** | Foundation for eval/runtime isolation; eval harness must work before touching extraction logic |
| 2 | **4: Document Extraction Subsystem** | Core multimodal capability; 6 document types consolidated before routing depends on them |
| 3 | **1: Supervisor Routing** | Routing policy depends on extraction results; needs stable document pipeline first |
| 4 | **5: Evidence Assembly** | Orchestrator consumes extraction output; integrates with routing for guideline decisions |
| 5 | **2: Drafting-Verification** | Narrow façade is final trust boundary; depends on all upstream modules being stable |

## Dependency Graph

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ FOUNDATION LAYER                                                            │
│ 3. Composition Root (ClinicalDocumentExtractionPort + Adapter)              │
│    └── Enables deterministic 50-case eval harness                          │
│                                                                              │
│ 4. Document Extraction Subsystem (DocumentExtractionModule + Registry)       │
│    └── Depends on 3: uses adapter for provider selection                     │
│    └── Provides: attachAndExtract() for all 6 document types               │
└─────────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│ INTEGRATION LAYER                                                           │
│ 1. Supervisor Routing (HandoffPolicy + Supervisor)                          │
│    └── Depends on 4: routes to extraction strategies                         │
│    └── Provides: unified routing for chat + document paths                   │
│                                                                              │
│ 5. Evidence Assembly (EvidenceOrchestrator + Ports)                          │
│    └── Depends on 1: uses supervisor for guideline routing decisions         │
│    └── Depends on 4: uses extraction module for on-demand evidence         │
│    └── Provides: EvidenceBundle with coverage reports                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│ TRUST BOUNDARY LAYER                                                        │
│ 2. Drafting-Verification (DraftVerificationPipeline + Assembler)           │
│    └── Depends on 5: receives EvidenceBundle from orchestrator               │
│    └── Depends on 1: refusal decisions from supervisor                     │
│    └── Provides: final AgentResponse with citations/attribution                │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Cross-Cutting Concerns

### Clock/Time Management
- **PSR-20 ClockInterface** required by: 3, 4, 5, 1, 2
- **Implementation**: `MonotonicClock` already exists
- **Test doubles**: `FrozenMonotonicClock`, `AdvanceableMonotonicClock` in test support
- **Strategy**: Inject at every composition root; never instantiate `new DateTimeImmutable()` in business logic

### Observability Without PHI
- **Required by all plans**: No raw PHI in logs/traces
- **Pattern**: `PatientRefHasher` for redacted identifiers
- **Pattern**: `SensitiveLogPolicy` for field allowlists
- **Pattern**: Structured context arrays, never interpolate PHI into messages

### Eval Gate Preservation
- **50-case golden suite** must pass at every phase
- **Boolean rubrics**: schema_valid, citation_present, factually_consistent, safe_refusal, no_phi_in_logs
- **Strategy**: Feature flags for each candidate; run full suite with flag on/off
- **CI gate**: `.github/workflows/agentforge-evals.yml` unchanged

## Implementation Waves

### Wave 1: Foundation (Week 1)
**Goal**: Eval harness stable, document extraction ready

| Day | Work | Verification |
|-----|------|--------------|
| 1-2 | Plan 3: Create ports, contexts, adapter | 50 cases pass with fixtures only |
| 3-4 | Plan 4: Registry, strategies, module | Lab + intake form extraction pass |
| 5 | Integration: Wire adapter → module | End-to-end attachAndExtract works |

**Deliverable**: `DocumentExtractionModule` operational, 50-case suite green

### Wave 2: Integration (Week 2)
**Goal**: Routing unified, evidence orchestrated

| Day | Work | Verification |
|-----|------|--------------|
| 1-2 | Plan 1: Policy, decision types, supervisor | HandoffRubric passes |
| 3-4 | Plan 5: Orchestrator, strategies, coverage | Evidence coverage in 50 cases |
| 5 | Integration: Supervisor → Orchestrator → Module | Full chat + document flow works |

**Deliverable**: Supervisor routes correctly, evidence assembled with guidelines

### Wave 3: Trust Boundary (Week 3)
**Goal**: Narrow façade, clean separation

| Day | Work | Verification |
|-----|------|--------------|
| 1-2 | Plan 2: DraftVerificationPipeline, Pair | Draft/verify separation tested |
| 3-4 | Plan 2: AnswerAssembler, builders | CitationCoverageRubric passes |
| 5 | Integration: Full pipeline with all 5 | All 50 cases + rubrics green |

**Deliverable**: `VerifiedDraftingPipeline` narrowed, all trust boundaries verified

## Rollback Strategy

Each plan has a feature flag:

| Plan | Flag Name | Default | Rollback Trigger |
|------|-----------|---------|------------------|
| 3 | `USE_COMPOSITION_ROOT` | false | Eval contamination (real API keys in tests) |
| 4 | `USE_DEEP_MODULE` | false | Document type regression |
| 1 | `USE_UNIFIED_SUPERVISOR` | false | Routing errors, wrong worker dispatch |
| 5 | `USE_EVIDENCE_ORCHESTRATOR` | false | Evidence gaps, deadline handling bugs |
| 2 | `USE_NARROW_PIPELINE` | false | Citation format regression, refusal errors |

**Rollback procedure**:
1. Set flag to `false` in environment
2. Verify 50 cases pass on old path
3. Debug new path in isolation
4. Re-enable when fixed

## Testing Strategy Summary

### Boundary Tests (Replace Internal Tests)
| Module | Boundary Test | What It Proves |
|--------|-------------|----------------|
| 3 | `ClinicalDocumentExtractionAdapterTest` | Eval never uses real provider |
| 4 | `DocumentExtractionModuleTest` | One public method, 6 types work |
| 1 | `SupervisorTest` | Policy decides, handoff logs |
| 5 | `EvidenceOrchestratorTest` | Deadline/policy/coverage correct |
| 2 | `DraftVerificationPipelineTest` | Draft/verify without shaping |

### Unit Tests (Preserve)
- `DraftVerifierTest` - verification logic unchanged
- `*FactMapperTest` - per-type mapping correct
- `PatientAuthorizationGateTest` - auth unchanged
- `*RubricTest` - rubric logic unchanged

## SOLID/DRY Verification Checklist

For each completed plan, verify:

- [ ] **Single Responsibility**: Each class has one reason to change
- [ ] **Open/Closed**: New feature = new class, no existing changes
- [ ] **Liskov Substitution**: All implementations satisfy interface contracts
- [ ] **Interface Segregation**: No fat interfaces; clients see only what they need
- [ ] **Dependency Inversion**: Business logic depends on abstractions, not concretions
- [ ] **DRY**: No duplicated decision logic, no repeated switch/match blocks

## Completion Criteria

All 5 plans complete when:

1. **All 50 golden cases pass** with all feature flags enabled
2. **All boolean rubrics pass**:
   - schema_valid: strict schemas enforced
   - citation_present: every claim has source
   - factually_consistent: no hallucinations pass
   - safe_refusal: malicious requests blocked
   - no_phi_in_logs: redaction verified
3. **Boundary tests replace internal tests** where planned
4. **Feature flags removed** (old code deleted after 100% confidence)
5. **Performance neutral or improved** (p95 latency stable)
6. **Code coverage maintained or improved**

## Plan Files

| Plan | File |
|------|------|
| 3 | `PLAN-03-Composition-Root.md` |
| 4 | `PLAN-04-Document-Extraction-Subsystem.md` |
| 1 | `PLAN-01-Supervisor-Routing.md` |
| 5 | `PLAN-05-Evidence-Assembly.md` |
| 2 | `PLAN-02-Drafting-Verification-Facade.md` |

## Next Steps

1. Review all 5 plans for approval
2. Assign Wave 1 (Foundation) start date
3. Begin implementation of Plan 3
4. Track progress via TODO list in this project

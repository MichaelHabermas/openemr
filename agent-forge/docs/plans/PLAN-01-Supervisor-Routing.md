# Implementation Plan: Candidate 1 — Unified Routing / Handoff Policy for Supervisor Semantics

## Objective
Unify scattered routing logic across document jobs and chat paths into a single, explicit `HandoffPolicy` + `Supervisor` orchestrator. Replace string heuristics and side-effect logging with deterministic, typed decisions and mandatory handoff persistence. Enable the 50-case harness to test routing decisions independently.

## Acceptance Criteria
- [ ] `HandoffPolicy` interface defined with single method: `decide()`
- [ ] `Supervisor` class uses policy + repository + clock (no heuristics inline)
- [ ] `VerifiedAgentHandler` delegates routing to `Supervisor` instead of inline logic
- [ ] All handoffs logged to `SupervisorHandoffRepository` with structured context
- [ ] `SupervisorHandoffRubric` passes for all 50 cases
- [ ] String heuristics (`requiresGuidelineEvidence()`) moved into policy implementation
- [ ] Document job routing and chat routing use same policy abstraction
- [ ] Refusal decisions have clear source attribution in logs

## Files to Create

```
src/AgentForge/Orchestration/
├── HandoffPolicy.php                           # Strategy interface
├── DecisionType.php                            # Enum: Extract, Guideline, Answer, Refuse, Hold
├── HandoffDecision.php                         # Immutable result value object
├── HandoffContext.php                          # Request + patient + conversation state
├── Policy/
│   ├── DefaultHandoffPolicy.php                # Current heuristic logic encapsulated
│   ├── GuidelineAwarePolicy.php                # Extended policy with guideline detection
│   └── DeterministicDocumentPolicy.php         # Document job specific policy
└── SupervisorRuntime.php                       # Already exists - refactor to use new abstractions
```

## Files to Modify

```
src/AgentForge/
├── Handlers/VerifiedAgentHandler.php           # Replace inline routing with Supervisor
├── Orchestration/Supervisor.php                # Use HandoffPolicy, remove logic
├── Orchestration/SupervisorRuntime.php         # Pass through to repository with full context
└── Document/Worker/IntakeExtractorWorker.php     # Trigger handoff via Supervisor

tests/Tests/Isolated/AgentForge/
├── Orchestration/HandoffPolicyTest.php
├── Orchestration/SupervisorTest.php              # Boundary tests
└── Orchestration/SupervisorHandoffRubricTest.php # Updated for new abstractions
```

## Implementation Order

### Phase 1: Define Policy Abstractions (2 hours)
1. Create `DecisionType` enum:
   - `Extract` → route to `IntakeExtractor`
   - `Guideline` → route to `EvidenceRetriever` with guideline flag
   - `Answer` → final answer ready (no worker needed)
   - `Refuse` → refusal (safety/scope violation)
   - `Hold` → waiting (retracted, failed, identity pending)
2. Create `HandoffDecision` value object:
   - `decisionType: DecisionType`
   - `reason: string` (machine-readable code)
   - `context: array<string, scalar>` (structured context)
   - `shouldHandoff(): bool` (extract/guideline = true)
3. Create `HandoffContext` DTO:
   - Request, patient, conversation summary, document job (if applicable), deadline remaining
4. Create `HandoffPolicy` interface:
   ```php
   public function decide(HandoffContext $context): HandoffDecision;
   ```

### Phase 2: Implement Policy Classes (2-3 hours)
1. `DefaultHandoffPolicy` - encapsulates current `VerifiedAgentHandler` heuristics:
   - Keyword detection for guidelines (`guideline`, `evidence`, `ACC/AHA`, etc.)
   - Question type matching (`follow_up_change_review`, etc.)
   - Refusal triggers (cross-patient, out-of-scope)
2. `DeterministicDocumentPolicy` - document job specific:
   - Job status checks (Succeeded/Failed/Retracted)
   - Identity trust check
   - Simple routing: Failed→Hold, Succeeded→EvidenceRetriever, else→IntakeExtractor
3. Policy selection injected at composition root

### Phase 3: Refactor Supervisor (2 hours)
1. Update `Supervisor`:
   - Constructor: `HandoffPolicy $policy`, `SupervisorHandoffRepository $repo`, `ClockInterface $clock`
   - Single public method: `route(HandoffContext $context): SupervisorDecision`
   - Implementation:
     - Call `$this->policy->decide($context)`
     - Log decision to repository with timestamp
     - Return `SupervisorDecision` (wrapper around `HandoffDecision`)
2. Remove inline decision logic from `Supervisor`

### Phase 4: Refactor VerifiedAgentHandler (2-3 hours)
1. Remove `requiresGuidelineEvidence()` private method (moved to policy)
2. Remove `recordGuidelineHandoff()` side-effect (now in Supervisor)
3. Update `handle()`:
   - Build `HandoffContext` from request
   - Call `$this->supervisor->route($context)`
   - Switch on decision type to dispatch to appropriate worker
4. Constructor now requires `Supervisor` instead of building it internally

### Phase 5: Update Document Job Routing (1-2 hours)
1. Update `DocumentJobWorkerFactory` to inject `Supervisor`
2. `IntakeExtractorWorker` triggers handoff post-extraction via Supervisor
3. Ensure document path and chat path use same handoff repository schema

### Phase 6: Verification (2 hours)
1. Run `SupervisorHandoffRubric` across 50 cases
2. Verify all handoffs logged with correct node names
3. Test refusal decisions have correct `reason` codes
4. Test guideline routing with keyword queries

## SOLID/DRY Compliance

- **Single Responsibility**: Supervisor = orchestration + logging only; Policy = decision logic only
- **Open/Closed**: New routing rules = new policy class; Supervisor unchanged
- **Liskov Substitution**: All policies satisfy `HandoffPolicy` contract
- **Interface Segregation**: Policy interface is one method, one context type
- **Dependency Inversion**: Handler depends on `Supervisor` abstraction, not concrete routing logic
- **DRY**: Single `HandoffDecision` type replaces scattered bool/string/enum returns across codebase

## Boundary Test Strategy

```php
// Test: Policy decides guideline routing
$policy = new DefaultHandoffPolicy();
$context = HandoffContext::forChat($questionWithGuidelineKeyword, $patient);
$decision = $policy->decide($context);
$this->assertSame(DecisionType::Guideline, $decision->type);

// Test: Supervisor logs handoff
$repo = new FakeHandoffRepository();
$supervisor = new Supervisor($policy, $repo, $clock);
$supervisor->route($context);
$this->assertCount(1, $repo->recordedHandoffs);

// Test: Document job routing
$docContext = HandoffContext::forDocument($job, /* succeeded, trusted */);
$decision = $policy->decide($docContext);
$this->assertSame(DecisionType::Guideline, $decision->type); // trusted → evidence

// Test: Refusal with reason code
$crossPatientContext = HandoffContext::forChat($crossPatientQuery, $patient);
$decision = $policy->decide($crossPatientContext);
$this->assertSame(DecisionType::Refuse, $decision->type);
$this->assertSame('cross_patient_scope', $decision->reason);
```

## Migration of Existing Logic

| Current Location | New Location |
|----------------|-------------|
| `VerifiedAgentHandler::requiresGuidelineEvidence()` | `DefaultHandoffPolicy::detectGuidelineNeed()` |
| `VerifiedAgentHandler::recordGuidelineHandoff()` | `Supervisor::route()` logging |
| `Supervisor::decide()` inline status checks | `DeterministicDocumentPolicy` |
| `NodeName` hardcoding in handler | `HandoffDecision` contains target node |

## Risk Mitigation

- **Routing regression**: All 50 cases test policy decisions; golden manifest includes expected routing
- **Performance**: Policy decisions are pure functions (no I/O); no latency impact
- **Complexity explosion**: Policies are small, composable; common base class for shared logic
- **Handoff audit gaps**: All decisions logged via repository; repository has its own unit tests

## Rollback Plan

Feature flag `USE_UNIFIED_SUPERVISOR` toggles between old inline routing and new policy-based. If handoff rubrics fail, revert to old path while debugging policy logic.

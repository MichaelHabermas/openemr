# Implementation Plan: Candidate 5 — Evidence Bundle Assembly as Integration Deep Module

## Objective
Consolidate scattered evidence collection logic (planner, selector, collector, invoker, retriever, guideline integration) into a single deep module: `EvidenceOrchestrator` with narrow public API. Hide the complexity of speed-vs-completeness tradeoffs, deadline enforcement, prefetch policies, and guideline retrieval behind a single boundary.

## Acceptance Criteria
- [ ] `EvidenceOrchestrator` created with single public method: `assemble()`
- [ ] `EvidenceBundleAssemblyPort` interface for pluggable collection strategies
- [ ] `EvidenceRetrieverWorker` implements port for document-based evidence
- [ ] `ChartEvidenceCollector` refactored to internal strategy (not exposed)
- [ ] All deadline handling centralized in orchestrator
- [ ] `EvidenceBundle` remains immutable value object (already good)
- [ ] 50-case golden suite passes with correct evidence coverage
- [ ] Speed vs completeness policy is configurable (eager/lazy/on-demand)
- [ ] `EvidencePolicy` enum for collection strategies

## Files to Create

```
src/AgentForge/Evidence/
├── EvidenceOrchestrator.php                    # Deep module facade
├── EvidenceBundleAssemblyPort.php              # Strategy interface
├── EvidencePolicy.php                          # Enum: Eager, Lazy, OnDemand
├── AssemblyContext.php                         # Request + plan + deadline + policy
├── Strategy/
│   ├── SerialEvidenceStrategy.php              # Current SerialChartEvidenceCollector
│   ├── ConcurrentEvidenceStrategy.php            # Future concurrent (placeholder now)
│   └── OnDemandDocumentStrategy.php            # Re-extract from docs if needed
└── Result/
    ├── EvidenceAssemblyResult.php              # Bundle + timing + coverage
    └── EvidenceCoverageReport.php              # What was/wasn't found
```

## Files to Modify

```
src/AgentForge/
├── Evidence/
│   ├── SerialChartEvidenceCollector.php        # Convert to internal strategy
│   ├── ChartEvidenceToolInvoker.php            # Hide behind orchestrator
│   ├── EvidenceRetrieverWorker.php             # Implement EvidenceBundleAssemblyPort
│   └── ChartQuestionPlanner.php                # Provide plan to orchestrator
├── Handlers/VerifiedAgentHandler.php           # Use orchestrator, remove direct collector wiring
└── Guidelines/HybridGuidelineRetriever.php     # Integrate via orchestrator

tests/Tests/Isolated/AgentForge/
├── Evidence/EvidenceOrchestratorTest.php       # Boundary tests
├── Evidence/EvidenceBundleAssemblyPortTest.php
└── Evidence/EvidenceCoverageRubricTest.php     # Golden case coverage verification
```

## Implementation Order

### Phase 1: Define Port and Policy Abstractions (2 hours)
1. Create `EvidencePolicy` enum:
   - `Eager` - prefetch all sections, accept deadline risk
   - `Lazy` - collect only explicitly planned sections
   - `OnDemand` - re-extract from documents if chart data missing
2. Create `EvidenceBundleAssemblyPort` interface:
   ```php
   public function assembleBundle(
       PatientId $patientId,
       AgentQuestion $question,
       ChartQuestionPlan $plan,
       ?Deadline $deadline = null
   ): EvidenceRun;
   ```
3. Create `AssemblyContext` DTO:
   - Patient, question, plan, deadline, policy, timer (optional)
4. Create `EvidenceAssemblyResult` value object:
   - `EvidenceBundle $bundle`
   - `array $timingMs` per section
   - `EvidenceCoverageReport $coverage`
   - `bool $deadlineExceeded`

### Phase 2: Implement Orchestrator (3-4 hours)
1. Create `EvidenceOrchestrator`:
   - Constructor: `ClockInterface $clock`, `LoggerInterface $logger`, strategy deps
   - Single public method:
     ```php
     public function assemble(
         PatientId $patientId,
         AgentQuestion $question,
         ChartQuestionPlan $plan,
         EvidencePolicy $policy,
         ?Deadline $deadline = null,
         ?StageTimer $timer = null
     ): EvidenceAssemblyResult;
     ```
2. Internal orchestration (hidden complexity):
   - Apply policy to select strategy
   - Prefetch sections if `Eager` policy
   - Execute collection via strategy
   - Check deadline at each step
   - Merge guidelines if requested (via separate port call)
   - Build coverage report
   - Return immutable result

### Phase 3: Convert Existing Collectors to Strategies (2-3 hours)
1. `SerialChartEvidenceCollector` → `SerialEvidenceStrategy implements EvidenceBundleAssemblyPort`
   - Extract interface compliance
   - Keep internal prefetch/deadline logic
   - No public API change for existing callers (via adapter)
2. `ConcurrentChartEvidenceCollector` → `ConcurrentEvidenceStrategy` (placeholder/future)
3. `OnDemandDocumentExtractionTool` integration for `OnDemand` policy

### Phase 4: Refactor EvidenceRetrieverWorker (2 hours)
1. Implement `EvidenceBundleAssemblyPort`
2. Add guideline retrieval integration:
   - If `includeGuidelines` flag set
   - Call `GuidelineRetriever` after chart evidence
   - Merge into bundle with attribution
3. Worker becomes one implementation of the port; orchestrator can use others

### Phase 5: Update VerifiedAgentHandler (2 hours)
1. Remove direct `ChartEvidenceCollector` wiring
2. Inject `EvidenceOrchestrator` instead
3. Build `AssemblyContext` in `handle()`
4. Call `orchestrator->assemble()`
5. Pass `EvidenceAssemblyResult` to drafting pipeline

### Phase 6: Verification (2 hours)
1. Run 50-case suite, verify evidence coverage for each case
2. Test deadline enforcement (artificially short deadlines)
3. Test policy selection (eager vs lazy produces different coverage)
4. Test guideline integration where expected

## SOLID/DRY Compliance

- **Single Responsibility**: Orchestrator = coordination only; strategies = collection only; policies = selection only
- **Open/Closed**: New collection strategy = new class implementing port; orchestrator unchanged
- **Liskov Substitution**: All strategies satisfy `EvidenceBundleAssemblyPort`
- **Interface Segregation**: Port is narrow (one method), policy is one enum
- **Dependency Inversion**: Orchestrator depends on port abstraction, not concrete collectors
- **DRY**: Deadline handling, timer management, coverage reporting in one place (orchestrator)

## Deep Module Characteristics

- **Public surface**: 1 method (`assemble`) with context DTO
- **Hidden implementation**:
  - Chart section selection (planner/selector)
  - Serial vs concurrent collection strategies
  - Tool invocation and failure handling
  - Prefetch policy enforcement
  - Deadline checking at sub-step granularity
  - Guideline retrieval and merge
  - Evidence deduplication
  - Coverage analysis (what's missing and why)
  - Timing telemetry assembly
  - Graceful degradation (partial results on deadline)

## Boundary Test Strategy

```php
// Test: Eager policy prefetches all sections
$orchestrator = new EvidenceOrchestrator($clock, $logger, $strategies);
$context = AssemblyContext::withPolicy($patient, $question, $plan, EvidencePolicy::Eager);
$result = $orchestrator->assemble($context);
$this->assertGreaterThanOrEqual(count($plan->sections), count($result->bundle->items));

// Test: Lazy policy only fetches planned
$context = AssemblyContext::withPolicy($patient, $question, $plan, EvidencePolicy::Lazy);
$result = $orchestrator->assemble($context);
// Assert: only planned sections attempted

// Test: Deadline enforcement
$deadline = new Deadline($clock, 1); // 1ms, already exceeded
$context = AssemblyContext::withDeadline($patient, $question, $plan, $deadline);
$result = $orchestrator->assemble($context);
$this->assertTrue($result->deadlineExceeded);
$this->assertNotEmpty($result->bundle->failedSections);

// Test: Guideline integration via EvidenceRetrieverWorker
$worker = new EvidenceRetrieverWorker($collector, $guidelineRetriever);
$run = $worker->assembleBundle($patient, $guidelineQuestion, $plan);
$this->assertTrue($run->bundle->hasGuidelineEvidence());
```

## Migration from Current Pattern

| Current | New |
|---------|-----|
| `VerifiedAgentHandler` wires `ChartEvidenceCollector` directly | Handler wires `EvidenceOrchestrator` |
| `SerialChartEvidenceCollector::collect()` public | `SerialEvidenceStrategy` internal to orchestrator |
| Deadline checked in collector | Deadline checked in orchestrator between strategy calls |
| Guideline retrieval inline in handler | Guideline via `EvidenceRetrieverWorker` port implementation |
| Coverage analysis scattered | `EvidenceCoverageReport` from orchestrator result |

## Risk Mitigation

- **Evidence gaps**: Coverage report explicitly states what was missing and why (deadline/policy/not found)
- **Performance regression**: Policy controls tradeoff; eager users get full coverage, lazy users get speed
- **Deadline handling bugs**: Centralized deadline checks with tests for edge cases (exact timeout, partial collection)
- **Guideline attribution lost**: Guideline evidence items carry source metadata same as chart evidence

## Rollback Plan

Feature flag `USE_EVIDENCE_ORCHESTRATOR` toggles between direct collector wiring and orchestrator. Golden cases validate both paths produce equivalent bundles. After 100% confidence, remove flag.

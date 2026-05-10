# Implementation Plan: Candidate 3 — Single Composition Root for Clinical-Document Eval vs Runtime

## Objective
Eliminate eval/production composition drift by introducing explicit ports (`ClinicalDocumentExtractionPort`) and a single composition root (`ClinicalDocumentExtractionAdapter`). This ensures the 50-case golden harness uses deterministic fixtures only, while runtime uses real providers, with no risk of cross-contamination.

## Acceptance Criteria
- [ ] `ClinicalDocumentExtractionPort` interface defined with narrow contract
- [ ] `ClinicalDocumentExtractionAdapter` implements port for both eval and runtime paths
- [ ] `forInMemoryEvalAndTest()` static factory eliminated from `AttachAndExtractTool`
- [ ] All 50 golden cases pass with fixture-only provider (no real API keys, no PHI)
- [ ] Runtime path still uses real OpenAI VLM + normalizers + identity repos
- [ ] No conditional logic inside `AttachAndExtractTool` for mode selection (moved to adapter)
- [ ] `MonotonicClock`/`ClockInterface` injected at composition root, not created internally

## Files to Create

```
src/AgentForge/Document/Extraction/Port/
├── ClinicalDocumentExtractionPort.php        # Interface
├── ClinicalDocumentExtractionAdapter.php     # Implementation
├── EvalExtractionContext.php                 # Immutable DTO for eval
└── RuntimeExtractionContext.php              # Immutable DTO for runtime
```

## Files to Modify

```
src/AgentForge/Document/
├── AttachAndExtractTool.php                  # Remove static eval factory, narrow ctor
└── Extraction/ExtractionProviderFactory.php    # Delegate to adapter for provider selection

tests/Tests/Isolated/AgentForge/
├── Eval/ClinicalDocument/Adapter/ClinicalDocumentExtractionAdapterTest.php
└── Document/AttachAndExtractToolTest.php       # Update to use adapter

agent-forge/scripts/
├── check-clinical-document-gate.sh           # Verify eval path uses fixtures only
```

## Implementation Order

### Phase 1: Define Ports and Contexts (1-2 hours)
1. Create `ClinicalDocumentExtractionPort` with methods:
   - `createExtractionTool(EvalContext $ctx): AttachAndExtractTool`
   - `createExtractionTool(RuntimeContext $ctx): AttachAndExtractTool`
2. Create immutable context DTOs:
   - `EvalContext`: fixture manifest path, clock, fixed identity repos
   - `RuntimeContext`: env config, http client, clock, real repos

### Phase 2: Implement Adapter (2-3 hours)
1. Implement `ClinicalDocumentExtractionAdapter`:
   - Eval path: constructs `FixtureExtractionProvider` only
   - Runtime path: constructs lazy `OpenAiVlmExtractionProvider` with normalizers
   - Both paths inject same storage/loader pattern, but providers differ
2. Move provider selection logic from `ExtractionProviderFactory` to adapter

### Phase 3: Refactor AttachAndExtractTool (1-2 hours)
1. Remove `forInMemoryEvalAndTest()` static method
2. Narrow constructor to require only:
   - `DocumentExtractionProvider $provider`
   - `SourceDocumentStorage $storage`
   - `DocumentLoader $loader`
   - `ClockInterface $clock`
   - Optional identity repos (null for eval)
3. All mode detection logic eliminated from tool class

### Phase 4: Update Eval Adapter and Tests (2-3 hours)
1. Update `ClinicalDocumentExtractionAdapter` (eval) to use new port
2. Update `AttachAndExtractToolTest` to construct via adapter
3. Ensure golden cases still pass with fixture provider only

### Phase 5: Update Runtime Wiring (1-2 hours)
1. Update `DocumentJobWorkerFactory` to use adapter for runtime
2. Update `DocumentUploadEnqueuerFactory` to use adapter
3. Verify runtime still constructs real OpenAI provider with proper timeouts/keys

### Phase 6: Verification (1 hour)
1. Run full 50-case golden suite: `composer run eval:clinical-documents`
2. Run runtime smoke test with real (test) PDF
3. Verify no real API keys reachable from eval path via static analysis

## SOLID/DRY Compliance

- **Single Responsibility**: Adapter owns composition; tool owns extraction only
- **Open/Closed**: New context types extend without changing adapter
- **Liskov Substitution**: Both eval and runtime tools satisfy same contract
- **Interface Segregation**: Port is narrow (one method per context type)
- **Dependency Inversion**: Tool depends on `DocumentExtractionProvider` abstraction, not concrete providers
- **DRY**: Provider selection logic lives in one place (adapter), not scattered across factories and static methods

## Boundary Test Strategy

Replace internal provider tests with boundary tests on adapter seam:

```php
// Test: Eval context never creates real provider
$adapter = new ClinicalDocumentExtractionAdapter();
$tool = $adapter->createExtractionTool(new EvalContext($manifest, $clock));
// Assert: No HTTP client, no API key usage, fixture provider only

// Test: Runtime context creates lazy provider with correct config
$tool = $adapter->createExtractionTool(new RuntimeContext($env, $http, $clock));
// Assert: LazyExtractionProvider wrapper, correct timeouts, normalizers registered
```

## Risk Mitigation

- **Eval contamination risk**: Static analysis check in CI ensures no `OpenAiVlmExtractionProvider` instantiation in eval path
- **Runtime breakage**: Feature flag + gradual rollout; existing tests catch provider config errors
- **Performance**: Lazy provider instantiation preserved; no eager HTTP client creation

## Rollback Plan

If issues arise, revert to `forInMemoryEvalAndTest()` static factory temporarily while fixing adapter implementation. Keep original factory methods as deprecated stubs during transition.

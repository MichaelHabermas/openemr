# Implementation Plan: Candidate 2 — Narrow the Drafting-Verification Façade

## Objective
Split the broad `VerifiedDraftingPipeline` into focused, deep modules: a narrow `DraftVerificationPipeline` (draft + verify only) and a separate `VerifiedAnswerAssembler` (response shaping). Eliminate duplicate verification logic and separate orchestration from output construction. Preserve all trust boundary guarantees.

## Acceptance Criteria
- [ ] `DraftVerificationPipeline` created with single public method: `draftAndVerify()`
- [ ] `DraftVerificationPair` immutable value object (draft + verification result)
- [ ] `VerifiedAnswerAssembler` interface for response construction
- [ ] `AnswerAssembler` implementation handles sections, citations, missing evidence, follow-up
- [ ] `VerifiedDraftingPipeline` refactored to thin wrapper delegating to assembler
- [ ] Duplicate `trustedFixtureResult()` logic eliminated
- [ ] `DraftVerifier` unchanged (pure verification stays separate)
- [ ] `FactuallyConsistentRubric` and `AnswerCitationCoverageRubric` pass for all 50 cases
- [ ] Refusal paths (known missing, unsafe, out-of-corpus) all route through assembler

## Files to Create

```
src/AgentForge/ResponseGeneration/
├── DraftVerificationPipeline.php               # Narrow pipeline
├── DraftVerificationPair.php                   # Immutable result pair
├── Assembler/
│   ├── VerifiedAnswerAssembler.php             # Interface
│   └── AnswerAssembler.php                     # Standard implementation
└── AssemblerSection/
    ├── CitationSectionBuilder.php              # Citation formatting
    ├── MissingEvidenceSectionBuilder.php       # Missing data messaging
    └── FollowUpSectionBuilder.php              # Follow-up special handling
```

## Files to Modify

```
src/AgentForge/
├── Handlers/
│   ├── VerifiedDraftingPipeline.php            # Refactor to thin wrapper
│   └── VerifiedAgentHandler.php                # Use new pipeline + assembler
├── Verification/
│   └── DraftVerifier.php                       # No changes (pure verification preserved)
├── ResponseGeneration/
    ├── DraftProvider.php                         # No changes (interface preserved)
    └── DraftResponse.php                         # No changes (used in pair)

tests/Tests/Isolated/AgentForge/
├── Handlers/DraftVerificationPipelineTest.php  # Boundary tests
├── ResponseGeneration/AnswerAssemblerTest.php  # Section/citation tests
└── Eval/Rubric/FactuallyConsistentRubricTest.php
```

## Implementation Order

### Phase 1: Define Narrow Pipeline and Pair (2 hours)
1. Create `DraftVerificationPair` value object:
   ```php
   final readonly class DraftVerificationPair {
       public function __construct(
           public DraftResponse $draft,
           public VerificationResult $result
       ) {}
   }
   ```
2. Create `DraftVerificationPipeline`:
   - Constructor: `DraftProvider $provider`, `DraftVerifier $verifier`, `ClockInterface $clock`
   - Single public method:
     ```php
     public function draftAndVerify(
         DraftRequest $request,
         EvidenceBundle $bundle,
         Deadline $deadline
     ): DraftVerificationPair;
     ```
   - Implementation:
     - Draft with retry (single method, no branching)
     - Verify result
     - Return pair (no response shaping yet)

### Phase 2: Define Assembler Interface (1 hour)
1. Create `VerifiedAnswerAssembler` interface:
   ```php
   interface VerifiedAnswerAssembler {
       public function assembleVerified(
           DraftResponse $draft,
           VerificationResult $result,
           EvidenceBundle $bundle,
           string $questionType
       ): AgentResponse;

       public function assembleRefusal(
           string $reason,
           EvidenceBundle $bundle,
           string $questionType
       ): AgentResponse;

       public function assembleKnownMissing(
           EvidenceBundle $bundle,
           array $missingSections,
           string $questionType
       ): AgentResponse;
   }
   ```

### Phase 3: Implement AnswerAssembler (3-4 hours)
1. Create `AnswerAssembler`:
   - Constructor: `CitationSectionBuilder`, `MissingEvidenceSectionBuilder`, `FollowUpSectionBuilder`
   - `assembleVerified()`:
     - Filter claims by verification status
     - Build citation details from verified claims
     - Format sections per question type
     - Handle follow-up special cases
   - `assembleRefusal()`:
     - Format refusal with appropriate tone
     - Include what was requested but not provided
   - `assembleKnownMissing()`:
     - List missing sections explicitly
     - Explain why answer is incomplete
2. Create builder classes:
   - `CitationSectionBuilder`: Format `citationDetails`, `source_ids`, page/quote display
   - `MissingEvidenceSectionBuilder`: Format missing sections gracefully
   - `FollowUpSectionBuilder`: Special handling for follow-up question types

### Phase 4: Refactor VerifiedDraftingPipeline (2 hours)
1. Narrow constructor to:
   - `DraftVerificationPipeline $verificationPipeline`
   - `VerifiedAnswerAssembler $assembler`
   - `ClockInterface $clock`
   - `LoggerInterface $logger`
2. Refactor `run()` method:
   - Handle `knownMissing` case early → `assembler->assembleKnownMissing()`
   - Handle empty bundle → `assembler->assembleRefusal()` or similar
   - Draft + verify via `verificationPipeline->draftAndVerify()`
   - On success → `assembler->assembleVerified()`
   - On failure → `assembler->assembleRefusal()` or retry
3. Remove `trustedFixtureResult()` (duplication eliminated)
4. Remove `toAgentResponse()` (moved to assembler)
5. Remove `sectionsFor()`, `citationDetails()`, `missingEvidenceLines()` (moved to builders)

### Phase 5: Update VerifiedAgentHandler (1-2 hours)
1. Update constructor to accept new pipeline type
2. No changes to `handle()` logic (still calls `pipeline->run()`)
3. Update composition root to wire:
   - `DraftVerificationPipeline` with provider/verifier
   - `AnswerAssembler` with builders
   - `VerifiedDraftingPipeline` with both

### Phase 6: Verification (2 hours)
1. Run 50-case suite:
   - `FactuallyConsistentRubric` (all claims verified against evidence)
   - `AnswerCitationCoverageRubric` (all claims have citations)
   - `FinalAnswerSectionsRubric` (proper section structure)
2. Test refusal paths:
   - Known missing data
   - Unsafe to echo (malicious input)
   - Out-of-corpus (guideline request no match)
3. Verify citation formatting unchanged (visual regression)

## SOLID/DRY Compliance

- **Single Responsibility**:
  - `DraftVerificationPipeline` = draft + verify only
  - `AnswerAssembler` = response construction only
  - Builder classes = one formatting concern each
- **Open/Closed**: New response format = new assembler implementation; pipeline unchanged
- **Liskov Substitution**: All assemblers satisfy `VerifiedAnswerAssembler`
- **Interface Segregation**: Assembler interface is narrow (3 methods), builders are single-purpose
- **Dependency Inversion**: Pipeline depends on `DraftProvider`/`DraftVerifier` abstractions; assembler is pluggable
- **DRY**: Verification logic lives in `DraftVerifier` only (no duplication in fixture path)

## Deep Module Characteristics

**`DraftVerificationPipeline`** (narrow façade):
- **Public surface**: 1 method, 3 constructor params
- **Hidden complexity**: retry logic, deadline enforcement, provider error handling, verifier coordination

**`AnswerAssembler`** (deep module):
- **Public surface**: 3 methods
- **Hidden complexity**:
  - Citation formatting with source attribution
  - Missing evidence messaging
  - Question-type-specific section layouts
  - Follow-up conversation handling
  - Safe refusal formatting
  - Known missing data explanation

**`VerifiedDraftingPipeline`** (thin wrapper):
- **Public surface**: `run()` method (same as now)
- **Implementation**: Delegates to pipeline + assembler (tiny surface, all complexity pushed down)

## Boundary Test Strategy

```php
// Test: Draft verification pair contains both results
$pipeline = new DraftVerificationPipeline($provider, $verifier, $clock);
$pair = $pipeline->draftAndVerify($request, $bundle, $deadline);
$this->assertInstanceOf(DraftResponse::class, $pair->draft);
$this->assertInstanceOf(VerificationResult::class, $pair->result);

// Test: Assembler builds response with citations
$assembler = new AnswerAssembler($citationBuilder, $missingBuilder, $followUpBuilder);
$response = $assembler->assembleVerified($draft, $result, $bundle, 'medication_review');
$this->assertStringContainsString('Citation:', $response->body);

// Test: Refusal includes reason
$response = $assembler->assembleRefusal('cross_patient_scope', $bundle, 'general');
$this->assertStringContainsString('unable to answer', $response->body);

// Test: Known missing lists sections
$response = $assembler->assembleKnownMissing($bundle, ['Labs', 'Allergies'], 'pre_visit_summary');
$this->assertStringContainsString('Labs', $response->body);
$this->assertStringContainsString('Allergies', $response->body);
```

## Migration of Current Code

| Current (VerifiedDraftingPipeline) | New Location |
|-----------------------------------|-------------|
| `draftWithOneRetry()` | `DraftVerificationPipeline::draftAndVerify()` |
| `trustedFixtureResult()` | Eliminated (use `DraftVerifier` directly) |
| `toAgentResponse()` | `AnswerAssembler::assembleVerified()` |
| `sectionsFor()` | `AnswerAssembler` + builder classes |
| `citationDetails()` | `CitationSectionBuilder` |
| `missingEvidenceLines()` | `MissingEvidenceSectionBuilder` |
| `unsafeToEcho()` | `AnswerAssembler::assembleRefusal()` |
| `knownMissingResponse()` | `AnswerAssembler::assembleKnownMissing()` |

## Risk Mitigation

- **Trust boundary leakage**: Assembler only receives already-verified drafts; no new verification paths introduced
- **Citation format regression**: `CitationSectionBuilder` unit tests match current formatting exactly
- **Refusal message changes**: All refusal paths tested in 50 cases; golden assertions catch tone drift
- **Performance**: No additional LLM calls; same draft/verify flow, just better separated

## Rollback Plan

Feature flag `USE_NARROW_PIPELINE` toggles between old monolithic `VerifiedDraftingPipeline` and new narrow pipeline + assembler. If rubrics fail, revert to monolithic while debugging assembler. After 100% confidence, remove flag.

## Post-Implementation Benefits

1. **Testability**: Draft/verify testable without response shaping; response shaping testable without LLM calls
2. **Composability**: New response formats (FHIR bundles, structured JSON) = new assembler implementations
3. **Trust verification**: Single point where verification results become user-visible (assembler boundary)
4. **Code navigation**: 700-line god class → 3 small focused classes + builders

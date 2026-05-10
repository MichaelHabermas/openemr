# Implementation Plan: Candidate 4 — Deep Document Extraction Subsystem Module

## Objective
Transform scattered document type handling (6+ types with repeated `instanceof`/`match` blocks) into a deep module: `DocumentExtractionModule` with narrow public API and internal `DocumentExtractionRegistry` for pluggable strategies. New document types add via single registration, not 5+ file edits.

## Acceptance Criteria
- [ ] `DocumentExtractionModule` created with single public method: `attachAndExtract()`
- [ ] `DocumentExtractionRegistry` manages type-to-strategy mappings
- [ ] `ExtractionStrategy` interface for per-type implementations
- [ ] All 6 existing types (lab_pdf, intake_form, hl7v2, fax_packet, clinical_workbook, referral_docx) registered
- [ ] New document type can be added via single `register()` call or new strategy class
- [ ] `instanceof`/`match` blocks eliminated from `AttachAndExtractTool` and `IntakeExtractorWorker`
- [ ] Schema validation, fact mapping, identity verification hidden behind registry
- [ ] 50-case golden suite still passes
- [ ] OCP satisfied: new type requires no changes to module core

## Files to Create

```
src/AgentForge/Document/Extraction/Module/
├── DocumentExtractionModule.php              # Deep module facade
├── DocumentExtractionRegistry.php              # Type-to-strategy registry
├── ExtractionStrategy.php                      # Strategy interface
├── ExtractionPipeline.php                      # Per-type pipeline value object
├── SchemaValidator.php                         # Encapsulated schema validation
└── Strategy/
    ├── LabPdfExtractionStrategy.php
    ├── IntakeFormExtractionStrategy.php
    ├── Hl7v2MessageExtractionStrategy.php
    ├── FaxPacketExtractionStrategy.php
    ├── ClinicalWorkbookExtractionStrategy.php
    └── ReferralDocxExtractionStrategy.php
```

## Files to Modify

```
src/AgentForge/Document/
├── AttachAndExtractTool.php                  # Delegate to module, remove type switches
├── Worker/IntakeExtractorWorker.php            # Use module instead of direct wiring
└── Extraction/
    ├── ExtractionProviderResponse.php          # Remove union type exposure
    └── *FactMapper.php files                   # Register as strategies instead

tests/Tests/Isolated/AgentForge/
├── Document/DocumentExtractionModuleTest.php
├── Document/DocumentExtractionRegistryTest.php
└── Document/ExtractionStrategyTest.php         # Base strategy contract tests
```

## Implementation Order

### Phase 1: Define Core Abstractions (2-3 hours)
1. Create `ExtractionStrategy` interface:
   - `supports(DocumentType $type): bool`
   - `extract(DocumentLoadResult $document, Deadline $deadline): ExtractionResult`
   - `getSchema(): ExtractionSchema`
   - `getMapper(): DocumentFactMapper`
2. Create `ExtractionPipeline` value object (immutable tuple of strategy + deps)
3. Create `DocumentExtractionRegistry`:
   - `register(DocumentType $type, ExtractionStrategy $strategy): void`
   - `getPipeline(DocumentType $type): ExtractionPipeline`
   - Throws typed exception for unsupported types

### Phase 2: Implement Strategy Classes (3-4 hours)
1. Convert each existing `*Extraction` + `*FactMapper` pair to strategy:
   - `LabPdfExtractionStrategy` wraps current lab provider + mapper
   - `IntakeFormExtractionStrategy` wraps intake logic
   - etc. for all 6 types
2. Each strategy encapsulates its own:
   - Content normalizer selection
   - Schema validation rules
   - Fact mapping logic
   - Citation building (page/quote/bounding-box)

### Phase 3: Build Deep Module Facade (2-3 hours)
1. Create `DocumentExtractionModule`:
   - Constructor: registry + clock + logger + optional identity repos
   - Single public method:
     ```php
     public function attachAndExtract(
         PatientId $patientId,
         string|DocumentId $source,
         DocumentType $docType,
         Deadline $deadline
     ): AttachAndExtractResult;
     ```
   - Internal orchestration: load → normalize → extract → validate → map → identity check → promote
2. Hide all complexity: no public methods for sub-steps

### Phase 4: Refactor Call Sites (2-3 hours)
1. Update `AttachAndExtractTool`:
   - Remove `match`/`switch` on `DocumentType`
   - Delegate entirely to `DocumentExtractionModule`
   - Narrow constructor to module + storage only
2. Update `IntakeExtractorWorker`:
   - Use module instead of direct provider/mapper wiring
   - Eliminate `countFactBuckets()` duplication
3. Update `DocumentJobWorkerFactory` to register strategies at bootstrap

### Phase 5: Migrate Registry Bootstrap (1-2 hours)
1. Create `DocumentExtractionBootstrap`:
   - Registers all 6 strategies with registry
   - Returns configured module instance
2. Update factories to use bootstrap instead of inline construction

### Phase 6: Verification (2 hours)
1. Run 50-case golden suite for all document types
2. Add boundary test: new type registration doesn't break existing
3. Verify no `instanceof` checks remain in production code (static analysis)

## SOLID/DRY Compliance

- **Single Responsibility**: Module = orchestration only; strategies = per-type logic; registry = mapping only
- **Open/Closed**: New type = new strategy class + one `register()` call
- **Liskov Substitution**: All strategies satisfy `ExtractionStrategy` contract
- **Interface Segregation**: Strategy interface is narrow (4 methods)
- **Dependency Inversion**: Module depends on `ExtractionStrategy` abstraction
- **DRY**: Single registry lookup replaces 6+ `match`/`instanceof` blocks across multiple files

## Deep Module Characteristics

- **Public surface**: 1 method (`attachAndExtract`) + 1 registration method
- **Hidden implementation**:
  - 6 strategy classes
  - Registry with type mapping
  - Schema validation across 6 formats
  - Fact mapping for each type
  - Identity verification
  - Promotion/upsert logic
  - Citation building (page/quote/bounding-box)
  - Content normalization (PDF/TIFF/DOCX/XLSX/HL7v2)
  - Deadline enforcement
  - Telemetry/logging

## Boundary Test Strategy

```php
// Test: New document type registration
$registry = new DocumentExtractionRegistry();
$registry->register(DocumentType::LabPdf, new LabPdfExtractionStrategy(...));
$module = new DocumentExtractionModule($registry, $clock);
$result = $module->attachAndExtract($patientId, $path, DocumentType::LabPdf, $deadline);
// Assert: result has extraction with facts, citations, source document stored

// Test: Unsupported type throws typed exception
$this->expectException(UnsupportedDocumentTypeException::class);
$module->attachAndExtract($patientId, $path, DocumentType::from('unknown'), $deadline);

// Test: OCP - new type without modifying module
$registry->register(DocumentType::Custom, new CustomStrategy());
// Module works without code changes
```

## Migration Path for Existing Types

| Current Pattern | New Pattern |
|---------------|-------------|
| `LabPdfExtraction` + `LabPdfFactMapper` | `LabPdfExtractionStrategy` implements both |
| `instanceof LabPdfExtraction` check | `$registry->getPipeline(LabPdf)->strategy` |
| `match ($type)` in worker | `$module->attachAndExtract(..., $type, ...)` |
| Direct provider wiring | Strategy encapsulates provider selection |
| Direct mapper instantiation | Strategy provides mapper via `getMapper()` |

## Risk Mitigation

- **Regression in extraction quality**: Each strategy gets unit tests matching current behavior
- **Performance degradation**: Lazy provider instantiation preserved within strategies
- **Schema drift**: Schema validation centralized in `SchemaValidator`, tested against golden fixtures
- **Identity verification gaps**: Identity logic moved to module level, not per-strategy, to ensure consistent dedup

## Rollback Plan

Feature flag `USE_DEEP_MODULE` toggles between old switch-based code and new module. If issues, revert to flag=false. After 100% confidence, remove flag and old code.

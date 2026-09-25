# Roadmap: LG ThinQ Sync — Optimierung

## Overview

Four phases transform the module library from God-class territory into maintainable, standards-compliant PHP. Phase 1 extracts shared helpers into a trait (prerequisite for all size reductions). Phase 2 deletes dead code while the files are already open. Phase 3 splits the 2,585-line CapabilityEngine and gets every file under 500 lines. Phase 4 runs a final syntax and interface-integrity sweep to confirm zero regressions.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Shared Trait** - Extract duplicated helper methods into `ThinQModuleTrait` and integrate it into Bridge and Device (completed 2026-03-27)
- [x] **Phase 2: Dead Code Removal** - Delete empty placeholders, unused helpers, and the unreferenced error-codes class
- [x] **Phase 3: CapabilityEngine Split** - Break the 2,585-line God class into per-device-type handler files with clear internal responsibility separation (completed 2026-03-27)
- [ ] **Phase 4: QA Validation** - Confirm syntax passes `php -l` on every changed file and public interfaces are identical to pre-refactoring

## Phase Details

### Phase 1: Shared Trait
**Goal**: A single `ThinQModuleTrait` exists and is the sole source of shared helper methods in both Bridge and Device
**Depends on**: Nothing (first phase)
**Requirements**: TRAIT-01, TRAIT-02, TRAIT-03
**Success Criteria** (what must be TRUE):
  1. `ThinQModuleTrait.php` exists with `t()`, `isKernelReady()`, `findModuleGUIDByName()` and all other duplicated helpers
  2. `LGThinQBridge` uses `ThinQModuleTrait` — its own copies of those helpers are gone
  3. `LGThinQDevice` uses `ThinQModuleTrait` — its own copies of those helpers are gone
  4. `php -l` passes on `ThinQModuleTrait.php`, `LG ThinQ Bridge/module.php`, and `LG ThinQ Device/module.php`
**Plans**: 1 plan

Plans:
- [x] PLAN.md — Extract ThinQModuleTrait, integrate into Bridge and Device

### Phase 2: Dead Code Removal
**Goal**: Every identifiable dead code unit is deleted — no empty placeholders, no unused methods, no unreferenced classes
**Depends on**: Phase 1
**Requirements**: DEAD-01, DEAD-02, DEAD-03
**Success Criteria** (what must be TRUE):
  1. `updateFromStatus()` no longer exists in `LG ThinQ Device/module.php`
  2. `flattenKeys()` and `flattenKeysRecursive()` no longer exist anywhere in the codebase
  3. `ThinQApiErrorCodes` is either wired into `ThinQHttpClient` retry logic or the class file is deleted — no middle state
  4. `php -l` passes on every file touched in this phase
**Plans**: 1 plan

Plans:
- [x] 02-P01-PLAN.md — Delete updateFromStatus, flattenKeys/flattenKeysRecursive, and ThinQApiErrorCodes

### Phase 3: CapabilityEngine Split
**Goal**: CapabilityEngine's logic lives in per-device-type handler files, each under 500 lines, with profile-parsing, plan-building, and variable-registration as distinct responsibilities
**Depends on**: Phase 1
**Requirements**: CAPE-01, CAPE-02, CAPE-03
**Success Criteria** (what must be TRUE):
  1. `CapabilityEngine.php` no longer exceeds 500 lines (acts as dispatcher or is replaced by handler files)
  2. Each device-type handler file (Washer, Fridge, AC, etc.) is under 500 lines
  3. Within each handler, profile-parsing, plan-building, and variable-registration are implemented as separate, clearly named methods or classes
  4. `LG ThinQ Bridge/module.php` and `LG ThinQ Device/module.php` are each under 500 lines
  5. `php -l` passes on all new and modified files
**Plans**: TBD

### Phase 4: QA Validation
**Goal**: Every changed file passes syntax validation and the public interface contract is confirmed unchanged
**Depends on**: Phase 3
**Requirements**: QA-01, QA-02
**Success Criteria** (what must be TRUE):
  1. `php -l` runs clean (exit 0) on every file modified across all phases with no errors or warnings
  2. All `LGTQ_`, `LGTQD_`, and `LGTQC_` prefixed public methods have identical signatures to the pre-refactoring state
  3. All module GUIDs, `parentRequirements`, `childRequirements`, and `implemented` entries in `module.json` files are unchanged
  4. All IP-Symcon variable Idents registered by `MaintainVariable` / `RegisterVariable*` are identical to pre-refactoring
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Shared Trait | 1/1 | Complete | 2026-03-27 |
| 2. Dead Code Removal | 1/1 | Complete | 2026-03-27 |
| 3. CapabilityEngine Split | 1/1 | Complete   | 2026-03-27 |
| 4. QA Validation | 0/TBD | Not started | - |

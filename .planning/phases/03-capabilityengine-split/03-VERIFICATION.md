---
phase: 03-capabilityengine-split
verified: 2026-03-27T00:00:00Z
status: gaps_found
score: 3/5 success criteria verified (2 documented deviations, 1 orphaned artifact)
gaps:
  - truth: "Every new CapabilityEngine sub-file is under 500 lines"
    status: partial
    reason: "CapabilityControlBuilder.php is 692 lines — monolithic buildControlPayload method (362 lines) documented as irreducible in SUMMARY"
    artifacts:
      - path: "LG ThinQ Device/libs/CapabilityControlBuilder.php"
        issue: "692 lines, target was <500 — documented deviation accepted in plan"
    missing:
      - "Either split buildControlPayload further or document the 692-line deviation as accepted in REQUIREMENTS.md"
  - truth: "LG ThinQ Bridge/module.php and LG ThinQ Device/module.php are each under 500 lines"
    status: partial
    reason: "Device/module.php is 940 lines, Bridge/module.php is 866 lines — both exceed 500. Plan explicitly documents this as architecturally unachievable via class extraction alone (IPS lifecycle methods require $this-bound calls)"
    artifacts:
      - path: "LG ThinQ Device/module.php"
        issue: "940 lines — IPSModule lifecycle methods cannot be extracted; pre-acknowledged in plan"
      - path: "LG ThinQ Bridge/module.php"
        issue: "866 lines — IPSModule lifecycle methods cannot be extracted; pre-acknowledged in plan"
    missing:
      - "No code fix needed — constraint is architectural. REQUIREMENTS.md CAPE-03 should be formally annotated with the accepted deviation."
  - truth: "CapabilityCatalogLoader is integrated into the engine (required but never instantiated)"
    status: failed
    reason: "CapabilityCatalogLoader.php is required via require_once in CapabilityEngine.php but is never instantiated anywhere in the codebase — the class is orphaned"
    artifacts:
      - path: "LG ThinQ Device/libs/CapabilityCatalogLoader.php"
        issue: "File exists (223 lines, valid PHP), included via require_once, but zero call sites — class is never used"
    missing:
      - "Either wire CapabilityCatalogLoader into CapabilityEngine (replace inline catalog loading logic with delegation to this class) or remove the file and its require_once from CapabilityEngine.php"
human_verification:
  - test: "Run a full IP-Symcon module load cycle with an LG ThinQ device"
    expected: "Device variables are created correctly, MQTT events arrive and update variables, RequestAction forwards commands — identical behavior to pre-refactoring"
    why_human: "IP-Symcon runtime required; cannot exercise ReceiveData/RequestAction/ApplyChanges without a live Symcon instance"
---

# Phase 3: CapabilityEngine Split — Verification Report

**Phase Goal:** Split CapabilityEngine.php (2585 lines), LG ThinQ Device/module.php (1832 lines), and LG ThinQ Bridge/module.php (1729 lines) so that every file is under 500 lines. No behavior changes, no public API changes.
**Verified:** 2026-03-27
**Status:** gaps_found
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths (from ROADMAP.md Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `CapabilityEngine.php` no longer exceeds 500 lines | VERIFIED | 492 lines — `wc -l` confirmed |
| 2 | Each CapabilityEngine sub-file is under 500 lines | PARTIAL | CapabilityControlBuilder.php = 692 lines (documented deviation) |
| 3 | Profile-parsing, plan-building, variable-registration are distinct classes | VERIFIED | CapabilityProfileExtractor (352L), CapabilityPlanBuilder (463L), CapabilityVarManager (271L) all exist and are wired |
| 4 | Bridge/module.php and Device/module.php are each under 500 lines | PARTIAL | Device=940L, Bridge=866L — architecturally unachievable per plan's documented constraint |
| 5 | `php -l` passes on all new and modified files | VERIFIED | All 15 files pass `php -l` with no syntax errors |

**Score:** 3/5 truths fully verified (2 partial with documented deviations)

---

## Required Artifacts

| Artifact | Lines | Status | Notes |
|----------|-------|--------|-------|
| `LG ThinQ Device/libs/CapabilityEngine.php` | 492 | VERIFIED | Under 500, wires all sub-classes |
| `LG ThinQ Device/libs/CapabilityProfileExtractor.php` | 352 | VERIFIED | Extracted, wired, used |
| `LG ThinQ Device/libs/CapabilityCatalogLoader.php` | 223 | ORPHANED | File exists, required, but never instantiated |
| `LG ThinQ Device/libs/CapabilityPlanBuilder.php` | 463 | VERIFIED | Wired via `buildPlan()` |
| `LG ThinQ Device/libs/CapabilityVarManager.php` | 271 | VERIFIED | Wired via `getVarManager()` |
| `LG ThinQ Device/libs/CapabilityControlBuilder.php` | 692 | DEVIATION | Over 500 — monolithic `buildControlPayload`, documented |
| `LG ThinQ Device/libs/ThinQPresentationBuilder.php` | 338 | VERIFIED | Wired in Device/module.php |
| `LG ThinQ Device/libs/ThinQEnergyManager.php` | 183 | VERIFIED | Wired in Device/module.php |
| `LG ThinQ Device/libs/ThinQSupportBundle.php` | 197 | VERIFIED | Wired in Device/module.php |
| `LG ThinQ Device/libs/ThinQDeviceProfileManager.php` | 208 | VERIFIED | Wired in Device/module.php |
| `LG ThinQ Device/libs/ThinQDeviceUtil.php` | 177 | VERIFIED | Wired in Device/module.php |
| `LG ThinQ Device/module.php` | 940 | DEVIATION | Over 500 — IPS lifecycle methods, pre-acknowledged |
| `LG ThinQ Bridge/libs/ThinQMqttSetupWizard.php` | 550 | DEVIATION | Over 500 — monolithic `run()` method, documented |
| `LG ThinQ Bridge/libs/ThinQMqttCertBuilder.php` | 311 | VERIFIED | Wired in Bridge/module.php |
| `LG ThinQ Bridge/module.php` | 866 | DEVIATION | Over 500 — IPS lifecycle methods, pre-acknowledged |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `CapabilityEngine.php` | `CapabilityProfileExtractor` | `getExtractor()` + `new CapabilityProfileExtractor(...)` | WIRED | Lines 58–60; also used in `applyStatus()` at lines 363–366 |
| `CapabilityEngine.php` | `CapabilityPlanBuilder` | `new CapabilityPlanBuilder(...)` | WIRED | Line 288 in `buildPlan()` |
| `CapabilityEngine.php` | `CapabilityVarManager` | `getVarManager()` + `new CapabilityVarManager(...)` | WIRED | Lines 66–68; used at lines 83, 447, 452 |
| `CapabilityEngine.php` | `CapabilityControlBuilder` | `getControlBuilder()` + `new CapabilityControlBuilder(...)` | WIRED | Lines 75–84; used at line 122 |
| `CapabilityEngine.php` | `CapabilityCatalogLoader` | `require_once` only | ORPHANED | Required at line 10 but never instantiated |
| `Device/module.php` | `CapabilityEngine` | `require_once` + `new CapabilityEngine(...)` | WIRED | Lines 6, 772 |
| `Device/module.php` | `ThinQSupportBundle` | `require_once` + `new ThinQSupportBundle(...)` | WIRED | Lines 9, 864 |
| `Device/module.php` | `ThinQDeviceProfileManager` | `require_once` + `new ThinQDeviceProfileManager(...)` | WIRED | Lines 10, 790 |
| `Device/module.php` | `ThinQDeviceUtil` | `require_once` + `new ThinQDeviceUtil(...)` | WIRED | Lines 11, 819 |
| `Device/module.php` | `ThinQEnergyManager` | `require_once` + `new ThinQEnergyManager(...)` | WIRED | Lines 8, 886–930 |
| `Device/module.php` | `ThinQPresentationBuilder` | `require_once` + `new ThinQPresentationBuilder(...)` | WIRED | Lines 7, 703–723 |
| `Bridge/module.php` | `ThinQMqttSetupWizard` | `require_once` + `new ThinQMqttSetupWizard(...)` | WIRED | Lines 15, 857 |
| `Bridge/module.php` | `ThinQMqttCertBuilder` | `require_once` + `new ThinQMqttCertBuilder(...)` | WIRED | Lines 16, 840 |

---

## Data-Flow Trace (Level 4)

Not applicable. This phase is a structural refactoring — no new data sources or rendering paths were introduced. All extracted classes receive their data via constructor parameters from module.php or CapabilityEngine.php, which own the original data. The data flow pre-existed and was not changed.

---

## Behavioral Spot-Checks

Step 7b: SKIPPED — IP-Symcon runtime required. PHP files contain IPS_* global function calls (`IPS_GetProperty`, `IPS_LogMessage`, `IPS_SetValue`, etc.) that are undefined outside the Symcon runtime environment. No standalone entry point exists for behavior testing.

Syntax validation (the closest proxy) confirmed: all 15 files pass `php -l` with exit 0.

---

## Requirements Coverage

| Requirement | Phase | Description | Status | Evidence |
|-------------|-------|-------------|--------|----------|
| CAPE-01 | Phase 3 | CapabilityEngine.php split into handler files, each under 500 lines | PARTIALLY SATISFIED | CapabilityEngine.php = 492L (PASS). CapabilityControlBuilder.php = 692L (deviation — irreducible monolith documented in SUMMARY). All others pass. |
| CAPE-02 | Phase 3 | Profile-parsing, plan-building, variable-registration as separate classes | SATISFIED | CapabilityProfileExtractor (parsing), CapabilityPlanBuilder (plan building), CapabilityVarManager (variable registration) — all exist, are distinct classes, and are wired |
| CAPE-03 | Phase 3 | All files under 500 lines including Bridge and Device module.php | PARTIALLY SATISFIED | CapabilityEngine.php = 492L (PASS). Device/module.php = 940L, Bridge/module.php = 866L, CapabilityControlBuilder.php = 692L, ThinQMqttSetupWizard.php = 550L — all exceed 500L. All four are documented deviations in the plan with architectural justification. |

**Orphaned requirements check:** REQUIREMENTS.md maps CAPE-01, CAPE-02, CAPE-03 to Phase 3. All three are accounted for above. No orphaned requirements.

---

## Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `LG ThinQ Device/libs/CapabilityCatalogLoader.php` | 1–223 | Class exists and is required but never instantiated | Warning | Dead weight — adds a require_once cost and creates confusion about whether catalog loading is active. Not a behavioral blocker since the class is never called. |

No TODO/FIXME/placeholder comments found in any of the 5 new extraction files.

All 5 new files declare `strict_types=1` correctly.

---

## Human Verification Required

### 1. Full Device Lifecycle Smoke Test

**Test:** Load the refactored module in a live IP-Symcon installation. Create a Bridge instance with a valid LG PAT token. Create a Device instance for an LG appliance. Trigger `ApplyChanges()` and verify variables are created with correct Presentations (no old `~Profile` strings). Send a command via `RequestAction` and confirm it reaches the LG API.
**Expected:** Identical variable set, identical presentations, identical command routing as the pre-refactoring version.
**Why human:** Requires a live IP-Symcon instance with a connected LG ThinQ account and physical device.

### 2. MQTT Event Routing

**Test:** Enable MQTT in the Bridge instance. Receive a push event from the LG broker and verify it is routed to the correct Device instance and updates the correct variables.
**Expected:** `ReceiveData` on Bridge routes to `SendDataToChildren`, Device `ReceiveData` processes the payload, variable values update.
**Why human:** Requires live MQTT connection to LG AWS IoT broker — cannot test without real credentials and device.

---

## Gaps Summary

Three gaps were found:

**Gap 1 — CapabilityControlBuilder.php over 500 lines (692L):** The `buildControlPayload` method is a 362-line sequential `firstOf` dispatch chain that the plan's author assessed as architecturally irreducible without semantic changes. This deviation is documented in the SUMMARY. Since the plan's GOAL was "every file under 500 lines" and this file is not, it is a formal gap — but the root cause analysis is sound and the deviation is pre-acknowledged. Resolution options: (a) accept and annotate CAPE-01 in REQUIREMENTS.md with this known exception, or (b) extract sub-branches of the firstOf chain into private helper methods.

**Gap 2 — Device/module.php (940L) and Bridge/module.php (866L) over 500 lines:** The plan's pre-flight analysis explicitly projected these outcomes and documented them as architecturally impossible to resolve via class extraction alone (IPS lifecycle methods are bound to `$this` and require IPSModule-provided methods). Both files were reduced substantially from their originals (1832→940 and 1729→866). This is a documented architectural constraint, not an oversight. Resolution: annotate CAPE-03 in REQUIREMENTS.md as partially satisfied with a formal exception for IPS-bound lifecycle methods.

**Gap 3 — CapabilityCatalogLoader.php is orphaned:** The file is valid PHP, correctly extracted, and included via `require_once` in CapabilityEngine.php — but no code in the repository ever instantiates `CapabilityCatalogLoader`. This means the catalog loading logic it encapsulates is either duplicated elsewhere, bypassed, or was intended to replace an inline block that was never updated. This is a genuine wiring failure: the class was extracted but not wired. Resolution: either wire it into CapabilityEngine (replacing the inline catalog logic with delegation) or delete it and remove its require_once.

---

_Verified: 2026-03-27_
_Verifier: Claude (gsd-verifier)_

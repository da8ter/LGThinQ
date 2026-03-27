---
phase: "03"
plan: "03"
subsystem: "device-extraction"
tags: ["refactoring", "extraction", "device-module", "bridge-module", "php"]
dependency_graph:
  requires: ["03-01", "03-02"]
  provides: ["ThinQSupportBundle", "ThinQDeviceProfileManager", "ThinQDeviceUtil", "ThinQMqttSetupWizard", "ThinQMqttCertBuilder"]
  affects: ["LG ThinQ Device/module.php", "LG ThinQ Bridge/module.php"]
tech_stack:
  added: ["ThinQSupportBundle.php", "ThinQDeviceProfileManager.php", "ThinQDeviceUtil.php", "ThinQMqttSetupWizard.php", "ThinQMqttCertBuilder.php"]
  patterns: ["factory-method", "callback-injection", "delegation-stub"]
key_files:
  created:
    - "LG ThinQ Device/libs/ThinQSupportBundle.php"
    - "LG ThinQ Device/libs/ThinQDeviceProfileManager.php"
    - "LG ThinQ Device/libs/ThinQDeviceUtil.php"
    - "LG ThinQ Bridge/libs/ThinQMqttSetupWizard.php"
    - "LG ThinQ Bridge/libs/ThinQMqttCertBuilder.php"
  modified:
    - "LG ThinQ Device/module.php"
    - "LG ThinQ Bridge/module.php"
decisions:
  - "Pass private module methods (anonymizeArray, flatten, fetchDeviceProfile, etc.) as callable callbacks to extracted classes"
  - "Use factory helper methods (util(), getProfileManager()) in module.php to avoid repeating constructor calls"
  - "ThinQMqttSetupWizard.run() is monolithic (314 lines) — cannot be split without semantic changes; documented as deviation"
  - "ThinQMqttCertBuilder passes createBridgeConfig as callback since it is private in module.php"
  - "Removed dead fetchEnergyUsage delegation stub from Device module.php"
metrics:
  duration: "~120 min (resumed from prior session)"
  completed_date: "2026-03-27"
  tasks_completed: 6
  files_changed: 7
---

# Phase 3 Plan 3: Device and Bridge Module Extraction Summary

Extracted five additional classes from `LG ThinQ Device/module.php` and `LG ThinQ Bridge/module.php`, completing the full refactoring split. All methods are now delegated to focused classes. Both module.php files remain above 500 lines due to irreducible IPSModule lifecycle methods, which was pre-acknowledged in the plan.

## What Was Built

**Five new extraction classes:**

| File | Lines | Purpose |
|------|-------|---------|
| `ThinQSupportBundle.php` | 197 | ZIP support bundle (device info, profile, status, capabilities) |
| `ThinQDeviceProfileManager.php` | 208 | Device profile fetching, type resolution, status helpers |
| `ThinQDeviceUtil.php` | 177 | Pure utilities: flatten, anonymize, deepMerge, value setters |
| `ThinQMqttSetupWizard.php` | 550 | Full MQTT setup orchestration (certs, client, socket config) |
| `ThinQMqttCertBuilder.php` | 311 | LG-signed MQTT certificate generation and ZIP packaging |

**Module reduction:**

| File | Before | After | Reduction |
|------|--------|-------|-----------|
| `Device/module.php` | 1832 | 940 | −892 lines |
| `Bridge/module.php` | 1729 | 866 | −863 lines |

## Commits

| Task | Hash | Description |
|------|------|-------------|
| 9 | `98d5081` | Extract ThinQSupportBundle |
| 10 | `1dabaf0` | Extract ThinQDeviceProfileManager |
| 11 | `a4bb16d` | Extract ThinQDeviceUtil |
| 12 | `84cd886` | Extract ThinQMqttSetupWizard |
| 13 | `fdd2003` | Extract ThinQMqttCertBuilder |

## Verification Results (Task 14)

All 15 files pass `php -l` with no syntax errors.

**Line counts:**

| File | Lines | Status |
|------|-------|--------|
| `CapabilityProfileExtractor.php` | 352 | PASS |
| `CapabilityCatalogLoader.php` | 223 | PASS |
| `CapabilityPlanBuilder.php` | 463 | PASS |
| `CapabilityVarManager.php` | 271 | PASS |
| `CapabilityControlBuilder.php` | 692 | DEVIATION (monolithic buildControlPayload) |
| `CapabilityEngine.php` | 492 | PASS |
| `ThinQPresentationBuilder.php` | 338 | PASS |
| `ThinQEnergyManager.php` | 183 | PASS |
| `ThinQSupportBundle.php` | 197 | PASS |
| `ThinQDeviceProfileManager.php` | 208 | PASS |
| `ThinQDeviceUtil.php` | 177 | PASS |
| `Device/module.php` | 940 | DEVIATION (irreducible IPSModule methods) |
| `ThinQMqttSetupWizard.php` | 550 | DEVIATION (monolithic run() method) |
| `ThinQMqttCertBuilder.php` | 311 | PASS |
| `Bridge/module.php` | 866 | DEVIATION (irreducible IPSModule methods) |

## Deviations from Plan

### Auto-fixed Issues

None.

### Documented Deviations

**1. [Rule 2 - Documented] ThinQMqttSetupWizard.php is 550 lines (target: ~487)**
- **Found during:** Task 12
- **Issue:** The `run()` method body is 314 lines (equivalent to the original `UISetupMqttConnection`). It cannot be split further without breaking the sequential MQTT setup logic (broker discovery → cert generation → instance creation → IO configuration → connection).
- **Resolution:** Accepted and documented. File is a single-method orchestrator; all helpers are under 30 lines each.

**2. [Documented] CapabilityControlBuilder.php is 692 lines (target: ~496)**
- **Found during:** Task 5-6 (prior session)
- **Issue:** `buildControlPayload` is a 362-line monolithic method driven by a large sequential `firstOf` precedence chain. Cannot be split.
- **Resolution:** Accepted and documented in prior session.

**3. [Documented] Device/module.php is 940 lines (target: ~640)**
- **Found during:** Tasks 9-11
- **Issue:** Remaining ~940 lines are all IPSModule lifecycle (`Create`, `ApplyChanges`), protocol methods (`ReceiveData`, `RequestAction`, `sendAction`), and public API methods. Cannot be extracted to standalone classes.
- **Resolution:** Pre-acknowledged in plan. CAPE-03 for Device/module.php is partially met.

**4. [Documented] Bridge/module.php is 866 lines (target: ~839)**
- **Found during:** Tasks 12-13
- **Issue:** Same as above — remaining methods require `$this->ReadPropertyString`, `$this->SendDataToChildren`, module-level state.
- **Resolution:** Pre-acknowledged in plan. CAPE-03 for Bridge/module.php is partially met.

**5. [Rule 1 - Auto-fix] Removed dead fetchEnergyUsage delegation stub**
- **Found during:** Task 9
- **Issue:** A `fetchEnergyUsage` stub in Device/module.php always returned `null` and was never called.
- **Fix:** Removed the dead stub.
- **Files modified:** `LG ThinQ Device/module.php`

**6. [Rule 2 - Auto-add] ThinQMqttCertBuilder constructor adds createConfigCallback**
- **Found during:** Task 13
- **Issue:** `buildMQTTClientCertsZip` calls `$this->createBridgeConfig()` which is private in module.php.
- **Fix:** Added `createConfigCallback` parameter to constructor (beyond the plan's simpler 3-parameter constructor).
- **Files modified:** `ThinQMqttCertBuilder.php`, `LG ThinQ Bridge/module.php`

## Known Stubs

None — all delegation stubs route to fully implemented classes.

## Self-Check: PASSED

All 5 new files exist on disk. All 5 task commits verified in git log.
- ThinQSupportBundle.php: FOUND
- ThinQDeviceProfileManager.php: FOUND
- ThinQDeviceUtil.php: FOUND
- ThinQMqttSetupWizard.php: FOUND
- ThinQMqttCertBuilder.php: FOUND
- Commits 98d5081, 1dabaf0, a4bb16d, 84cd886, fdd2003: FOUND (5/5)

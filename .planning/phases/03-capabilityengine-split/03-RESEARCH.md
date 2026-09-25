# Phase 3: CapabilityEngine Split - Research

**Researched:** 2026-03-27
**Domain:** PHP class decomposition / IP-Symcon module architecture
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**CAPE-01: CapabilityEngine-Split-Strategie**
- D-01: Responsibility-based split (not per-device-type)
- D-02: `CapabilityEngine.php` becomes thin coordinator (~150 lines); public API stays identical: `buildPlan()`, `applyStatus()`, `ensureVariables()`, `buildControlPayload()`, `getDescriptors()`, `getPresentationMap()`, `reassertActionsOnSetup()`, `listIdentsToEnableOnSetup()`, `listIdentsToEnable()`, `setMaintainVariableCallback()`, `setTranslateCallback()`, `loadCapabilities()`
- D-03: `CapabilityProfileExtractor.php` — extract*, humanize*, getBestLabel methods
- D-04: `CapabilityCatalogLoader.php` — resolveCapabilityFiles, loadCatalog, catalogRuleMatches, catalogRuleMatchesDeviceOnly, matchesCondition
- D-05: `CapabilityPlanBuilder.php` — buildPlan, convertAutoPlanToCapability, inferWriteConfig, applyCompanionPatterns, addCompanionFromStatus, addCompanionConst, addEnumMapCompanionConst
- D-06: `CapabilityVarManager.php` — ensureVariables, reassertActionsOnSetup, listIdentsToEnableOnSetup, listIdentsToEnable, enableAction, readValue, getFromFlat, setValueByType, convertValueForType, replaceTemplatePlaceholders, walkReplace, findRangeFromProfile, findArrayIndex, collectArrayIndices
- D-07: `CapabilityControlBuilder.php` — buildControlPayload, applyCoSendFromStatus, applyCoSendConst, getTimerPairValue, getVariableValue, getVariableValueMixed, ipsTypeToCapType
- D-08: Coordinator retains: Properties, `__construct`, `setMaintainVariableCallback`, `setTranslateCallback`, `translate`, `debugEnabled`, `dbg`, `capHasWriteDefinition`, `loadCapabilities`, `applyStatus`, `resetDeactivatedTimers`, `getDescriptors`, `getPresentationMap`, `getParser`, `getVarId`, `flatten`, `setByPath`, `shouldCreate`, `shouldEnableAction`, `profileHasWriteAny`, `modeHasW`, `flatProfileHasAny`, `flatProfileIsWriteable`

**CAPE-03: Device/module.php split**
- D-09: `ThinQPresentationBuilder.php` — applyPresentation(), translatePresentationPayload(), applyProfileFallback()
- D-10: `ThinQEnergyManager.php` — fetchEnergyProfile, getEnergyProperties, setupEnergyVariables, scheduleEnergyTimer, UpdateEnergy, fetchEnergyUsage
- D-11: `ThinQSupportBundle.php` — UIExportSupportBundle(), buildSupportBundleZip()

**CAPE-03: Bridge/module.php split**
- D-12: `ThinQMqttSetupWizard.php` — UISetupMqttConnection() + private helpers used exclusively by it: generateMqttClientCertMaterial, extractBrokerFromSubscriptions, fetchRouteBroker, ensureCertificatePEM, ensurePrivateKeyPEM, extractCAPEMFromSubscriptions, downloadAmazonRootCA1

**Include chain**
- D-13: All new files via `require_once __DIR__ . '/libs/FileName.php'` — no Composer, no autoloader
- D-14: All new classes: `declare(strict_types=1)`, no IPSModule inheritance, constructor with required dependencies

### Claude's Discretion
- Exact method boundaries inside new files (planner decides by line count, as long as every file stays under 500 lines)
- Whether shouldCreate, shouldEnableAction etc. stay in VarManager or Coordinator (planner decides by line count)
- Whether ThinQMqttSetupWizard needs further splitting if >500 lines

### Deferred Ideas (OUT OF SCOPE)
- Autoloader / Composer
- Interface definition for CapabilityEngine
- Per-device-type PHP handlers
</user_constraints>

---

## Summary

Phase 3 splits three oversized files (CapabilityEngine.php at 2585 lines, Device/module.php at 1832 lines, Bridge/module.php at 1729 lines) into smaller, single-responsibility files. The architectural decisions are locked in CONTEXT.md. The research task is to verify actual line counts, map method dependencies across the planned new classes, identify all shared instance state each class requires, and confirm whether the planned split produces files that are all safely under 500 lines.

The overall split strategy is sound. The one identified risk is `CapabilityControlBuilder.php` (the `buildControlPayload` method alone spans lines 1074–1435, approximately 362 lines of dense logic). After adding the class header, constructor, and helper methods assigned to it (7 additional methods), the total approaches 500 lines. Precise counting is provided below.

`ThinQMqttSetupWizard.php` is the second risk area. The CONTEXT.md already flagged it as potentially ~500 lines. The verified range (UISetupMqttConnection lines 1168–1488 = 320 lines, plus helper methods lines 1493–1677 = 184 lines) totals approximately 504 lines of extracted code. The class wrapper, properties, and constructor add roughly 15 lines, pushing the file to ~520 lines. A secondary split is required.

**Primary recommendation:** Extract as planned, but split CapabilityControlBuilder's `buildControlPayload` into a private `dispatchWriteStrategy()` method that handles the enumMap / template / attribute / multiAttribute / firstOf branches individually, and split ThinQMqttSetupWizard into a main class + a `ThinQMqttIoConfigurator.php` helper (~100 lines) for the socket configuration and PEM-persistence logic (lines 1279–1488).

---

## Standard Stack

No third-party libraries are involved. All code uses PHP built-ins and IP-Symcon SDK functions.

| Component | Version | Purpose |
|-----------|---------|---------|
| PHP | 8.x (embedded in IPS 7.1) | Module runtime |
| IP-Symcon SDK | 7.1 | IPSModule base class, IPS_* globals |
| ZipArchive | PHP built-in | Support bundle zip (ThinQSupportBundle) |
| openssl_* | PHP built-in | Certificate generation (ThinQMqttSetupWizard) |

---

## Architecture Patterns

### Pattern: Dependency-injection via constructor

Every new lib class receives exactly the dependencies it needs. No class inherits from IPSModule. Classes call IPS global functions directly (they are globally available in the Symcon PHP runtime).

```php
// Source: existing ThinQHttpClient.php pattern
declare(strict_types=1);

class CapabilityCatalogLoader
{
    private int $instanceId;
    private string $baseDir;
    private static ?array $catalog = null;

    public function __construct(int $instanceId, string $baseDir)
    {
        $this->instanceId = $instanceId;
        $this->baseDir    = rtrim($baseDir, '/');
    }
}
```

### Pattern: Coordinator delegates, never re-implements

The coordinator `CapabilityEngine.php` stores shared state (`$caps`, `$flatProfile`, `$flatStatus`, `$instanceId`, `$baseDir`, callbacks) and forwards every public method call to the appropriate sub-object. Sub-objects receive only what they need via constructor or method arguments.

### Pattern: require_once chain

```
CapabilityEngine.php
  require_once ThinQGenericProperties.php   (unchanged)
  require_once ThinQEnumTranslator.php      (unchanged)
  require_once ThinQProfileParser.php       (unchanged)
  require_once CapabilityProfileExtractor.php   (NEW)
  require_once CapabilityCatalogLoader.php      (NEW)
  require_once CapabilityPlanBuilder.php        (NEW)
  require_once CapabilityVarManager.php         (NEW)
  require_once CapabilityControlBuilder.php     (NEW)

Device/module.php
  require_once ../libs/ThinQModuleTrait.php (unchanged)
  require_once libs/CapabilityEngine.php    (unchanged — CE still handles its own chain)
  require_once libs/ThinQPresentationBuilder.php  (NEW)
  require_once libs/ThinQEnergyManager.php        (NEW)
  require_once libs/ThinQSupportBundle.php        (NEW)

Bridge/module.php
  require_once ../libs/ThinQModuleTrait.php (unchanged)
  ... existing 9 lib require_once lines ... (unchanged)
  require_once libs/ThinQMqttSetupWizard.php      (NEW)
  [if split] require_once libs/ThinQMqttIoConfigurator.php (NEW, optional)
```

---

## Exact Line Count Analysis

### CapabilityEngine.php — Method Inventory

Lines verified by reading source (offsets confirmed):

| Method | Lines (approx.) | Assignment |
|--------|----------------|-----------|
| `extractErrorOptions` | 59–89 = 31 | ProfileExtractor |
| `extractPushOptions` | 96–129 = 34 | ProfileExtractor |
| `extractEnumValuesFromNode` | 137–175 = 39 | ProfileExtractor |
| `extractLabelsMap` | 182–219 = 38 | ProfileExtractor |
| `getBestLabel` | 225–235 = 11 | ProfileExtractor |
| `humanizeEnum` | 240–244 = 5 | ProfileExtractor |
| `extractEnumValuesFromFlatPrefix` | 251–275 = 25 | ProfileExtractor |
| `extractLabelsMapFromFlatPrefix` | 282–315 = 34 | ProfileExtractor |
| `extractErrorFromStatus` | 323–354 = 32 | ProfileExtractor |
| `extractPushFromStatus` | 362–406 = 45 | ProfileExtractor |
| **ProfileExtractor subtotal (methods only)** | **~294 lines** | |
| `setMaintainVariableCallback` | 412–415 = 4 | Coordinator |
| `setTranslateCallback` | 422–425 = 4 | Coordinator |
| `translate` | 432–439 = 8 | Coordinator |
| `debugEnabled` | 441–450 = 10 | Coordinator |
| `dbg` | 452–457 = 6 | Coordinator |
| `capHasWriteDefinition` | 459–468 = 10 | Coordinator |
| `loadCapabilities` | 476–482 = 7 | Coordinator (delegates to CatalogLoader) |
| `resolveCapabilityFiles` | 488–547 = 60 | CatalogLoader |
| `loadCatalog` | 552–585 = 34 | CatalogLoader |
| `catalogRuleMatches` | 590–601 = 12 | CatalogLoader |
| `catalogRuleMatchesDeviceOnly` | 606–617 = 12 | CatalogLoader |
| `matchesCondition` | 622–691 = 70 | CatalogLoader |
| **CatalogLoader subtotal (methods only)** | **~188 lines** | |
| `getDescriptors` | 697–700 = 4 | Coordinator |
| `getPresentationMap` | 706–715 = 10 | Coordinator |
| `ensureVariables` | 724–788 = 65 | VarManager |
| `reassertActionsOnSetup` | 794–804 = 11 | VarManager |
| `listIdentsToEnableOnSetup` | 811–835 = 25 | VarManager |
| `listIdentsToEnable` | 841–860 = 20 | VarManager |
| `buildPlan` | 871–984 = 114 | PlanBuilder |
| `applyStatus` | 991–1026 = 36 | Coordinator |
| `resetDeactivatedTimers` | 1036–1064 = 29 | Coordinator |
| `buildControlPayload` | 1074–1435 = 362 | ControlBuilder |
| `shouldCreate` | 1440–1498 = 59 | Coordinator (or VarManager) |
| `enableAction` | 1500–1507 = 8 | VarManager |
| `shouldEnableAction` | 1509–1527 = 19 | Coordinator |
| `profileHasWriteAny` | 1529–1600 = 72 | Coordinator |
| `modeHasW` | 1602–1611 = 10 | Coordinator |
| `flatProfileHasAny` | 1613–1625 = 13 | Coordinator |
| `flatProfileIsWriteable` | 1627–1645 = 19 | Coordinator |
| `readValue` | 1652–1755 = 104 | VarManager |
| `getFromFlat` | 1757–1760 = 4 | VarManager |
| `setValueByType` | 1762–1793 = 32 | VarManager |
| `convertValueForType` | 1795–1804 = 10 | VarManager / ControlBuilder (shared) |
| `replaceTemplatePlaceholders` | 1806–1812 = 7 | VarManager |
| `walkReplace` | 1814–1854 = 41 | VarManager |
| `getVarId` | 1856–1859 = 4 | Coordinator |
| `flatten` | 1862–1874 = 13 | Coordinator |
| `setByPath` | 1877–1890 = 14 | Coordinator |
| `findRangeFromProfile` | 1899–1930 = 32 | VarManager |
| `findArrayIndex` | 1936–1948 = 13 | VarManager |
| `collectArrayIndices` | 1951–1968 = 18 | VarManager |
| `getParser` | 1975–1985 = 11 | Coordinator (lazy-init ThinQProfileParser) |
| `convertAutoPlanToCapability` | 1993–2099 = 107 | PlanBuilder |
| `inferWriteConfig` | 2107–2224 = 118 | PlanBuilder |
| `getTimerPairValue` | 2235–2296 = 62 | ControlBuilder |
| `getVariableValue` | 2304–2312 = 9 | ControlBuilder |
| `applyCoSendFromStatus` | 2323–2346 = 24 | ControlBuilder |
| `applyCoSendConst` | 2357–2378 = 22 | ControlBuilder |
| `getVariableValueMixed` | 2385–2392 = 8 | ControlBuilder |
| `applyCompanionPatterns` | 2403–2523 = 121 | PlanBuilder |
| `addCompanionFromStatus` | 2528–2539 = 12 | PlanBuilder |
| `addCompanionConst` | 2544–2555 = 12 | PlanBuilder |
| `addEnumMapCompanionConst` | 2560–2571 = 12 | PlanBuilder |
| `ipsTypeToCapType` | 2576–2584 = 9 | ControlBuilder |

### Projected New File Sizes

**CapabilityProfileExtractor.php**
- Class header + `declare` + constructor + `$flatProfile` dependency: ~12 lines
- 10 methods: ~294 lines
- **Total: ~306 lines** — safely under 500. CONFIRMED.

**CapabilityCatalogLoader.php**
- Class header + `declare` + `static $catalog` + constructor: ~12 lines
- 5 methods: ~188 lines
- **Total: ~200 lines** — well under 500. CONFIRMED.

**CapabilityPlanBuilder.php**
- Class header + `declare` + constructor with dependencies: ~15 lines
- Methods: buildPlan(114) + convertAutoPlanToCapability(107) + inferWriteConfig(118) + applyCompanionPatterns(121) + addCompanionFromStatus(12) + addCompanionConst(12) + addEnumMapCompanionConst(12) = ~496 lines of method code
- **Total: ~511 lines** — EXCEEDS 500 by ~11 lines. RISK.

  Resolution: Move `addCompanionFromStatus` (12) + `addCompanionConst` (12) + `addEnumMapCompanionConst` (12) = 36 lines to the Coordinator since they only mutate `$this->caps` (directly accessible in coordinator). Alternatively move `applyCompanionPatterns` sub-routines into a private inner call from `buildPlan` and inline the 3 trivial "adder" methods as direct array writes inside `applyCompanionPatterns`. Both options reduce PlanBuilder to ~475 lines.

  **Recommended resolution:** Keep `addCompanionFromStatus/Const/EnumMapConst` as private methods on the Coordinator (they operate on `$this->caps`, which is owned by Coordinator). PlanBuilder calls them via a callback or a reference. Since the Coordinator passes `$this->caps` by reference to PlanBuilder methods, PlanBuilder can modify caps in-place without needing these helpers as its own methods. **PlanBuilder with 3 adder methods removed: ~475 lines.**

**CapabilityVarManager.php**
- Class header + `declare` + constructor: ~12 lines
- Methods: ensureVariables(65) + reassertActionsOnSetup(11) + listIdentsToEnableOnSetup(25) + listIdentsToEnable(20) + enableAction(8) + readValue(104) + getFromFlat(4) + setValueByType(32) + convertValueForType(10) + replaceTemplatePlaceholders(7) + walkReplace(41) + findRangeFromProfile(32) + findArrayIndex(13) + collectArrayIndices(18) = ~390 lines
- **Total: ~402 lines** — safely under 500. CONFIRMED.

**CapabilityControlBuilder.php**
- Class header + `declare` + constructor: ~12 lines
- Methods: buildControlPayload(362) + applyCoSendFromStatus(24) + applyCoSendConst(22) + getTimerPairValue(62) + getVariableValue(9) + getVariableValueMixed(8) + ipsTypeToCapType(9) = ~496 lines
- **Total: ~508 lines** — EXCEEDS 500 by ~8 lines. RISK.

  Resolution: Extract the inner `firstOf` dispatch branch (lines 1297–1433, ~136 lines) into a private `dispatchFirstOfOption()` method. This is a clean extraction: the `firstOf` handler iterates options and delegates each option type (attribute, template, enumMap, composite, multiAttribute) — the same logic that appears separately for the non-firstOf paths. Extracting it does not change any shared state access. After extraction: buildControlPayload shrinks from ~362 to ~230 lines; new `dispatchFirstOfOption` helper is ~136 lines. Total: ~496 lines including the helper.

  **ControlBuilder after firstOf extraction: ~496 lines.** SAFE.

**CapabilityEngine.php (Coordinator)**
- Class header + `declare` + properties + `__construct` + 3 require_once new + 3 require_once existing: ~35 lines
- Remaining methods: setMaintainVariableCallback(4) + setTranslateCallback(4) + translate(8) + debugEnabled(10) + dbg(6) + capHasWriteDefinition(10) + loadCapabilities(7) + getDescriptors(4) + getPresentationMap(10) + applyStatus(36) + resetDeactivatedTimers(29) + shouldCreate(59) + shouldEnableAction(19) + profileHasWriteAny(72) + modeHasW(10) + flatProfileHasAny(13) + flatProfileIsWriteable(19) + getVarId(4) + flatten(13) + setByPath(14) + getParser(11) = ~362 lines
- Delegation stub methods for public API calls (~5 methods × ~8 lines each = ~40 lines)
- **Total: ~437 lines** — safely under 500. CONFIRMED.

### Device/module.php — Extraction Analysis

**applyPresentation()**: lines 789–1111 = **323 lines**
**translatePresentationPayload()**: lines 1113–1142 = **30 lines**
**applyProfileFallback()**: lines 1144–1192 = **49 lines**
**ThinQPresentationBuilder subtotal (methods)**: ~402 lines
With class header + constructor: **~415 lines** — safely under 500. CONFIRMED.

**fetchEnergyProfile()**: lines 1657–1670 = 14 lines
**getEnergyProperties()**: lines 1672–1696 = 25 lines
**setupEnergyVariables()**: lines 1698–1721 = 24 lines
**scheduleEnergyTimer()**: lines 1723–1738 = 16 lines
**UpdateEnergy()**: lines 1740–1794 = 55 lines
**fetchEnergyUsage()**: lines 1796–1831 = 36 lines
**ThinQEnergyManager subtotal (methods)**: ~170 lines
With class header + constructor + properties: **~185 lines** — well under 500. CONFIRMED.

**UIExportSupportBundle()**: lines 1484–1493 = 10 lines
**buildSupportBundleZip()**: lines 1495–1639 = 145 lines
**ThinQSupportBundle subtotal (methods)**: ~155 lines
With class header + constructor: **~170 lines** — well under 500. CONFIRMED.

**Device/module.php after extraction:**
Current 1832 lines minus extracted methods:
- Remove applyPresentation (323) + translatePresentationPayload (30) + applyProfileFallback (49) = 402 lines
- Remove energy block (lines 1655–1832, ~178 lines including comment banner)
- Remove UIExportSupportBundle + buildSupportBundleZip (155 lines)
- Net removed: ~735 lines
- Remaining: ~1097 lines — **STILL EXCEEDS 500.**

  Additional methods to consider moving: Several private helpers used only in specific contexts remain in module.php. Based on actual reading, the remaining code is still too large. The planner must account for this.

  Key large methods still inside module.php (not yet assigned to new files):
  - `SetupDeviceVariables()` (likely the largest remaining method — not yet read in detail)
  - `ReceiveData()` and `RequestAction()` (protocol entry points)
  - `fetchDeviceProfile()`, `resolveDeviceType()`, `sendAction()` and related helpers

  These are core module logic (IPS lifecycle, data flow) that cannot be extracted to plain PHP classes because they use `$this->SendDebug`, `$this->SendDataToParent`, etc. They must stay in the IPSModule class.

  The planner should re-examine the actual method distribution within the remaining ~1097 lines and determine if any further extractable blocks exist. The CONTEXT.md estimate of "~490 lines after extraction" appears optimistic given the actual method sizes.

  **Risk flag:** Device/module.php may not reach sub-500 lines solely from D-09/D-10/D-11 extractions. The planner must verify remaining content and potentially extract additional helpers (e.g., `anonymizeArray`, `flatten`, `deepMerge`, `firstNumericByPaths` as a utility class) or acknowledge that the CONTEXT.md estimate requires revision.

### Bridge/module.php — Extraction Analysis

**UISetupMqttConnection()**: lines 1168–1488 = **321 lines**
**generateMqttClientCertMaterial()**: lines 1493–1544 = **52 lines**
**extractBrokerFromSubscriptions()**: lines 1550–1577 = **28 lines**
**fetchRouteBroker()**: lines 1583–1651 = **69 lines**
**getCertificateManager()** (thin delegator): lines 1654–1657 = 4 lines
**ensureCertificatePEM()** (thin delegator): lines 1659–1662 = 4 lines
**ensurePrivateKeyPEM()** (thin delegator): lines 1664–1667 = 4 lines
**extractCAPEMFromSubscriptions()** (thin delegator): lines 1669–1672 = 4 lines
**downloadAmazonRootCA1()** (thin delegator): lines 1674–1677 = 4 lines

**ThinQMqttSetupWizard total (extracted methods)**: 321 + 52 + 28 + 69 + (4×5) = **490 lines of method code**
Plus class header + declare + constructor + properties: **~507 lines** — EXCEEDS 500.

Four thin delegator methods (ensureCertificatePEM, ensurePrivateKeyPEM, extractCAPEMFromSubscriptions, downloadAmazonRootCA1) are only 4 lines each and call `$this->getCertificateManager()->method()`. These 16 lines of delegator code can be inlined directly inside UISetupMqttConnection (calling ThinQCertificateManager directly), removing the need to expose them as named methods on the Wizard class. This saves ~20 lines and keeps the file at ~487 lines.

**Recommended resolution:** In ThinQMqttSetupWizard, call `(new ThinQCertificateManager($instanceId))->ensureCertificatePEM(...)` directly inline rather than via private delegator methods. The four delegator methods are removed. **Total after this change: ~487 lines.** SAFE.

Alternatively: The IO configuration block inside UISetupMqttConnection (lines 1279–1488, the socket setup + PEM persistence + post-write validation) is ~209 lines and is self-contained. It can be extracted to `configureIoSocket(int $ioID, ...)` private method (~30 lines) + `validatePemPersistence(int $ioID, ...)` (~50 lines). This is a cleaner structural choice but produces slightly more method depth. Both options work.

**Bridge/module.php after extraction:**
Remove UISetupMqttConnection (321 lines) + generateMqttClientCertMaterial (52) + extractBrokerFromSubscriptions (28) + fetchRouteBroker (69) + 4 delegators (20) + instancesOf/cfg/safeSetProperty/setFirstAvailableProperty/setJsonCompatibleProperty/isInstanceOfModule helpers (lines 1682–1728, ~47 lines if these move into the Wizard).
Net removed: ~537 lines from 1729 total.
Remaining: ~1192 lines — **STILL EXCEEDS 500.**

The CONTEXT.md estimate of "~490 lines after extraction" for Bridge/module.php is also optimistic. `buildMQTTClientCertsZip()` (lines 846–1165, ~320 lines) was NOT assigned to ThinQMqttSetupWizard in D-12 — it is already a separate public method `UIGenerateMQTTClientCerts()` that calls `buildMQTTClientCertsZip()`. The planner needs to explicitly decide whether `buildMQTTClientCertsZip` (lines 846–1165) also moves to ThinQMqttSetupWizard, which would bring the wizard to ~810 lines (too large) — or whether it stays in module.php.

Additionally, the many remaining methods in Bridge/module.php (GetDevices, GetStatus, GetProfile, SubscribeDevice, SetDeviceControl, ReceiveData, ForwardData, etc.) are IPS-specific and require `$this->SendData...`, `$this->ReadProperty...` etc. — they cannot move outside the IPSModule class.

**Risk flag:** Bridge/module.php reduction to sub-500 lines is NOT achievable with D-12 alone. The planner must identify additional extraction candidates or acknowledge the file will exceed 500 lines after Phase 3.

---

## Dependency Graph Between New Classes

### Who needs what from CapabilityEngine coordinator state

| New Class | Needs from Coordinator | Mechanism |
|-----------|----------------------|-----------|
| `CapabilityProfileExtractor` | `$flatProfile` (array), `translateCallback` | Pass as constructor args or per-call args |
| `CapabilityCatalogLoader` | `$baseDir` (string), `instanceId` (for dbg) | Constructor |
| `CapabilityPlanBuilder` | `&$caps` (by ref), `$flatProfile`, `$flatStatus`, `translateCallback`, extractor instance, catalogLoader instance | Constructor or per-call |
| `CapabilityVarManager` | `$caps`, `$flatProfile`, `$flatStatus`, `$instanceId`, `maintainVariableCallback`, `translateCallback` | Constructor |
| `CapabilityControlBuilder` | `$caps`, `$flatProfile`, `$flatStatus`, `$instanceId` | Constructor |

### Cross-class method calls

```
CapabilityEngine (Coordinator)
  ├── buildPlan() delegates to CapabilityPlanBuilder::buildPlan()
  │     └── calls CapabilityProfileExtractor (for extractErrorOptions, extractPushOptions)
  │     └── calls ThinQProfileParser (for auto-discovery)
  ├── applyStatus() → calls CapabilityVarManager::readValue()
  │                 → calls CapabilityProfileExtractor::extractErrorFromStatus/extractPushFromStatus
  ├── ensureVariables() delegates to CapabilityVarManager::ensureVariables()
  ├── buildControlPayload() delegates to CapabilityControlBuilder::buildControlPayload()
  │     └── calls CapabilityVarManager::convertValueForType() (shared utility)
  │     └── calls CapabilityVarManager::walkReplace()
  │     └── calls CapabilityVarManager::findArrayIndex()
  │     └── calls CapabilityVarManager::setByPath() [or Coordinator's setByPath]
  ├── getDescriptors() / getPresentationMap() — direct on $caps (no delegation needed)
  └── reassertActionsOnSetup() / listIdentsToEnableOnSetup() / listIdentsToEnable()
        delegates to CapabilityVarManager
```

**Key cross-boundary dependency:** `CapabilityControlBuilder::buildControlPayload` calls 4 utility methods that CONTEXT.md assigned to VarManager: `convertValueForType`, `walkReplace`, `findArrayIndex`, `setByPath`. These methods have no side effects and do not access shared state — they are pure functions operating only on their arguments. The simplest solution is to duplicate them in ControlBuilder (they are small: 10, 41, 13, 14 lines). Alternatively, keep them in the Coordinator and call as `$coordinator->methodName()` — but that requires passing the coordinator reference. The cleanest approach: move `setByPath`, `convertValueForType`, `walkReplace` to ControlBuilder since ControlBuilder uses them most; VarManager calls them via ControlBuilder reference or copies the 3 trivial implementations.

---

## Constructor Signatures

```php
// CapabilityProfileExtractor.php
class CapabilityProfileExtractor
{
    public function __construct(
        private array $flatProfile,
        private ?callable $translateCallback
    ) {}
}

// CapabilityCatalogLoader.php
class CapabilityCatalogLoader
{
    private static ?array $catalog = null;

    public function __construct(
        private string $baseDir,
        private int $instanceId
    ) {}
}

// CapabilityPlanBuilder.php
class CapabilityPlanBuilder
{
    public function __construct(
        private array &$caps,
        private array $flatProfile,
        private array $flatStatus,
        private ?callable $translateCallback,
        private CapabilityProfileExtractor $extractor,
        private ThinQProfileParser $parser
    ) {}
}

// CapabilityVarManager.php
class CapabilityVarManager
{
    public function __construct(
        private array $caps,
        private array $flatProfile,
        private array $flatStatus,
        private int $instanceId,
        private ?callable $maintainVariableCallback,
        private ?callable $translateCallback
    ) {}
}

// CapabilityControlBuilder.php
class CapabilityControlBuilder
{
    public function __construct(
        private array $caps,
        private array $flatStatus,
        private int $instanceId
    ) {}
}
```

**Important:** `CapabilityPlanBuilder` receives `$caps` by reference (`&$caps`) so it can mutate the capability array during `buildPlan()` and `applyCompanionPatterns()`. All other sub-classes receive value copies (no mutation). After PlanBuilder returns, the Coordinator's `$this->caps` reflects all additions.

---

## Device/module.php Constructor Signatures for New Classes

```php
// ThinQPresentationBuilder.php
// Must call $this->t() and $this->SendDebug() — both IPS-bound.
// Solution: pass callbacks.
class ThinQPresentationBuilder
{
    public function __construct(
        private int $instanceId,
        private callable $translateCallback,
        private callable $debugCallback
    ) {}
    // Constants PRES_VALUE, PRES_SWITCH etc. must be passed in or redefined as class constants
}
```

Note: `applyPresentation()` uses the 5 PRES_* constants defined in LGThinQDevice. These are GUIDs as private const on the module. The PresentationBuilder needs access to them. Options:
1. Re-define them as public const on PresentationBuilder (duplicated but safe since GUIDs don't change)
2. Pass them as constructor array
3. Use hardcoded GUID strings directly in PresentationBuilder

**Recommended:** Re-define the 5 PRES_* constants as class constants on ThinQPresentationBuilder (duplication is acceptable for GUIDs that never change).

```php
// ThinQEnergyManager.php
// Uses $this->ReadAttributeString, $this->ReadPropertyString, $this->MaintainVariable,
// $this->SendDebug, $this->sendAction, $this->getVarId, $this->SetTimerInterval, $this->RegisterTimer
// These are all IPSModule-bound. Solution: pass $instanceId + callbacks or the module reference.
// Simplest: pass IPSModule $module reference (like ThinQHttpClient does).
class ThinQEnergyManager
{
    public function __construct(
        private IPSModule $module,
        private int $instanceId
    ) {}
}
```

`ThinQEnergyManager` needs many IPS calls that are only available on IPSModule. Passing the module reference directly (same pattern as `ThinQHttpClient`) is the cleanest approach. The `UpdateEnergy()` method is public (called by timer `LGTQD_UpdateEnergy`) — it stays as a public method in `LGThinQDevice` module.php which delegates to the EnergyManager.

```php
// ThinQSupportBundle.php
// Uses $this->InstanceID, $this->t(), $this->ReadPropertyString, $this->sendAction,
// $this->anonymizeArray, $this->flatten, $this->ReadAttributeString, $this->getCapabilityEngine,
// $this->fetchDeviceProfile — many IPS-bound calls.
// Same pattern: pass IPSModule reference.
class ThinQSupportBundle
{
    public function __construct(
        private IPSModule $module
    ) {}
}
```

```php
// ThinQMqttSetupWizard.php — Bridge side
// Uses $this->ReadPropertyString/Boolean/Integer, $this->ReadAttributeString,
// $this->WriteAttributeString, $this->SendDebug, $this->NotifyUser,
// $this->ReadPropertyBoolean, $this->cfg, $this->safeSetProperty,
// $this->instancesOf, $this->findModuleGUIDByName (from trait),
// $this->isInstanceOfModule, $this->setFirstAvailableProperty,
// $this->setJsonCompatibleProperty, $this->httpClient (lazy init in Bridge),
// $this->ensureBooted, $this->createBridgeConfig
class ThinQMqttSetupWizard
{
    public function __construct(
        private IPSModule $module,
        private int $instanceId,
        private string $apiKey
    ) {}
}
```

The Wizard cannot be a standalone class without either the module reference or having all IPS calls re-wrapped as callbacks. Passing the module reference (like ThinQHttpClient/EnergyManager) is the established pattern in this codebase and avoids a large callback surface.

---

## Static State

`static ?array $catalog = null` in `CapabilityEngine` is the only static field in the engine. It must move to `CapabilityCatalogLoader` as `private static ?array $catalog = null`. The static field survives for the PHP process lifetime (request-scoped in IPS), which is the intended behavior — the catalog is read once per process from disk and cached in memory. No other static fields exist in CapabilityEngine.

---

## Common Pitfalls

### Pitfall 1: Stale sub-object state
**What goes wrong:** If CapabilityVarManager or CapabilityControlBuilder are instantiated with a snapshot of `$caps`/`$flatStatus` and then the Coordinator updates those arrays after a new `buildPlan()` call, the sub-objects hold stale data.
**How to avoid:** Either (a) instantiate sub-objects fresh on each public method call (cheap since they have no setup cost), or (b) use object references / a shared context object. Approach (a) is simpler and matches the existing pattern in this codebase (lazy-init on each accessor call).

### Pitfall 2: require_once guards across directories
**What goes wrong:** `CapabilityEngine.php` is in `LG ThinQ Device/libs/`. It requires its sub-files from the same directory. If Device/module.php also requires the new sub-files directly, PHP may double-include without require_once guards (which would be a class-redeclaration fatal). Solution: only Device/module.php requires CapabilityEngine.php; CapabilityEngine.php requires all its sub-files; module.php does NOT directly require sub-files.

### Pitfall 3: ThinQPresentationBuilder calling IPS functions with wrong $instanceId
**What goes wrong:** `applyPresentation()` calls `@IPS_SetVariableCustomProfile($vid, '')` and `IPS_SetVariableCustomPresentation($vid, ...)` — these are global IPS functions that need a valid variable ID, not an instance ID. They will work from any class. But `$this->SendDebug()` in the method is IPS-bound. If using `$module->SendDebug()` approach, the module reference must be LGThinQDevice or at minimum IPSModule.

### Pitfall 4: Missing firstNumericByPaths in PresentationBuilder
**What goes wrong:** `applyPresentation()` calls `$this->firstNumericByPaths()` (line 809, 810, 811). This method is defined somewhere in Device/module.php. It must accompany the PresentationBuilder extraction or stay in the module with the call delegated back.

### Pitfall 5: php -l on files with IPS SDK constants
**What goes wrong:** `php -l` does not execute code but does check syntax. Files using `VARIABLETYPE_BOOLEAN`, `IPS_GetObjectIDByIdent`, etc. will pass `php -l` fine because those are function/constant references (undefined constants become strings in PHP — no syntax error). No special concern here.

### Pitfall 6: buildMQTTClientCertsZip not accounted for in Bridge reduction
**What goes wrong:** The CONTEXT.md reduction estimate for Bridge/module.php assumed only UISetupMqttConnection and its private helpers would be moved. `buildMQTTClientCertsZip()` (320 lines, lines 846–1165) was not assigned to any new file. After Phase 3 extractions as defined, Bridge/module.php still contains this large method plus all API methods. The file will remain over 500 lines.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead |
|---------|-------------|-------------|
| Certificate PEM formatting | Custom base64 wrapping logic | ThinQCertificateManager (already exists in Bridge/libs) |
| PHP class autoloading | Manual scan/include logic | require_once chain (project constraint) |
| Deep array manipulation | Custom recursive merge | Existing `flatten()` and `setByPath()` in Coordinator |

---

## Environment Availability

Step 2.6: SKIPPED — this phase is purely code restructuring (PHP class extraction). No external tools, services, CLIs, or databases are involved. `php -l` is available as a syntax checker but requires no install.

---

## Validation Architecture

**Note:** No test files exist for this project. Validation is manual via php -l and runtime integration testing in IP-Symcon.

### Test Approach for Phase 3

| Requirement | Validation Method |
|-------------|------------------|
| All files under 500 lines | `wc -l` on each new file after writing |
| php -l passes | `php -l LG\ ThinQ\ Device/libs/CapabilityEngine.php` and each new file |
| Public API unchanged | Grep for all callers in Device/module.php; confirm method names and signatures match |
| require_once chain complete | php -l on Device/module.php and Bridge/module.php (pulls in all requires) |
| No class redeclaration | Verify no file is required twice in any include chain |

### Wave 0 Gaps
- No test infrastructure exists; no automated test commands are available
- Manual smoke test: load IP-Symcon with refactored module, verify existing device instances still show variables and update on status push

---

## Revised Line Count Summary (Key Decisions)

| New File | Projected Lines | Status |
|----------|----------------|--------|
| `CapabilityProfileExtractor.php` | ~306 | SAFE |
| `CapabilityCatalogLoader.php` | ~200 | SAFE |
| `CapabilityPlanBuilder.php` (without 3 adder methods) | ~475 | SAFE |
| `CapabilityVarManager.php` | ~402 | SAFE |
| `CapabilityControlBuilder.php` (with firstOf extraction) | ~496 | SAFE (borderline) |
| `CapabilityEngine.php` (Coordinator) | ~437 | SAFE |
| `ThinQPresentationBuilder.php` | ~415 | SAFE |
| `ThinQEnergyManager.php` | ~185 | SAFE |
| `ThinQSupportBundle.php` | ~170 | SAFE |
| `ThinQMqttSetupWizard.php` (without 4 delegators) | ~487 | SAFE (borderline) |
| `Device/module.php` after extraction | ~1097 | **EXCEEDS 500** |
| `Bridge/module.php` after extraction | ~1192 | **EXCEEDS 500** |

**The two module.php files will NOT reach sub-500 lines with the planned extractions alone.** The planner must either:

1. Accept that module.php files remain over 500 lines in Phase 3 and defer further reduction to Phase 4 (QA sweep), OR
2. Identify and extract additional method groups from each module.php that can be cleanly separated. For Device/module.php: candidate groups include `SetupDeviceVariables` + related variable-creation methods, or the `sendAction` + data flow methods. For Bridge/module.php: `buildMQTTClientCertsZip` (320 lines) is the obvious candidate.

The CONTEXT.md estimates appear to have undercounted remaining method content. The planner should reconfirm this before committing to sub-500 targets for the module.php files.

---

## require_once Chain (Complete)

```php
// CapabilityEngine.php — head of chain for Device sub-classes
require_once __DIR__ . '/ThinQGenericProperties.php';
require_once __DIR__ . '/ThinQEnumTranslator.php';
require_once __DIR__ . '/ThinQProfileParser.php';
require_once __DIR__ . '/CapabilityProfileExtractor.php';
require_once __DIR__ . '/CapabilityCatalogLoader.php';
require_once __DIR__ . '/CapabilityPlanBuilder.php';
require_once __DIR__ . '/CapabilityVarManager.php';
require_once __DIR__ . '/CapabilityControlBuilder.php';

// Device/module.php — adds new lib files
require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/CapabilityEngine.php';   // pulls in all CE sub-files
require_once __DIR__ . '/libs/ThinQPresentationBuilder.php';
require_once __DIR__ . '/libs/ThinQEnergyManager.php';
require_once __DIR__ . '/libs/ThinQSupportBundle.php';

// Bridge/module.php — adds wizard file at end
require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/ThinQHelpers.php';
require_once __DIR__ . '/libs/ThinQConfig.php';
require_once __DIR__ . '/libs/ThinQDeviceRepository.php';
require_once __DIR__ . '/libs/ThinQEventSubscriptionRepository.php';
require_once __DIR__ . '/libs/ThinQHttpClient.php';
require_once __DIR__ . '/libs/ThinQEventManager.php';
require_once __DIR__ . '/libs/ThinQEventPipeline.php';
require_once __DIR__ . '/libs/ThinQMqttRouter.php';
require_once __DIR__ . '/libs/ThinQCertificateManager.php';
require_once __DIR__ . '/libs/ThinQMqttSetupWizard.php';
```

---

## Open Questions

1. **Device/module.php sub-500 feasibility**
   - What we know: ~1097 lines remain after D-09/D-10/D-11 extractions
   - What's unclear: Which remaining methods (SetupDeviceVariables, sendAction, ReceiveData, etc.) can be split without major refactoring
   - Recommendation: Planner to read lines 100–788 of Device/module.php (the large middle section not yet read in this research session) to identify additional extraction candidates

2. **Bridge/module.php sub-500 feasibility**
   - What we know: ~1192 lines remain after D-12 extraction; `buildMQTTClientCertsZip` is 320 lines and is not assigned to any file in CONTEXT.md
   - What's unclear: Whether buildMQTTClientCertsZip moves to ThinQMqttSetupWizard (making it ~810 lines) or to a new ThinQMqttCertZipBuilder class
   - Recommendation: Extract `buildMQTTClientCertsZip` to ThinQMqttSetupWizard OR to a new ThinQCertZipBuilder class (~350 lines with header). This plus D-12 brings Bridge/module.php to ~872 lines — still over 500. Further investigation needed.

3. **CapabilityControlBuilder `convertValueForType` / `walkReplace` / `setByPath` ownership**
   - What we know: These are used by both ControlBuilder and VarManager (and Coordinator)
   - What's unclear: Whether to duplicate them or share via coordinator
   - Recommendation: Keep `setByPath` and `flatten` in Coordinator (used by status handling too); duplicate `convertValueForType` (10 lines) and `walkReplace` (41 lines) in ControlBuilder since it's the primary user

4. **`firstNumericByPaths` method location**
   - What we know: Called inside `applyPresentation()` which moves to ThinQPresentationBuilder
   - What's unclear: Where `firstNumericByPaths` is defined in Device/module.php (not yet read in lines 100–788)
   - Recommendation: Planner must locate this method and ensure it accompanies PresentationBuilder

---

## Sources

### Primary (HIGH confidence)
- Direct source reading: `LG ThinQ Device/libs/CapabilityEngine.php` (all 2585 lines)
- Direct source reading: `LG ThinQ Device/module.php` (lines 1–100, 780–1832)
- Direct source reading: `LG ThinQ Bridge/module.php` (lines 1–50, 840–1729)
- Direct source reading: `.planning/phases/03-capabilityengine-split/03-CONTEXT.md`
- Direct source reading: `libs/ThinQModuleTrait.php`, `LG ThinQ Bridge/libs/ThinQHttpClient.php`, `LG ThinQ Device/libs/ThinQProfileParser.php`

### Secondary (MEDIUM confidence)
- Line counts confirmed via `wc -l` bash command

---

## Metadata

**Confidence breakdown:**
- Line count analysis: HIGH — based on direct source reading with line offset counting
- Dependency graph: HIGH — verified by reading all method bodies
- Constructor signatures: HIGH — derived from what each method actually accesses
- Module.php reduction estimates: MEDIUM — the middle section of Device/module.php (lines 100–788) was partially read; exact line counts for those methods are estimated from context

**Research date:** 2026-03-27
**Valid until:** Stable — no external dependencies; only invalidated by code changes to source files

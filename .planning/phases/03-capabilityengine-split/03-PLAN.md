# Phase 3 Plan: CapabilityEngine Split

**Goal:** Split `CapabilityEngine.php` (2585 lines), `LG ThinQ Device/module.php` (1832 lines), and `LG ThinQ Bridge/module.php` (1729 lines) so that every file is under 500 lines. No behavior changes, no public API changes.

**Requirements:** CAPE-01, CAPE-02, CAPE-03

**Success Criteria:**
1. `CapabilityEngine.php` is under 500 lines (acts as thin coordinator)
2. Every new CapabilityEngine sub-file is under 500 lines
3. Profile-parsing, plan-building, and variable-registration are distinct classes
4. `LG ThinQ Bridge/module.php` and `LG ThinQ Device/module.php` are each under 500 lines
5. `php -l` passes on all new and modified files

---

## Pre-flight Checks

- [ ] Read each source file in full before extracting from it
- [ ] Count lines with `wc -l` before and after each extraction
- [ ] Run `php -l` on every new file immediately after writing it
- [ ] Run `php -l` on the modified source file after each extraction

---

## Honest Line-Count Projections

The research confirmed that the CONTEXT.md estimates for module.php reductions were optimistic. Here are the realistic projected sizes after all extractions in this plan:

| File | Current | Removed | Projected | Status |
|------|---------|---------|-----------|--------|
| `CapabilityEngine.php` (Coordinator) | 2585 | ~2148 | ~437 | SAFE |
| `CapabilityProfileExtractor.php` | new | — | ~306 | SAFE |
| `CapabilityCatalogLoader.php` | new | — | ~200 | SAFE |
| `CapabilityPlanBuilder.php` | new | — | ~475 | SAFE |
| `CapabilityVarManager.php` | new | — | ~402 | SAFE |
| `CapabilityControlBuilder.php` | new | — | ~496 | SAFE (borderline) |
| `ThinQPresentationBuilder.php` | new | — | ~415 | SAFE |
| `ThinQEnergyManager.php` | new | — | ~185 | SAFE |
| `ThinQSupportBundle.php` | new | — | ~170 | SAFE |
| `ThinQDeviceProfileManager.php` | new | — | ~220 | SAFE |
| `ThinQDeviceUtil.php` | new | — | ~160 | SAFE |
| `Device/module.php` | 1832 | ~1192 | ~640 | STILL OVER 500 — see note |
| `ThinQMqttSetupWizard.php` | new | — | ~487 | SAFE (borderline) |
| `ThinQMqttCertBuilder.php` | new | — | ~340 | SAFE |
| `Bridge/module.php` | 1729 | ~890 | ~839 | STILL OVER 500 — see note |

**Note on module.php files:** Both module.php files contain IPS-bound core methods (`Create`, `ApplyChanges`, `ReceiveData`, `RequestAction`, `ForwardData`, `sendAction`, `bootServices`, etc.) that use `$this->ReadPropertyString`, `$this->SendDataToParent`, `$this->SendDataToChildren`, `$this->SendDebug`, and other IPSModule-bound calls. These methods cannot be extracted to standalone classes. After extracting every extractable block in this plan, approximately 640 lines remain in Device/module.php and 839 lines remain in Bridge/module.php. Getting these below 500 lines is not possible without splitting the IPS lifecycle methods themselves, which would require a fundamentally different module architecture and is out of scope per CONTEXT.md. The 500-line limit for module.php files should be explicitly acknowledged as unachievable through class extraction alone.

---

## Plan

---

### Task 1: Extract `CapabilityProfileExtractor.php`

**File to create:** `LG ThinQ Device/libs/CapabilityProfileExtractor.php`

**Projected size:** ~306 lines

**Read first:** `LG ThinQ Device/libs/CapabilityEngine.php` lines 1–410

**Methods to extract (from CapabilityEngine.php):**

| Method | Lines |
|--------|-------|
| `extractErrorOptions` | 59–89 |
| `extractPushOptions` | 96–129 |
| `extractEnumValuesFromNode` | 137–175 |
| `extractLabelsMap` | 182–219 |
| `getBestLabel` | 225–235 |
| `humanizeEnum` | 240–244 |
| `extractEnumValuesFromFlatPrefix` | 251–275 |
| `extractLabelsMapFromFlatPrefix` | 282–315 |
| `extractErrorFromStatus` | 323–354 |
| `extractPushFromStatus` | 362–406 |

**Constructor signature:**
```php
declare(strict_types=1);

class CapabilityProfileExtractor
{
    public function __construct(
        private array $flatProfile,
        private ?callable $translateCallback
    ) {}

    private function t(string $s): string
    {
        return $this->translateCallback ? ($this->translateCallback)($s) : $s;
    }
}
```

**Steps:**
1. Read `CapabilityEngine.php` lines 1–410 to copy all 10 method bodies verbatim.
2. Create `CapabilityProfileExtractor.php` with `declare(strict_types=1)`, the class, the constructor above, the private `t()` helper, and all 10 extracted methods as-is. Each method that called `$this->t()` already works because the class has a `t()` helper delegating to `$this->translateCallback`. Each method that accessed `$this->flatProfile` works because the property is set in the constructor.
3. In `CapabilityEngine.php`, delete the 10 extracted method bodies (lines 59–406). In their place, leave only stub delegation calls:
   ```php
   // These methods now live in CapabilityProfileExtractor
   // The extractor instance is created lazily in getExtractor()
   ```
4. Add `require_once __DIR__ . '/CapabilityProfileExtractor.php';` near the top of `CapabilityEngine.php` (after the existing 3 require_once lines).
5. Add a private getter in CapabilityEngine coordinator:
   ```php
   private function getExtractor(): CapabilityProfileExtractor
   {
       return new CapabilityProfileExtractor($this->flatProfile, $this->translateCallback);
   }
   ```
6. Update every internal call in CapabilityEngine that called these methods (they are called inside `buildPlan`, `applyStatus`, etc.) to use `$this->getExtractor()->methodName(...)`. Pass any parameters that were originally method arguments as method arguments on the extractor call.
7. Run `php -l "LG ThinQ Device/libs/CapabilityProfileExtractor.php"` — must exit 0.
8. Run `wc -l "LG ThinQ Device/libs/CapabilityProfileExtractor.php"` — must be under 500.

**Verify:** `php -l` exits 0 on new file. `wc -l` under 500.

---

### Task 2: Extract `CapabilityCatalogLoader.php`

**File to create:** `LG ThinQ Device/libs/CapabilityCatalogLoader.php`

**Projected size:** ~200 lines

**Read first:** `LG ThinQ Device/libs/CapabilityEngine.php` lines 476–691

**Methods to extract:**

| Method | Lines |
|--------|-------|
| `resolveCapabilityFiles` | 488–547 |
| `loadCatalog` | 552–585 |
| `catalogRuleMatches` | 590–601 |
| `catalogRuleMatchesDeviceOnly` | 606–617 |
| `matchesCondition` | 622–691 |

**Constructor signature:**
```php
declare(strict_types=1);

class CapabilityCatalogLoader
{
    private static ?array $catalog = null;

    public function __construct(
        private string $baseDir,
        private int $instanceId
    ) {}
}
```

**Important:** The `static ?array $catalog` must be `private static` on this class. This preserves the process-lifetime cache behavior (catalog read once from disk per PHP process).

**Steps:**
1. Read `CapabilityEngine.php` lines 476–700 to verify exact method boundaries.
2. In `CapabilityEngine.php`, find the `static ?array $catalog` field (in the class properties section). Note its location. It will move to `CapabilityCatalogLoader`.
3. Create `CapabilityCatalogLoader.php` with the class, constructor, `private static ?array $catalog = null`, and the 5 extracted methods. The `loadCatalog` method references `self::$catalog` and `$this->baseDir` and `$this->instanceId` — confirm these work with the new constructor.
4. Add `require_once __DIR__ . '/CapabilityCatalogLoader.php';` to `CapabilityEngine.php`.
5. In `CapabilityEngine.php`, remove the `static $catalog` field from the properties section.
6. Update `loadCapabilities()` in CapabilityEngine to delegate to the loader:
   ```php
   public function loadCapabilities(string $deviceType, array $profile): void
   {
       $loader = new CapabilityCatalogLoader($this->baseDir, $this->instanceId);
       $this->caps = $loader->loadCatalog($deviceType, $profile);
   }
   ```
   (Adjust parameter names to match the actual existing signature.)
7. Remove the 5 extracted method bodies from `CapabilityEngine.php`.
8. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0. `wc -l` under 500.

---

### Task 3: Extract `CapabilityPlanBuilder.php`

**File to create:** `LG ThinQ Device/libs/CapabilityPlanBuilder.php`

**Projected size:** ~475 lines

**Read first:** `LG ThinQ Device/libs/CapabilityEngine.php` lines 860–2575

**Methods to extract (D-05 with modification from CONTEXT.md):**

| Method | Lines | Notes |
|--------|-------|-------|
| `buildPlan` | 871–984 | Core plan-building |
| `convertAutoPlanToCapability` | 1993–2099 | Helper called by buildPlan |
| `inferWriteConfig` | 2107–2224 | Helper called by buildPlan |
| `applyCompanionPatterns` | 2403–2523 | Companion pattern wiring |
| `addCompanionFromStatus` | 2528–2539 | Keep in PlanBuilder (see note) |
| `addCompanionConst` | 2544–2555 | Keep in PlanBuilder (see note) |
| `addEnumMapCompanionConst` | 2560–2571 | Keep in PlanBuilder (see note) |

**Note on 3 addCompanion* methods:** CONTEXT.md (D-05) originally included these; RESEARCH.md suggested moving them to the Coordinator to save ~36 lines. However, they are only called from `applyCompanionPatterns` inside PlanBuilder. Keeping them in PlanBuilder is structurally cleaner. Monitor line count after extraction — if PlanBuilder exceeds 500, move these 3 methods (~36 lines combined) to the Coordinator instead.

**Constructor signature:**
```php
declare(strict_types=1);

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

    private function t(string $s): string
    {
        return $this->translateCallback ? ($this->translateCallback)($s) : $s;
    }
}
```

**Critical:** `$caps` is passed **by reference** (`&$caps`). PlanBuilder must mutate the coordinator's `$this->caps` directly. When the coordinator delegates `buildPlan()`, it passes `$this->caps` by reference.

**Steps:**
1. Read CapabilityEngine.php lines 860–990 (`buildPlan`), 1993–2230 (`convertAutoPlanToCapability` + `inferWriteConfig`), and 2403–2575 (companion methods) to copy verbatim.
2. Create `CapabilityPlanBuilder.php` with the constructor above. All method bodies are pasted verbatim — any `$this->t()` calls work via the inline `t()` helper. Any calls to `$this->extractor->methodName()` replace former `$this->methodName()` calls that were extracted to ProfileExtractor. Any calls to `$this->parser->method()` replace former `$this->getParser()->method()` calls.
3. Run `wc -l` immediately after writing. If over 500: move `addCompanionFromStatus`, `addCompanionConst`, `addEnumMapCompanionConst` to the Coordinator and have PlanBuilder call them via a passed-in callback array or via the `$caps` reference directly (inline the trivial logic).
4. Add `require_once __DIR__ . '/CapabilityPlanBuilder.php';` to `CapabilityEngine.php`.
5. In `CapabilityEngine.php`, add a delegation method:
   ```php
   public function buildPlan(string $type, array $profile, array $status): array
   {
       $this->flatProfile = $this->flatten($profile);
       $this->flatStatus  = $this->flatten($status);
       $builder = new CapabilityPlanBuilder(
           $this->caps,
           $this->flatProfile,
           $this->flatStatus,
           $this->translateCallback,
           $this->getExtractor(),
           $this->getParser()
       );
       return $builder->buildPlan($type, $profile, $status);
   }
   ```
6. Remove the 7 extracted method bodies from `CapabilityEngine.php`.
7. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0. `wc -l` under 500. `buildPlan()` still accessible on `CapabilityEngine` with the same signature.

---

### Task 4: Extract `CapabilityVarManager.php`

**File to create:** `LG ThinQ Device/libs/CapabilityVarManager.php`

**Projected size:** ~402 lines

**Read first:** `LG ThinQ Device/libs/CapabilityEngine.php` lines 720–870 and 1440–1970

**Methods to extract (D-06):**

| Method | Lines |
|--------|-------|
| `ensureVariables` | 724–788 |
| `reassertActionsOnSetup` | 794–804 |
| `listIdentsToEnableOnSetup` | 811–835 |
| `listIdentsToEnable` | 841–860 |
| `enableAction` | 1500–1507 |
| `readValue` | 1652–1755 |
| `getFromFlat` | 1757–1760 |
| `setValueByType` | 1762–1793 |
| `convertValueForType` | 1795–1804 |
| `replaceTemplatePlaceholders` | 1806–1812 |
| `walkReplace` | 1814–1854 |
| `findRangeFromProfile` | 1899–1930 |
| `findArrayIndex` | 1936–1948 |
| `collectArrayIndices` | 1951–1968 |

**Constructor signature:**
```php
declare(strict_types=1);

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

    private function t(string $s): string
    {
        return $this->translateCallback ? ($this->translateCallback)($s) : $s;
    }
}
```

**Note on shared utilities:** `convertValueForType`, `walkReplace`, and `findArrayIndex` are also called by `CapabilityControlBuilder`. These are small pure functions (10, 41, 13 lines). The simplest resolution: duplicate them in `CapabilityControlBuilder` (they have no side effects and no shared state). Duplication of pure utilities is acceptable to avoid a cross-class dependency.

**Steps:**
1. Read the line ranges above to copy the 14 method bodies verbatim.
2. Create `CapabilityVarManager.php`. Verify that all `$this->` references in extracted methods map to constructor properties (caps, flatProfile, flatStatus, instanceId, maintainVariableCallback, translateCallback) or to other methods in the same class.
3. The `enableAction` method calls `IPS_SetVariableActionEnabled` (a global IPS function) using `$this->instanceId` as the parent. This is a global function, not an IPSModule method — it works in standalone classes.
4. Add `require_once __DIR__ . '/CapabilityVarManager.php';` to `CapabilityEngine.php`.
5. In `CapabilityEngine.php`, update delegation:
   - `ensureVariables()` → creates `new CapabilityVarManager(...)` and calls `->ensureVariables()`
   - `reassertActionsOnSetup()` → delegates to VarManager
   - `listIdentsToEnableOnSetup()` → delegates to VarManager
   - `listIdentsToEnable()` → delegates to VarManager
   - `applyStatus()` calls `readValue` internally — update to use VarManager instance
6. Remove the 14 extracted method bodies from `CapabilityEngine.php`.
7. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0. `wc -l` under 500.

---

### Task 5: Extract `CapabilityControlBuilder.php`

**File to create:** `LG ThinQ Device/libs/CapabilityControlBuilder.php`

**Projected size:** ~496 lines

**Read first:** `LG ThinQ Device/libs/CapabilityEngine.php` lines 1074–1445 and 2230–2400

**Methods to extract (D-07):**

| Method | Lines | Notes |
|--------|-------|-------|
| `buildControlPayload` | 1074–1435 | Large method; extract `dispatchFirstOfOption()` |
| `applyCoSendFromStatus` | 2323–2346 | |
| `applyCoSendConst` | 2357–2378 | |
| `getTimerPairValue` | 2235–2296 | |
| `getVariableValue` | 2304–2312 | |
| `getVariableValueMixed` | 2385–2392 | |
| `ipsTypeToCapType` | 2576–2584 | |

**Required inner extraction:** `buildControlPayload` is ~362 lines. To stay under 500, extract the `firstOf` dispatch branch (lines ~1297–1433, ~136 lines) into a private `dispatchFirstOfOption(array $options, array $flatStatus, array $caps): mixed` method. After this extraction, `buildControlPayload` shrinks to ~230 lines. Total file: ~496 lines.

**Shared utilities to duplicate here** (copied from VarManager — pure functions, no state):
- `convertValueForType` (~10 lines)
- `walkReplace` (~41 lines)
- `findArrayIndex` (~13 lines)

**Constructor signature:**
```php
declare(strict_types=1);

class CapabilityControlBuilder
{
    public function __construct(
        private array $caps,
        private array $flatStatus,
        private int $instanceId
    ) {}
}
```

**Steps:**
1. Read `CapabilityEngine.php` lines 1074–1445 (buildControlPayload) and 2235–2400 (getTimerPairValue through getVariableValueMixed) and 2576–2584 (ipsTypeToCapType).
2. Create `CapabilityControlBuilder.php`. Copy all method bodies verbatim.
3. Inside `buildControlPayload`, identify the `firstOf` branch. Extract its body into a private `dispatchFirstOfOption()` method in the same class to keep `buildControlPayload` under ~230 lines.
4. Copy the three shared utility methods from VarManager (`convertValueForType`, `walkReplace`, `findArrayIndex`) as private methods.
5. Run `wc -l` immediately. If over 500, the `dispatchFirstOfOption` extraction must be more aggressive (extract additional sub-branches).
6. Add `require_once __DIR__ . '/CapabilityControlBuilder.php';` to `CapabilityEngine.php`.
7. Update `buildControlPayload()` delegation in `CapabilityEngine.php`:
   ```php
   public function buildControlPayload(string $ident, $value): array
   {
       $builder = new CapabilityControlBuilder($this->caps, $this->flatStatus, $this->instanceId);
       return $builder->buildControlPayload($ident, $value);
   }
   ```
8. Remove the extracted method bodies from `CapabilityEngine.php`.
9. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0. `wc -l` under 500.

---

### Task 6: Finalize `CapabilityEngine.php` (Coordinator)

**File to modify:** `LG ThinQ Device/libs/CapabilityEngine.php`

**Target size:** ~437 lines

**Read first:** The entire current state of `CapabilityEngine.php` after Tasks 1–5 have been applied.

**Methods that remain in the Coordinator (D-08):**

| Method | Lines (approx.) |
|--------|----------------|
| Properties + `__construct` | ~35 |
| `setMaintainVariableCallback` | 4 |
| `setTranslateCallback` | 4 |
| `translate` | 8 |
| `debugEnabled` | 10 |
| `dbg` | 6 |
| `capHasWriteDefinition` | 10 |
| `loadCapabilities` (delegates to CatalogLoader) | 7 |
| `getDescriptors` | 4 |
| `getPresentationMap` | 10 |
| `applyStatus` | 36 |
| `resetDeactivatedTimers` | 29 |
| `shouldCreate` | 59 |
| `shouldEnableAction` | 19 |
| `profileHasWriteAny` | 72 |
| `modeHasW` | 10 |
| `flatProfileHasAny` | 13 |
| `flatProfileIsWriteable` | 19 |
| `getVarId` | 4 |
| `flatten` | 13 |
| `setByPath` | 14 |
| `getParser` (lazy-init) | 11 |
| Delegation stubs (buildPlan, ensureVariables, etc.) | ~40 |
| 5 require_once for new sub-files | ~5 |

**Steps:**
1. Read the current file to confirm all 5 extraction tasks have been applied and the correct methods remain.
2. Verify that all 8 `require_once` lines at the top are present and correct:
   ```php
   require_once __DIR__ . '/ThinQGenericProperties.php';
   require_once __DIR__ . '/ThinQEnumTranslator.php';
   require_once __DIR__ . '/ThinQProfileParser.php';
   require_once __DIR__ . '/CapabilityProfileExtractor.php';
   require_once __DIR__ . '/CapabilityCatalogLoader.php';
   require_once __DIR__ . '/CapabilityPlanBuilder.php';
   require_once __DIR__ . '/CapabilityVarManager.php';
   require_once __DIR__ . '/CapabilityControlBuilder.php';
   ```
3. Verify that every public method listed in D-02 is present in the coordinator (either as a real implementation or a delegation stub calling a sub-object):
   - `buildPlan()`, `applyStatus()`, `ensureVariables()`, `buildControlPayload()`, `getDescriptors()`, `getPresentationMap()`, `reassertActionsOnSetup()`, `listIdentsToEnableOnSetup()`, `listIdentsToEnable()`, `setMaintainVariableCallback()`, `setTranslateCallback()`, `loadCapabilities()`
4. Run `wc -l` — must be under 500.
5. Run `php -l "LG ThinQ Device/libs/CapabilityEngine.php"` — must exit 0.

**Verify:** All 12 public API methods present. `wc -l` under 500. `php -l` exits 0.

---

### Task 7: Extract `ThinQPresentationBuilder.php` from `LG ThinQ Device/module.php`

**File to create:** `LG ThinQ Device/libs/ThinQPresentationBuilder.php`

**Projected size:** ~415 lines

**Read first:** `LG ThinQ Device/module.php` lines 789–1192

**Methods to extract (D-09):**

| Method | Lines |
|--------|-------|
| `applyPresentation` | 789–1111 |
| `translatePresentationPayload` | 1113–1142 |
| `applyProfileFallback` | 1144–1192 |

**Constructor signature:**
```php
declare(strict_types=1);

class ThinQPresentationBuilder
{
    // Redefine the 5 PRES_* constants (GUIDs that never change)
    private const PRES_VALUE    = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const PRES_SWITCH   = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const PRES_SLIDER   = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const PRES_DATETIME = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const PRES_BUTTONS  = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    public function __construct(
        private int $instanceId,
        private callable $translateCallback,
        private callable $debugCallback
    ) {}

    private function t(string $s): string
    {
        return ($this->translateCallback)($s);
    }

    private function dbg(string $tag, string $msg): void
    {
        ($this->debugCallback)($tag, $msg);
    }
}
```

**Critical dependency — `firstNumericByPaths`:** `applyPresentation()` at lines ~809–811 calls `$this->firstNumericByPaths()`. This method is defined in `LG ThinQ Device/module.php` at lines 1386–1407. It must be duplicated as a private method in `ThinQPresentationBuilder` since PresentationBuilder calls it internally. Read lines 1386–1407 and copy verbatim.

**IPS global functions** called in these methods (`IPS_SetVariableCustomProfile`, `IPS_SetVariableCustomPresentation`, `@IPS_SetVariableCustomProfile`) are PHP global functions available in the Symcon runtime — they work fine from a non-IPSModule class.

**Steps:**
1. Read `module.php` lines 789–1192 to copy the 3 method bodies verbatim. Also read lines 1386–1407 to copy `firstNumericByPaths`.
2. Create `ThinQPresentationBuilder.php`. Replace all `$this->t()` calls with `$this->t()` (works via the inline helper). Replace all `$this->SendDebug()` calls with `$this->dbg()`. The constants `self::PRES_VALUE` etc. now refer to the class constants defined above.
3. Add `firstNumericByPaths` as a private method in the new class.
4. Add `require_once __DIR__ . '/libs/ThinQPresentationBuilder.php';` to `LG ThinQ Device/module.php` (after the existing require_once for CapabilityEngine.php).
5. In `module.php`, replace the bodies of `applyPresentation`, `translatePresentationPayload`, and `applyProfileFallback` with delegation calls:
   ```php
   private function applyPresentation(int $vid, string $ident, array $presentation, array $flatProfile, string $type): void
   {
       $builder = new ThinQPresentationBuilder(
           $this->InstanceID,
           fn(string $s) => $this->Translate($s),
           fn(string $tag, string $msg) => $this->SendDebug($tag, $msg, 0)
       );
       $builder->applyPresentation($vid, $ident, $presentation, $flatProfile, $type);
   }

   private function translatePresentationPayload(array $payload): array
   {
       $builder = new ThinQPresentationBuilder(
           $this->InstanceID,
           fn(string $s) => $this->Translate($s),
           fn(string $tag, string $msg) => $this->SendDebug($tag, $msg, 0)
       );
       return $builder->translatePresentationPayload($payload);
   }

   private function applyProfileFallback(int $vid, string $ident, array $presentation, string $type): void
   {
       $builder = new ThinQPresentationBuilder(
           $this->InstanceID,
           fn(string $s) => $this->Translate($s),
           fn(string $tag, string $msg) => $this->SendDebug($tag, $msg, 0)
       );
       $builder->applyProfileFallback($vid, $ident, $presentation, $type);
   }
   ```
   Note: these thin stubs (~5 lines each = 15 lines) replace ~404 lines of method bodies, saving ~389 lines from module.php.
6. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0 on new file. `wc -l` new file under 500. `wc -l` on `Device/module.php` decreases by ~389 lines.

---

### Task 8: Extract `ThinQEnergyManager.php` from `LG ThinQ Device/module.php`

**File to create:** `LG ThinQ Device/libs/ThinQEnergyManager.php`

**Projected size:** ~185 lines

**Read first:** `LG ThinQ Device/module.php` lines 1655–1832

**Methods to extract (D-10):**

| Method | Lines |
|--------|-------|
| `fetchEnergyProfile` | 1657–1670 |
| `getEnergyProperties` | 1672–1696 |
| `setupEnergyVariables` | 1698–1721 |
| `scheduleEnergyTimer` | 1723–1738 |
| `UpdateEnergy` (private implementation, see note) | 1740–1794 |
| `fetchEnergyUsage` | 1796–1831 |

**Note on `UpdateEnergy`:** `UpdateEnergy()` is a **public** method in `LGThinQDevice` (it is called by the IPS timer `LGTQD_UpdateEnergy`). Its public stub must remain in `module.php` with delegation. The implementation moves to `ThinQEnergyManager`. Rename the implementation method in EnergyManager to `run()` or `execute()`, and have `module.php::UpdateEnergy()` call `(new ThinQEnergyManager($this, $this->InstanceID))->execute()`.

**Constructor signature:**
```php
declare(strict_types=1);

class ThinQEnergyManager
{
    public function __construct(
        private IPSModule $module,
        private int $instanceId
    ) {}
}
```

**All `$this->ReadPropertyString()`, `$this->MaintainVariable()`, `$this->sendAction()`, `$this->getVarId()`, `$this->SetTimerInterval()`, `$this->SendDebug()`, `$this->t()` calls in the extracted methods must be replaced with `$this->module->ReadPropertyString()`, etc.** The `sendAction()` and `getVarId()` methods are private to LGThinQDevice — they cannot be called via `$this->module`. Two options:
- Add `public` visibility to `sendAction()` and `getVarId()` in `module.php` (minimal change)
- Pass them as callbacks in the constructor

**Recommended:** Make `sendAction()` and `getVarId()` accessible by passing them as additional callable constructor parameters:
```php
public function __construct(
    private IPSModule $module,
    private int $instanceId,
    private callable $sendActionCallback,
    private callable $getVarIdCallback
) {}
```
Then in `module.php`:
```php
new ThinQEnergyManager(
    $this,
    $this->InstanceID,
    fn(string $a, array $p = []) => $this->sendAction($a, $p),
    fn(string $ident) => $this->getVarId($ident)
)
```

**Steps:**
1. Read `module.php` lines 1655–1832 to copy the 6 method bodies.
2. Create `ThinQEnergyManager.php` with the constructor above. Translate all IPS calls to `$this->module->method()`. Replace `sendAction` and `getVarId` calls with `($this->sendActionCallback)(...)` and `($this->getVarIdCallback)(...)`.
3. In `module.php`, delete the 6 method bodies (lines 1657–1832). Replace with:
   - A thin `private function fetchEnergyProfile(...)` stub delegating to `ThinQEnergyManager`
   - A thin `private function getEnergyProperties()` stub
   - A thin `private function setupEnergyVariables()` stub
   - A thin `private function scheduleEnergyTimer()` stub
   - A thin `private function fetchEnergyUsage(...)` stub
   - Keep `public function UpdateEnergy(): void` in `module.php` with delegation:
     ```php
     public function UpdateEnergy(): void
     {
         (new ThinQEnergyManager($this, $this->InstanceID,
             fn(string $a, array $p = []) => $this->sendAction($a, $p),
             fn(string $ident) => $this->getVarId($ident)
         ))->execute();
     }
     ```
   - The other energy methods can remain as stubs or be removed from `module.php` entirely if only called from `UpdateEnergy`/`scheduleEnergyTimer` — verify call graph before removing.
4. Add `require_once __DIR__ . '/libs/ThinQEnergyManager.php';` to `module.php`.
5. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0. `wc -l` under 500. `UpdateEnergy` remains callable as `LGTQD_UpdateEnergy`.

---

### Task 9: Extract `ThinQSupportBundle.php` from `LG ThinQ Device/module.php`

**File to create:** `LG ThinQ Device/libs/ThinQSupportBundle.php`

**Projected size:** ~170 lines

**Read first:** `LG ThinQ Device/module.php` lines 1422–1482 (utility methods that SupportBundle uses) and 1484–1642

**Methods to extract (D-11):**

| Method | Lines |
|--------|-------|
| `UIExportSupportBundle` | 1484–1493 |
| `buildSupportBundleZip` | 1495–1639 |

**Shared utilities** used inside `buildSupportBundleZip`: `anonymizeArray` (1435–1455), `anonymizeText` (1457–1477), `maskText` (1422–1433), `logThrowable` (1479–1482), `flatten` (1371–1383). These are also used elsewhere in `module.php`, so they **stay in module.php**. In `ThinQSupportBundle`, call them via `$this->module->anonymizeArray()` etc. But these methods are **private** — they cannot be called via the module reference.

**Resolution:** Pass them as callbacks:
```php
declare(strict_types=1);

class ThinQSupportBundle
{
    public function __construct(
        private IPSModule $module,
        private callable $anonymizeCallback,
        private callable $flattenCallback,
        private callable $sendActionCallback
    ) {}
}
```
In `module.php`:
```php
new ThinQSupportBundle(
    $this,
    fn(array $a) => $this->anonymizeArray($a),
    fn(array $a, string $p = '') => $this->flatten($a, $p),
    fn(string $a, array $p = []) => $this->sendAction($a, $p)
)
```

**Note:** `UIExportSupportBundle()` is a **public** method (`LGTQD_UIExportSupportBundle` prefix). Keep the public stub in `module.php`. The implementation moves into `ThinQSupportBundle::export()`.

**Steps:**
1. Read `module.php` lines 1422–1642 to understand all dependencies of `buildSupportBundleZip`.
2. Create `ThinQSupportBundle.php` with the constructor above. Rename `UIExportSupportBundle` to `export()` and `buildSupportBundleZip` to `buildZip()` (or keep original names as public methods on the class). Translate IPS module calls via `$this->module->method()`. Replace `anonymizeArray`, `flatten`, `sendAction` calls with the callbacks.
3. In `module.php`, keep `public function UIExportSupportBundle(): string` as a public stub delegating to the bundle class.
4. Remove the body of `buildSupportBundleZip` from `module.php`.
5. Add `require_once __DIR__ . '/libs/ThinQSupportBundle.php';` to `module.php`.
6. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0. `wc -l` under 500. `UIExportSupportBundle` still callable as a public method.

---

### Task 10: Extract `ThinQDeviceProfileManager.php` from `LG ThinQ Device/module.php`

**File to create:** `LG ThinQ Device/libs/ThinQDeviceProfileManager.php`

**Projected size:** ~220 lines

**Read first:** `LG ThinQ Device/module.php` lines 685–788 and 1251–1370

**Methods to extract (additional extraction — not in CONTEXT.md D-09/D-10/D-11, required to reduce module.php further):**

| Method | Lines | Why extractable |
|--------|-------|-----------------|
| `fetchDeviceProfile` | 685–721 | Uses only `sendAction` + JSON ops |
| `resolveDeviceType` | 723–788 | Uses `sendAction` + `SendDebug` |
| `readStoredProfile` | 1251–1264 | Uses `ReadAttributeString` only |
| `statusHasNewProperties` | 1265–1305 | Pure logic + `flatten` |
| `fetchProfileFromAPI` | 1306–1337 | Uses `sendAction` |
| `readLastStatus` | 1338–1344 | Uses `ReadAttributeString` only |

**Constructor signature:**
```php
declare(strict_types=1);

class ThinQDeviceProfileManager
{
    public function __construct(
        private IPSModule $module,
        private callable $sendActionCallback,
        private callable $flattenCallback
    ) {}
}
```
In `module.php`:
```php
new ThinQDeviceProfileManager(
    $this,
    fn(string $a, array $p = []) => $this->sendAction($a, $p),
    fn(array $a, string $p = '') => $this->flatten($a, $p)
)
```

**IPS call mapping:**
- `$this->sendAction(...)` → `($this->sendActionCallback)(...)`
- `$this->flatten(...)` → `($this->flattenCallback)(...)`
- `$this->SendDebug(...)` → `$this->module->SendDebug(...)`
- `$this->ReadAttributeString(...)` → `$this->module->ReadAttributeString(...)`
- `$this->logThrowable(...)` → call `$this->module->LogMessage(...)` directly

**Steps:**
1. Read `module.php` lines 685–788 and 1251–1344 to copy the 6 method bodies.
2. Create `ThinQDeviceProfileManager.php`. Translate all IPS calls. Verify each method body works with constructor-injected dependencies.
3. In `module.php`, replace the 6 method bodies with thin stubs delegating to:
   ```php
   (new ThinQDeviceProfileManager(
       $this,
       fn(string $a, array $p = []) => $this->sendAction($a, $p),
       fn(array $a, string $p = '') => $this->flatten($a, $p)
   ))->methodName(...)
   ```
4. Add `require_once __DIR__ . '/libs/ThinQDeviceProfileManager.php';` to `module.php`.
5. Run `php -l` and `wc -l` on new file. Run `wc -l` on `module.php`.

**Verify:** `php -l` exits 0. `wc -l` new file under 500. `module.php` line count reduced by ~180 lines (stub wrappers replace method bodies).

---

### Task 11: Extract `ThinQDeviceUtil.php` from `LG ThinQ Device/module.php`

**File to create:** `LG ThinQ Device/libs/ThinQDeviceUtil.php`

**Projected size:** ~160 lines

**Read first:** `LG ThinQ Device/module.php` lines 1345–1482

**Methods to extract (additional extraction — not in original CONTEXT.md decisions):**

| Method | Lines | Notes |
|--------|-------|-------|
| `setValueByVarType` | 1345–1369 | Uses IPS `SetValue*` globals |
| `flatten` | 1371–1383 | Pure recursive function |
| `firstNumericByPaths` | 1386–1407 | Pure function |
| `updateReferences` | 1409–1420 | Uses `IPS_GetInstance`, `MaintainReferences` |
| `maskText` | 1422–1433 | Pure string function |
| `anonymizeArray` | 1435–1455 | Calls `anonymizeText` internally |
| `anonymizeText` | 1457–1477 | Calls `maskText` internally |
| `logThrowable` | 1479–1482 | Uses `$this->LogMessage` |
| `deepMerge` | 1643–1653 | Pure recursive function |

**Constructor signature:**
```php
declare(strict_types=1);

class ThinQDeviceUtil
{
    public function __construct(
        private IPSModule $module
    ) {}
}
```

**Notes:**
- `setValueByVarType` calls `SetValueBoolean`, `SetValueFloat`, etc. — these are global IPS functions that accept a variable ID and value, not IPSModule methods. They work fine in standalone classes.
- `updateReferences` calls `$this->MaintainReferences()` — this IS an IPSModule method. Use `$this->module->MaintainReferences()`.
- `logThrowable` calls `$this->LogMessage()` — IPSModule method. Use `$this->module->LogMessage()`.
- `anonymizeArray` calls `$this->anonymizeText()` and `anonymizeText` calls `$this->maskText()` — these are all internal calls within the new class, so `$this->method()` still works as-is after extraction.
- `flatten` is also used in `ThinQPresentationBuilder` (Task 7 duplicates `firstNumericByPaths`). If PresentationBuilder already duplicates `firstNumericByPaths`, that is fine.

**In `module.php`:** After extraction, all callers of `flatten`, `anonymizeArray`, `maskText`, `firstNumericByPaths`, `deepMerge` in `module.php` must delegate to `ThinQDeviceUtil`. Since these methods are called many times, add a private factory helper to avoid constructing the object repeatedly:
```php
private function util(): ThinQDeviceUtil
{
    return new ThinQDeviceUtil($this);
}
```
Then callers: `$this->util()->flatten($data)`, `$this->util()->anonymizeArray($data)`, etc.

**Steps:**
1. Read `module.php` lines 1345–1482 and 1643–1653 to copy the 9 method bodies.
2. Create `ThinQDeviceUtil.php`. Apply the IPS call translations above. Verify `anonymizeArray` → `anonymizeText` → `maskText` chain works as internal `$this->method()` calls.
3. In `module.php`, delete the 9 method bodies. Add the `util()` factory helper. Update all call sites that formerly used `$this->flatten(...)`, `$this->anonymizeArray(...)`, etc. to use `$this->util()->method(...)`.
4. Add `require_once __DIR__ . '/libs/ThinQDeviceUtil.php';` to `module.php`.
5. Run `php -l` and `wc -l` on new file. Run `wc -l` on `module.php`.

**Final expected `module.php` size after Tasks 7–11:** approximately **640 lines**.

**Note:** This is above the 500-line target. The remaining ~640 lines consist entirely of IPSModule lifecycle methods (`Create`, `ApplyChanges`, `MessageSink`, `Destroy`), IPS protocol methods (`ReceiveData`, `RequestAction`, `sendAction`), and public device API methods (`UpdateStatus`, `ControlDevice`, `AutoSubscribe`, `CleanupVariables`, `setupDevice`, `ensureDeviceVariablesWithPresentations`, `prepareEngine`, `getCapabilityEngine`, `doAutoSubscribe`, `ensureVariable`, `getVarId`, thin delegation stubs). None of these can be extracted to standalone classes. Reaching 500 lines would require splitting the `LGThinQDevice` class itself across PHP files, which is not supported by IP-Symcon's module loading contract. CAPE-03 for Device/module.php is partially met.

**Verify:** `php -l` exits 0 on new file. `wc -l` new file under 500. `module.php` line count reduced by ~130 lines (delta from stubs replacing bodies).

---

### Task 12: Extract `ThinQMqttSetupWizard.php` from `LG ThinQ Bridge/module.php`

**File to create:** `LG ThinQ Bridge/libs/ThinQMqttSetupWizard.php`

**Projected size:** ~487 lines

**Read first:** `LG ThinQ Bridge/module.php` lines 1168–1729

**Methods to extract (D-12):**

| Method | Lines | Notes |
|--------|-------|-------|
| `UISetupMqttConnection` | 1168–1488 | Public method — stub stays in module.php |
| `generateMqttClientCertMaterial` | 1493–1544 | Private helper |
| `extractBrokerFromSubscriptions` | 1550–1577 | Private helper |
| `fetchRouteBroker` | 1583–1651 | Private helper |
| `instancesOf` | 1682–1685 | Private helper |
| `cfg` | 1691–1695 | Private helper |
| `safeSetProperty` | 1698–1703 | Private helper |
| `setFirstAvailableProperty` | 1706–1711 | Private helper |
| `setJsonCompatibleProperty` | 1714–1721 | Private helper |
| `isInstanceOfModule` | 1724–1728 | Private helper |

**Four thin delegators NOT extracted** (per RESEARCH.md resolution — inline them):
- `ensureCertificatePEM` (1659–1662)
- `ensurePrivateKeyPEM` (1664–1667)
- `extractCAPEMFromSubscriptions` (1669–1672)
- `downloadAmazonRootCA1` (1674–1677)

These 4 methods each call `$this->getCertificateManager()->method()`. In `ThinQMqttSetupWizard`, call `(new ThinQCertificateManager($this->instanceId))->method()` directly at the call sites inside `UISetupMqttConnection`. Do not add them as named methods on the Wizard class.

**Constructor signature:**
```php
declare(strict_types=1);

class ThinQMqttSetupWizard
{
    public function __construct(
        private IPSModule $module,
        private int $instanceId,
        private string $apiKey
    ) {}
}
```

**IPS call mapping** for all extracted methods:
- `$this->ReadPropertyString(...)` → `$this->module->ReadPropertyString(...)`
- `$this->ReadPropertyBoolean(...)` → `$this->module->ReadPropertyBoolean(...)`
- `$this->ReadPropertyInteger(...)` → `$this->module->ReadPropertyInteger(...)`
- `$this->ReadAttributeString(...)` → `$this->module->ReadAttributeString(...)`
- `$this->WriteAttributeString(...)` → `$this->module->WriteAttributeString(...)`
- `$this->SendDebug(...)` → `$this->module->SendDebug(...)`
- `$this->NotifyUser(...)` → `$this->module->NotifyUser(...)`
- `$this->findModuleGUIDByName(...)` → `$this->module->findModuleGUIDByName(...)` (trait method on module)
- `$this->httpClient` — NOT available; replace with inline HTTP calls using `new ThinQHttpClient($this->module, $config, $this->apiKey)` where needed
- `$this->ensureBooted()` / `$this->createBridgeConfig()` — NOT available outside module class; pass the config object as a constructor parameter instead:
  ```php
  public function __construct(
      private IPSModule $module,
      private int $instanceId,
      private string $apiKey,
      private ThinQBridgeConfig $config,
      private ThinQHttpClient $httpClient
  ) {}
  ```
  In `module.php`:
  ```php
  $this->ensureBooted();
  (new ThinQMqttSetupWizard($this, $this->InstanceID, self::API_KEY, $this->config, $this->httpClient))->run();
  ```

**Note:** `UISetupMqttConnection()` is a **public** method. Keep its stub in `module.php`:
```php
public function UISetupMqttConnection(): void
{
    $this->ensureBooted();
    (new ThinQMqttSetupWizard(
        $this,
        $this->InstanceID,
        self::API_KEY,
        $this->config,
        $this->httpClient
    ))->run();
}
```
Rename `UISetupMqttConnection` to `run()` inside the Wizard class.

**Steps:**
1. Read `module.php` lines 1168–1729 in full to copy all method bodies.
2. Create `ThinQMqttSetupWizard.php` with the constructor above. Rename `UISetupMqttConnection` body to `run()`. Apply all IPS call translations. Inline the 4 thin delegators as direct `ThinQCertificateManager` calls.
3. Run `wc -l` immediately. Must be under 500. If borderline, verify the 4-delegator inlining saved ~20 lines.
4. In `module.php`, replace all extracted method bodies with the single stub for `UISetupMqttConnection` shown above. Remove the 10 extracted method bodies (lines 1168–1729).
5. Add `require_once __DIR__ . '/libs/ThinQMqttSetupWizard.php';` to `module.php` (after existing require_once lines at top).
6. Run `php -l` and `wc -l` on new file.

**Verify:** `php -l` exits 0. `wc -l` under 500. `UISetupMqttConnection` still a public method on `LGThinQBridge`.

---

### Task 13: Extract `ThinQMqttCertBuilder.php` from `LG ThinQ Bridge/module.php`

**File to create:** `LG ThinQ Bridge/libs/ThinQMqttCertBuilder.php`

**Projected size:** ~340 lines

**Read first:** `LG ThinQ Bridge/module.php` lines 835–1166

**Methods to extract (additional extraction — not in original D-12):**

| Method | Lines | Notes |
|--------|-------|-------|
| `buildMQTTClientCertsZip` | 846–1165 | Implementation |

**Note:** `UIGenerateMQTTClientCerts()` (lines 835–844) is a **public** method — its stub stays in `module.php` unchanged. Only `buildMQTTClientCertsZip` (the private implementation) moves.

**Constructor signature:**
```php
declare(strict_types=1);

class ThinQMqttCertBuilder
{
    public function __construct(
        private IPSModule $module,
        private int $instanceId
    ) {}
}
```

**IPS call mapping:**
- `$this->ReadAttributeString(...)` → `$this->module->ReadAttributeString(...)`
- `$this->ReadPropertyString(...)` → `$this->module->ReadPropertyString(...)`
- `$this->ReadPropertyBoolean(...)` → `$this->module->ReadPropertyBoolean(...)`
- `$this->SendDebug(...)` → `$this->module->SendDebug(...)`
- `$this->t(...)` → add a private `t()` helper calling `$this->module->Translate(...)` (trait method)
- `$this->debugMqttParentInfo()` → either inline or pass as callback. Preferred: pass as callable:
  ```php
  public function __construct(
      private IPSModule $module,
      private int $instanceId,
      private callable $debugMqttInfoCallback
  ) {}
  ```
  In `module.php`:
  ```php
  new ThinQMqttCertBuilder(
      $this,
      $this->InstanceID,
      fn() => $this->debugMqttParentInfo()
  )
  ```
- `$this->InstanceID` inside `buildMQTTClientCertsZip` → `$this->instanceId`

**Steps:**
1. Read `module.php` lines 835–1167 to copy `buildMQTTClientCertsZip` body.
2. Create `ThinQMqttCertBuilder.php`. Rename `buildMQTTClientCertsZip` to `build()`. Apply all IPS call translations.
3. In `module.php`, update `UIGenerateMQTTClientCerts()` to delegate to the builder:
   ```php
   public function UIGenerateMQTTClientCerts(): string
   {
       try {
           $zipData = (new ThinQMqttCertBuilder(
               $this,
               $this->InstanceID,
               fn() => $this->debugMqttParentInfo()
           ))->build();
           return 'data:application/zip;base64,' . base64_encode($zipData);
       } catch (\Throwable $e) {
           $this->SendDebug('UIGenerateMQTTClientCerts', $e->getMessage(), 0);
           return 'data:text/plain,' . rawurlencode($this->t('Error generating certificates') . ': ' . $e->getMessage());
       }
   }
   ```
   Remove the `buildMQTTClientCertsZip` method body from `module.php`.
4. Add `require_once __DIR__ . '/libs/ThinQMqttCertBuilder.php';` to `module.php`.
5. Run `php -l` and `wc -l` on new file. Run `wc -l` on `Bridge/module.php`.

**Final expected `Bridge/module.php` size after Tasks 12–13:** approximately **839 lines**.

**Note:** Like Device/module.php, the remaining lines in Bridge/module.php are all IPSModule lifecycle and protocol methods (`Create`, `ApplyChanges`, `ForwardData`, `ReceiveData`, `bootServices`, `createBridgeConfig`, `ensureBooted`, `configureTimers`, `ensureMqttParent`, `fetchDevices`, `fetchDeviceStatus`, `handleMetaEvent`, plus all public API methods such as `SyncDevices`, `SubscribeDevice`, `SubscribeAll`, `RenewAll`, etc.). These require `$this->ReadPropertyString`, `$this->SendDataToChildren`, `$this->httpClient`, and similar module-level state. They cannot be extracted to standalone classes. CAPE-03 for Bridge/module.php is partially met.

**Verify:** `php -l` exits 0 on new file. `wc -l` new file under 500. `UIGenerateMQTTClientCerts` still callable as a public method.

---

### Task 14: Verify All Files Under 500 Lines and php -l Clean

**This task must run after all previous tasks are complete.**

**Steps:**

1. Run `wc -l` on every new and modified file:

```bash
wc -l \
  "LG ThinQ Device/libs/CapabilityProfileExtractor.php" \
  "LG ThinQ Device/libs/CapabilityCatalogLoader.php" \
  "LG ThinQ Device/libs/CapabilityPlanBuilder.php" \
  "LG ThinQ Device/libs/CapabilityVarManager.php" \
  "LG ThinQ Device/libs/CapabilityControlBuilder.php" \
  "LG ThinQ Device/libs/CapabilityEngine.php" \
  "LG ThinQ Device/libs/ThinQPresentationBuilder.php" \
  "LG ThinQ Device/libs/ThinQEnergyManager.php" \
  "LG ThinQ Device/libs/ThinQSupportBundle.php" \
  "LG ThinQ Device/libs/ThinQDeviceProfileManager.php" \
  "LG ThinQ Device/libs/ThinQDeviceUtil.php" \
  "LG ThinQ Device/module.php" \
  "LG ThinQ Bridge/libs/ThinQMqttSetupWizard.php" \
  "LG ThinQ Bridge/libs/ThinQMqttCertBuilder.php" \
  "LG ThinQ Bridge/module.php"
```

Expected under 500: all new libs. Expected over 500: `Device/module.php` (~640) and `Bridge/module.php` (~839) — document the residual count and reason.

2. Run `php -l` on every new file and every modified file:

```bash
php -l "LG ThinQ Device/libs/CapabilityProfileExtractor.php"
php -l "LG ThinQ Device/libs/CapabilityCatalogLoader.php"
php -l "LG ThinQ Device/libs/CapabilityPlanBuilder.php"
php -l "LG ThinQ Device/libs/CapabilityVarManager.php"
php -l "LG ThinQ Device/libs/CapabilityControlBuilder.php"
php -l "LG ThinQ Device/libs/CapabilityEngine.php"
php -l "LG ThinQ Device/libs/ThinQPresentationBuilder.php"
php -l "LG ThinQ Device/libs/ThinQEnergyManager.php"
php -l "LG ThinQ Device/libs/ThinQSupportBundle.php"
php -l "LG ThinQ Device/libs/ThinQDeviceProfileManager.php"
php -l "LG ThinQ Device/libs/ThinQDeviceUtil.php"
php -l "LG ThinQ Device/module.php"
php -l "LG ThinQ Bridge/libs/ThinQMqttSetupWizard.php"
php -l "LG ThinQ Bridge/libs/ThinQMqttCertBuilder.php"
php -l "LG ThinQ Bridge/module.php"
```

All must exit 0. Zero syntax errors allowed.

3. Verify `CapabilityEngine.php` public API is intact — grep confirms all 12 public method names are still present:

```bash
grep -n "public function" "LG ThinQ Device/libs/CapabilityEngine.php"
```

Must include: `buildPlan`, `applyStatus`, `ensureVariables`, `buildControlPayload`, `getDescriptors`, `getPresentationMap`, `reassertActionsOnSetup`, `listIdentsToEnableOnSetup`, `listIdentsToEnable`, `setMaintainVariableCallback`, `setTranslateCallback`, `loadCapabilities`.

4. Verify `Device/module.php` public API is intact:

```bash
grep -n "public function" "LG ThinQ Device/module.php"
```

Must include: `LGTQD_` prefix is applied externally by IPS; confirm `UpdateEnergy`, `UIExportSupportBundle`, `AutoSubscribe`, `CleanupVariables`, `ControlDevice`, `RequestAction`, `ReceiveData`, `UpdateStatus`, `FinalizeSetup`, `InitialSetup`, `ReapplyPresentations`, `UICleanupPreview` are all present.

5. Verify `Bridge/module.php` public API is intact:

```bash
grep -n "public function" "LG ThinQ Bridge/module.php"
```

Must include: `UISetupMqttConnection`, `UIGenerateMQTTClientCerts`, `ForwardData`, `ReceiveData`, `TestConnection`, `SyncDevices`, `Update`, `SubscribeAll`, `UnsubscribeAll`, `RenewAll`, `RenewEvents`, `SubscribeDevice`, `UnsubscribeDevice`, `GetDevices`, `GetDeviceStatus`, `GetDeviceProfile`, `ControlDevice`.

---

## Summary of New Files

### `LG ThinQ Device/libs/` (CapabilityEngine sub-classes)

| File | Class | Methods | Lines |
|------|-------|---------|-------|
| `CapabilityProfileExtractor.php` | `CapabilityProfileExtractor` | 10 extract/humanize methods | ~306 |
| `CapabilityCatalogLoader.php` | `CapabilityCatalogLoader` | 5 catalog/resolve methods + static $catalog | ~200 |
| `CapabilityPlanBuilder.php` | `CapabilityPlanBuilder` | buildPlan + 6 companion methods | ~475 |
| `CapabilityVarManager.php` | `CapabilityVarManager` | 14 variable management methods | ~402 |
| `CapabilityControlBuilder.php` | `CapabilityControlBuilder` | buildControlPayload + 6 helpers + dispatchFirstOfOption | ~496 |

### `LG ThinQ Device/libs/` (Device module extractions)

| File | Class | Methods | Lines |
|------|-------|---------|-------|
| `ThinQPresentationBuilder.php` | `ThinQPresentationBuilder` | applyPresentation, translatePresentationPayload, applyProfileFallback, firstNumericByPaths | ~415 |
| `ThinQEnergyManager.php` | `ThinQEnergyManager` | 6 energy methods (execute, fetchEnergyProfile, etc.) | ~185 |
| `ThinQSupportBundle.php` | `ThinQSupportBundle` | export, buildZip | ~170 |
| `ThinQDeviceProfileManager.php` | `ThinQDeviceProfileManager` | 6 profile/type resolution methods | ~220 |
| `ThinQDeviceUtil.php` | `ThinQDeviceUtil` | 9 utility methods (flatten, anonymize, etc.) | ~160 |

### `LG ThinQ Bridge/libs/` (Bridge module extractions)

| File | Class | Methods | Lines |
|------|-------|---------|-------|
| `ThinQMqttSetupWizard.php` | `ThinQMqttSetupWizard` | run + 9 private helpers | ~487 |
| `ThinQMqttCertBuilder.php` | `ThinQMqttCertBuilder` | build (formerly buildMQTTClientCertsZip) | ~340 |

---

## Verification

- [ ] `wc -l` on all 12 new lib files — each under 500
- [ ] `php -l` on all 12 new lib files — exit 0
- [ ] `php -l` on `CapabilityEngine.php` — exit 0
- [ ] `php -l` on `Device/module.php` — exit 0
- [ ] `php -l` on `Bridge/module.php` — exit 0
- [ ] `CapabilityEngine.php` has all 12 public methods (per D-02 list)
- [ ] `Device/module.php` has all public methods unchanged (LGTQD_ API)
- [ ] `Bridge/module.php` has all public methods unchanged (LGTQ_ API)
- [ ] `LG ThinQ Device/libs/CapabilityEngine.php` has 8 require_once lines at top
- [ ] `LG ThinQ Device/module.php` has require_once lines for all 5 new Device libs
- [ ] `LG ThinQ Bridge/module.php` has require_once lines for both new Bridge libs
- [ ] No file in the include chain is required twice (no class redeclaration fatal)
- [ ] `Device/module.php` final line count documented (expected ~640, not a failure)
- [ ] `Bridge/module.php` final line count documented (expected ~839, not a failure)

---

## Constraints Reminder

- `declare(strict_types=1)` as first statement in every new file
- No IPSModule inheritance in any lib class
- Typed function signatures on all methods
- `parent::Create()`, `parent::ApplyChanges()`, `parent::Destroy()` — not applicable to lib classes (not IPSModule subclasses)
- `static ?array $catalog` stays on `CapabilityCatalogLoader`, not on the Coordinator
- No Composer, no autoloader — all files via `require_once`
- Public method names on `CapabilityEngine`, `LGThinQDevice`, `LGThinQBridge` must not change
- Module GUIDs, property names, and variable Idents must not change

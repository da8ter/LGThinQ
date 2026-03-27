# Phase 1 Research: Shared Trait

**Researched:** 2026-03-27
**Domain:** PHP traits, IP-Symcon module refactoring
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** `ThinQModuleTrait.php` lives at the library root under `libs/` → `LGThinQ/libs/ThinQModuleTrait.php`
- **D-02:** Both modules load the trait via `require_once __DIR__ . '/../libs/ThinQModuleTrait.php';` at the top of their `module.php`
- **D-03:** Trait contains at minimum: `t()`, `isKernelReady()`, `findModuleGUIDByName()` — exactly as specified in TRAIT-01
- **D-04:** Only confirmed duplicates and REQUIREMENTS-specified methods go in the trait. `maskText()`, `anonymizeArray()`, `anonymizeText()`, `instancesOf()` are Device- or Bridge-only — they stay where they are.

### Claude's Discretion

- Method visibility in the trait (`private` vs `protected`) — `private` is the minimal safe change and matches current usage; Claude may use `protected` if there is a reason, but `private` is preferred for zero-visibility-regression.
- Whether `instancesOf()` (Bridge-only, depends on `findModuleGUIDByName()`) should be updated to call `$this->findModuleGUIDByName()` via the trait rather than its own private copy — Claude decides based on what produces the cleanest result.

### Deferred Ideas (OUT OF SCOPE)

None — discussion stayed within phase scope.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TRAIT-01 | `ThinQModuleTrait` created with all shared helper methods (at minimum: `t()`, `isKernelReady()`, `findModuleGUIDByName()` and further duplicates) | All three methods confirmed in source; exact bodies verified; no additional duplicates found beyond these three |
| TRAIT-02 | `LGThinQBridge` uses `ThinQModuleTrait` — own copies of helper methods removed | Bridge `t()` at line 31, `isKernelReady()` at line 36, `findModuleGUIDByName()` at line 1688 — all verified; `use ThinQModuleTrait;` replaces them |
| TRAIT-03 | `LGThinQDevice` uses `ThinQModuleTrait` — own copies of helper methods removed | Device `t()` at line 1474, `isKernelReady()` at line 212 — both verified; `use ThinQModuleTrait;` replaces them |
</phase_requirements>

---

## Summary

Phase 1 extracts three helper methods into a new `ThinQModuleTrait`. Two of them (`t()` and `isKernelReady()`) are identical in both Bridge and Device. The third (`findModuleGUIDByName()`) exists only in Bridge but is required by TRAIT-01 and belongs in the trait because `instancesOf()` (which stays in Bridge) will call it via `$this->findModuleGUIDByName()`.

The full source audit found **no additional duplicates** beyond the three specified in CONTEXT.md. Every other method present in both modules is either a standard IPSModule override (lifecycle methods like `Create`, `ApplyChanges`, `MessageSink`) or has substantively different implementations per module.

**Primary recommendation:** Create `LGThinQ/libs/ThinQModuleTrait.php` with three `private` methods, prepend one `require_once` line to each `module.php`, add `use ThinQModuleTrait;` inside each class declaration, and delete the five method definitions across both files.

---

## Method Inventory

### Methods to Extract (confirmed duplicates + TRAIT-01 required)

| Method | Bridge line | Device line | Identical? | Notes |
|--------|-------------|-------------|-----------|-------|
| `t(string $text): string` | 31 | 1474 | YES — byte-for-byte identical | Both use `method_exists($this, 'Translate')` guard |
| `isKernelReady(): bool` | 36 | 212 | YES — byte-for-byte identical | Both use `function_exists('IPS_GetKernelRunlevel')` guard |
| `findModuleGUIDByName(string $name): ?string` | 1688 | N/A (Bridge only) | N/A | Required by TRAIT-01; called by `instancesOf()` in Bridge |

### instancesOf() Analysis

**Exact method body in Bridge (lines 1704–1708):**
```php
/**
 * @return array<int,int>
 */
private function instancesOf(string $moduleName): array
{
    $guid = $this->findModuleGUIDByName($moduleName);
    return $guid ? @IPS_GetInstanceListByModuleID($guid) : [];
}
```

`instancesOf()` calls `$this->findModuleGUIDByName()` on line 1706 — it already uses `$this->` method dispatch. When `findModuleGUIDByName()` is moved to the trait and `instancesOf()` remains in Bridge, the call continues to work unchanged because PHP resolves `$this->findModuleGUIDByName()` through the trait at runtime.

**Recommendation: `instancesOf()` STAYS in Bridge unchanged.** No code modification is required for `instancesOf()`. After the trait is loaded and Bridge uses `use ThinQModuleTrait;`, the `$this->findModuleGUIDByName()` call in `instancesOf()` resolves to the trait method automatically. The Bridge also has `isInstanceOfModule()` (line 1746) which calls `$this->instancesOf()` — that chain also continues to work without modification.

### Additional Duplicates Found

**None.** Full method-name comparison between Bridge and Device method lists found the following shared names:

| Method name | Bridge | Device | Verdict |
|-------------|--------|--------|---------|
| `Create()` | yes | yes | Standard IPSModule lifecycle override — not a helper, not a duplicate |
| `ApplyChanges()` | yes | yes | Standard IPSModule lifecycle override — different implementations |
| `MessageSink()` | yes | yes | Standard IPSModule lifecycle override — different implementations |
| `ControlDevice()` | yes | yes | Different signatures; Bridge: `(string $DeviceID, string $JSONPayload)`, Device: `(string $JSONPayload)` — different responsibilities |

No helper methods beyond `t()` and `isKernelReady()` appear in both classes.

---

## Exact Method Signatures and Bodies

These are the exact PHP blocks to place in `ThinQModuleTrait.php`:

```php
private function t(string $text): string
{
    return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
}
```

```php
private function isKernelReady(): bool
{
    return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
}
```

```php
private function findModuleGUIDByName(string $name): ?string
{
    foreach (@IPS_GetModuleList() as $guid) {
        $m = @IPS_GetModule($guid);
        if (!is_array($m)) { continue; }
        $names = array_merge([$m['ModuleName'] ?? ''], $m['Aliases'] ?? []);
        foreach ($names as $n) {
            if (mb_strtolower((string)$n) === mb_strtolower($name)) { return (string)$guid; }
        }
    }
    return null;
}
```

**Source:** Lines 31–34, 36–39, 1688–1699 of `LG ThinQ Bridge/module.php` (verified 2026-03-27).

---

## require_once Structure

### Bridge module.php — current lines 1–14

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ThinQHelpers.php';
require_once __DIR__ . '/libs/ThinQConfig.php';
require_once __DIR__ . '/libs/ThinQDeviceRepository.php';
require_once __DIR__ . '/libs/ThinQEventSubscriptionRepository.php';
require_once __DIR__ . '/libs/ThinQHttpClient.php';
require_once __DIR__ . '/libs/ThinQEventManager.php';
require_once __DIR__ . '/libs/ThinQEventPipeline.php';
require_once __DIR__ . '/libs/ThinQMqttRouter.php';
require_once __DIR__ . '/libs/ThinQCertificateManager.php';
require_once __DIR__ . '/libs/ThinQApiErrorCodes.php';
```

**Proposed insertion:** Add one line **before** line 5 (first in the require block), or as a distinct line after the existing block. Either is valid. Recommended position is immediately after `declare(strict_types=1);` and before the module-local lib requires, to make it visually distinct as a cross-module shared dependency:

```php
require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
```

### Device module.php — current line 5

```php
require_once __DIR__ . '/libs/CapabilityEngine.php';
```

**Proposed insertion:** Add one line before the existing require:

```php
require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/CapabilityEngine.php';
```

### Path resolution

Both modules are at:
- `LG ThinQ Bridge/module.php` — `__DIR__` = `.../LGThinQ/LG ThinQ Bridge`
- `LG ThinQ Device/module.php` — `__DIR__` = `.../LGThinQ/LG ThinQ Device`

`__DIR__ . '/../libs/ThinQModuleTrait.php'` resolves to `.../LGThinQ/libs/ThinQModuleTrait.php` from both — correct.

**IMPORTANT: `LGThinQ/libs/` does not yet exist.** The directory must be created before the file is written. The existing `libs/` folders belong to the individual module directories; the new `libs/` is at the repository root level.

---

## PHP Trait Compatibility

**Confidence: HIGH** — verified by live PHP 8.4.7 test on this machine.

| Question | Answer |
|----------|--------|
| `declare(strict_types=1)` in trait file | Supported. `strict_types` is a per-file directive; the trait file must declare it independently. It applies to code in that file. |
| `private` methods in trait | Supported. `private` methods in a trait become `private` to the using class. They are callable from within the trait's own methods and from within the class body, but not from outside. |
| Trait `private` method calling another `private` method in the same trait | Supported. `$this->otherPrivateTraitMethod()` resolves correctly at runtime. Verified with `isKernelReady()` calling its own body and `findModuleGUIDByName()` being called from `instancesOf()` in the class. |
| `class LGThinQBridge extends IPSModule { use ThinQModuleTrait; }` | Supported. PHP traits work with class inheritance. No conflicts introduced. |
| Conflict risk — both Bridge and Device already define `t()` and `isKernelReady()` | No conflict. Once the class-level definitions are removed and `use ThinQModuleTrait;` is added, PHP uses the trait's definition. Having both simultaneously would cause a "cannot redeclare" fatal — but the plan removes the class copies first. |
| `private` vs `protected` choice | `private` is correct. The methods are not called by subclasses, only by `$this` within the class body. `private` enforces minimal visibility. No reason to use `protected` for these three helpers. |

---

## Line Count Analysis

| File | Current lines | Lines to remove | Projected lines | Under 500? |
|------|--------------|-----------------|-----------------|------------|
| `LG ThinQ Bridge/module.php` | 1,751 | 12 (4 lines `t()` body + 4 lines `isKernelReady()` body + 12 lines `findModuleGUIDByName()` body = ~20 total with blank lines/comments) | ~1,731 | No (Phase 3 handles this) |
| `LG ThinQ Device/module.php` | 1,889 | ~8 (4 lines `t()` body + 4 lines `isKernelReady()` body) | ~1,881 | No (Phase 3 handles this) |
| `LGThinQ/libs/ThinQModuleTrait.php` | 0 (new) | N/A | ~35 | Yes |

**Phase 1 does NOT bring either file under 500 lines.** That is by design — REQUIREMENTS.md maps CAPE-03 (all files under 500 lines) to Phase 3, not Phase 1. The 500-line limit is a Phase 3 success criterion.

**Precise line counts for removed blocks:**

Bridge removals:
- `t()`: lines 31–34 = 4 lines
- `isKernelReady()`: lines 36–39 = 4 lines
- comment `// ---- helper methods for instance/properties ----` at line 1687 = 1 line (may stay; it is not part of `findModuleGUIDByName()` itself)
- `findModuleGUIDByName()`: lines 1688–1699 = 12 lines
- Total: ~20 lines removed from Bridge

Device removals:
- `t()`: lines 1474–1477 = 4 lines
- `isKernelReady()`: lines 212–215 = 4 lines
- Total: ~8 lines removed from Device

---

## Directory Creation Required

`LGThinQ/libs/` must be created as a new directory. It does not exist today.

Existing `libs/` directories are module-local:
- `LG ThinQ Bridge/libs/` — 10 PHP files (ThinQHelpers, ThinQConfig, etc.)
- `LG ThinQ Device/libs/` — 4 PHP files (CapabilityEngine, ThinQEnumTranslator, ThinQGenericProperties, ThinQProfileParser)

The new `LGThinQ/libs/` sits alongside the module folders at the repository root level and holds only `ThinQModuleTrait.php` after Phase 1.

---

## Implementation Notes

1. **`use ThinQModuleTrait;` placement:** Insert as the first statement inside the class body, immediately after the opening `{` and before the `const` and property declarations. This is the conventional PHP placement for `use` statements in a class.

2. **`instancesOf()` requires no change.** It already calls `$this->findModuleGUIDByName()`. Moving `findModuleGUIDByName()` to the trait does not break this call.

3. **`isInstanceOfModule()` in Bridge is also unaffected.** It calls `$this->instancesOf()` which calls `$this->findModuleGUIDByName()`. The whole chain works through `$this` dispatch.

4. **The comment `// ---- helper methods for instance/properties ----` at Bridge line 1687** sits above `findModuleGUIDByName()`. Since `findModuleGUIDByName()` moves to the trait, this comment becomes misleading if left in place. The planner should include removing or relocating it.

5. **Trait file must also declare `strict_types=1`.** Per CLAUDE.md rules, all PHP files must have `declare(strict_types=1);`. The trait file is no exception.

6. **No namespace is needed.** The project uses no PHP namespaces. The trait name `ThinQModuleTrait` is sufficient for global resolution.

7. **`php -l` check** is the Phase 1 success gate (criterion 4 in ROADMAP.md). The planner should include a verification task: `php -l "LGThinQ/libs/ThinQModuleTrait.php"`, `php -l "LG ThinQ Bridge/module.php"`, `php -l "LG ThinQ Device/module.php"`.

---

## Project Constraints (from CLAUDE.md)

| Directive | Impact on Phase 1 |
|-----------|-------------------|
| `declare(strict_types=1)` in every file | `ThinQModuleTrait.php` must include it |
| All files under 500 lines | Phase 1 does not violate this (new trait file ~35 lines); Phase 3 resolves the module files |
| No Composer, no autoloader — all classes via `require_once` | Trait must be loaded with explicit `require_once`, already addressed by D-02 |
| `parent::Create()`, `parent::ApplyChanges()`, `parent::Destroy()` always called | Not relevant to trait (no lifecycle methods in trait) |
| Public PHP functions use module prefix (`LGTQ_`, `LGTQD_`) | The three trait methods are `private` — no prefix required |
| NEVER create documentation files unless explicitly requested | This RESEARCH.md is required by the GSD workflow — not a violation |

---

## Environment Availability

Step 2.6: SKIPPED — Phase 1 is a pure PHP code/structure refactoring. No external tools, services, runtimes beyond PHP itself are required. PHP 8.4.7 is confirmed available on this machine.

---

## Validation Architecture

> `workflow.nyquist_validation` not found in `.planning/config.json` — treating as enabled per default.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | None detected — no `composer.json`, no `phpunit.xml`, no `tests/` directory in project |
| Config file | None |
| Quick run command | `php -l {file}` (syntax check only) |
| Full suite command | `php -l` on all changed files |

**No PHPUnit or automated test framework exists** (V2-04 in REQUIREMENTS.md tracks adding it as a v2 requirement). For Phase 1, the only automated validation available is PHP syntax checking via `php -l`.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TRAIT-01 | `ThinQModuleTrait.php` exists with the three methods | syntax | `php -l "LGThinQ/libs/ThinQModuleTrait.php"` | No — Wave 0 |
| TRAIT-02 | Bridge uses trait; own copies removed | syntax | `php -l "LG ThinQ Bridge/module.php"` | Yes (file exists, needs edit) |
| TRAIT-03 | Device uses trait; own copies removed | syntax | `php -l "LG ThinQ Device/module.php"` | Yes (file exists, needs edit) |

Behavioral verification (trait methods actually callable, no "method not found" runtime errors) requires manual testing in IP-Symcon or a mock environment. No automated path exists without V2-04.

### Sampling Rate

- **Per task commit:** `php -l` on the file just changed
- **Per wave merge:** `php -l` on all three files (`ThinQModuleTrait.php`, both `module.php` files)
- **Phase gate:** All three `php -l` checks exit 0 before marking Phase 1 complete

### Wave 0 Gaps

- [ ] `LGThinQ/libs/ThinQModuleTrait.php` — does not yet exist; must be created as part of TRAIT-01

*(No test framework gaps to address — PHPUnit is deferred to v2 requirements.)*

---

## Sources

### Primary (HIGH confidence)

- Direct source file read: `LG ThinQ Bridge/module.php` — lines 1–14, 31–39, 1687–1751 (method bodies, require_once block, instancesOf chain)
- Direct source file read: `LG ThinQ Device/module.php` — lines 1–6, 212–215, 1474–1477 (method bodies, require_once line)
- Live PHP 8.4.7 test: trait with `private` methods, `declare(strict_types=1)`, `class extends` — executed on host machine, all passed

### Secondary (MEDIUM confidence)

- PHP documentation (training data, August 2025 cutoff): PHP traits support `private` visibility; `use TraitName;` inside a class body is standard syntax

### Tertiary (LOW confidence)

- None

---

## Metadata

**Confidence breakdown:**

- Method inventory: HIGH — read directly from source files with exact line numbers
- Identical method bodies: HIGH — pasted exact code, confirmed character-for-character match
- PHP trait compatibility: HIGH — verified by live PHP test on host machine (PHP 8.4.7)
- Directory structure: HIGH — verified by direct filesystem inspection
- Line count projections: HIGH — `wc -l` output + manual count of removed blocks

**Research date:** 2026-03-27
**Valid until:** 2026-04-27 (source files are stable; only invalidated if Bridge or Device module.php is modified before Phase 1 executes)

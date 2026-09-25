# PLAN: Phase 1 — Shared Trait

**Goal:** A single `ThinQModuleTrait` exists and is the sole source of shared helper methods in both Bridge and Device
**Requirements:** TRAIT-01, TRAIT-02, TRAIT-03
**Executor:** gsd-executor

---

## Pre-conditions

- [ ] `LGThinQ/libs/` does not exist (will be created by Task 1)
- [ ] `LG ThinQ Bridge/module.php` contains private methods: `t()` (line 31), `isKernelReady()` (line 36), `findModuleGUIDByName()` (line 1688)
- [ ] `LG ThinQ Device/module.php` contains private methods: `isKernelReady()` (line 212), `t()` (line 1474)

---

## Tasks

### Task 1: Create shared libs directory and ThinQModuleTrait.php

**File:** `LGThinQ/libs/ThinQModuleTrait.php` (create new)

Create the directory `LGThinQ/libs/` and write the file at `LGThinQ/libs/ThinQModuleTrait.php` with the exact content below.

```php
<?php

declare(strict_types=1);

trait ThinQModuleTrait
{
    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function isKernelReady(): bool
    {
        return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
    }

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
}
```

**Verify:** `php -l "LGThinQ/libs/ThinQModuleTrait.php"` exits 0

---

### Task 2: Update LG ThinQ Bridge/module.php

**File:** `LG ThinQ Bridge/module.php` (edit existing)

Perform the following four changes in order. Use the exact `old_string` shown for the Edit tool — do not paraphrase.

#### Change 2a — Add require_once for trait

Insert `require_once __DIR__ . '/../libs/ThinQModuleTrait.php';` as a new line immediately before line 5 (the first `require_once` in the file).

**old_string** (lines 3–5):
```
declare(strict_types=1);

require_once __DIR__ . '/libs/ThinQHelpers.php';
```

**new_string**:
```
declare(strict_types=1);

require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/ThinQHelpers.php';
```

#### Change 2b — Add `use ThinQModuleTrait;` inside class declaration

Insert `use ThinQModuleTrait;` as the first statement inside the class body, on its own line after the opening brace, followed by a blank line before the existing `public const` declaration.

**old_string** (lines 16–19):
```
class LGThinQBridge extends IPSModule
{
    public const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
```

**new_string**:
```
class LGThinQBridge extends IPSModule
{
    use ThinQModuleTrait;

    public const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
```

#### Change 2c — Remove private methods `t()` and `isKernelReady()` from Bridge

Both methods appear on lines 31–39 with a blank line between them and the `public function Create()` below. Remove both method definitions and the blank line that separates them from each other, preserving exactly one blank line before `Create()`.

**old_string** (lines 31–41 inclusive — from the `t()` opening to the blank line before `Create()`):
```
    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function isKernelReady(): bool
    {
        return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
    }

    public function Create()
```

**new_string**:
```
    public function Create()
```

#### Change 2d — Remove `findModuleGUIDByName()` and its preceding comment from Bridge

The comment `// ---- helper methods for instance/properties ----` at line 1687 (after the move from 2c applies, the absolute line number shifts but the context string is unchanged) sits immediately before `findModuleGUIDByName()`. Remove both the comment line and the full method body. The blank line before the comment and the blank line after the closing `}` of the method are preserved so that `instancesOf()` remains separated from the preceding method by a blank line.

**old_string** (lines 1687–1699 in the original file; content is stable regardless of 2c offset):
```
    // ---- helper methods for instance/properties ----
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

    /**
     * @return array<int,int>
     */
    private function instancesOf(string $moduleName): array
```

**new_string**:
```
    /**
     * @return array<int,int>
     */
    private function instancesOf(string $moduleName): array
```

**Verify:** `php -l "LG ThinQ Bridge/module.php"` exits 0

---

### Task 3: Update LG ThinQ Device/module.php

**File:** `LG ThinQ Device/module.php` (edit existing)

Perform the following three changes in order.

#### Change 3a — Add require_once for trait

Insert `require_once __DIR__ . '/../libs/ThinQModuleTrait.php';` as a new line immediately before line 5 (the single `require_once` in the file).

**old_string** (lines 3–5):
```
declare(strict_types=1);

require_once __DIR__ . '/libs/CapabilityEngine.php';
```

**new_string**:
```
declare(strict_types=1);

require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/CapabilityEngine.php';
```

#### Change 3b — Add `use ThinQModuleTrait;` inside class declaration

Insert `use ThinQModuleTrait;` as the first statement inside the class body, on its own line after the opening brace, followed by a blank line before the existing `private const` declarations.

**old_string** (lines 7–9):
```
class LGThinQDevice extends IPSModule
{
    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
```

**new_string**:
```
class LGThinQDevice extends IPSModule
{
    use ThinQModuleTrait;

    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
```

#### Change 3c — Remove private method `isKernelReady()` from Device (line 212)

The method at lines 212–215 is followed by a blank line and then `public function MessageSink`. Remove the method body and the blank line below it so that the preceding method's closing brace is immediately followed by a blank line and then `MessageSink`.

**old_string** (lines 212–217):
```
    private function isKernelReady(): bool
    {
        return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
```

**new_string**:
```
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
```

#### Change 3d — Remove private method `t()` from Device (line 1474)

The method at lines 1474–1477 is preceded by two blank lines (lines 1472–1473) and is followed by a blank line and then `maskText()`. Remove the method body. Preserve one blank line before `maskText()` and reduce the double-blank to a single blank before the removed block, leaving exactly one blank line between the previous method's closing `}` (line 1471) and `maskText()`.

**old_string** (lines 1472–1479, with the double blank and following method):
```


    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function maskText(string $s): string
```

**new_string**:
```

    private function maskText(string $s): string
```

**Verify:** `php -l "LG ThinQ Device/module.php"` exits 0

---

### Task 4: Final syntax verification

Run `php -l` on all three files. All must exit 0 with "No syntax errors detected".

```bash
php -l "LGThinQ/libs/ThinQModuleTrait.php" && \
php -l "LG ThinQ Bridge/module.php" && \
php -l "LG ThinQ Device/module.php"
```

Expected output (one line per file):
```
No syntax errors detected in LGThinQ/libs/ThinQModuleTrait.php
No syntax errors detected in LG ThinQ Bridge/module.php
No syntax errors detected in LG ThinQ Device/module.php
```

If any file reports a syntax error, stop and report the error message verbatim before proceeding.

---

## Success Criteria Checklist

- [ ] **TRAIT-01:** `LGThinQ/libs/ThinQModuleTrait.php` exists and contains `t()`, `isKernelReady()`, `findModuleGUIDByName()` with the exact method bodies from Task 1
- [ ] **TRAIT-02:** `LG ThinQ Bridge/module.php` contains `use ThinQModuleTrait;` inside the class body; the private definitions of `t()`, `isKernelReady()`, `findModuleGUIDByName()`, and the `// ---- helper methods for instance/properties ----` comment are gone
- [ ] **TRAIT-03:** `LG ThinQ Device/module.php` contains `use ThinQModuleTrait;` inside the class body; the private definitions of `t()` and `isKernelReady()` are gone
- [ ] `php -l` exits 0 on all three files: `ThinQModuleTrait.php`, `LG ThinQ Bridge/module.php`, `LG ThinQ Device/module.php`
- [ ] `instancesOf()` in Bridge is unchanged — it still calls `$this->findModuleGUIDByName()` unmodified
- [ ] No `LGTQ_*`, `LGTQD_*`, or `LGTQC_*` public method signatures have been altered

---

## Rollback

If anything goes wrong after any task, revert by:

1. **After Task 1 only:** Delete `LGThinQ/libs/` and its contents. No source files were modified.
2. **After Task 2 (Bridge edits):** The changes to `LG ThinQ Bridge/module.php` are:
   - Remove the `require_once __DIR__ . '/../libs/ThinQModuleTrait.php';` line (line 5 in edited file)
   - Remove the `use ThinQModuleTrait;` line and its following blank line from inside the class
   - Re-add the two private methods `t()` and `isKernelReady()` between the property declarations and `Create()` at their original positions (lines 31–39)
   - Re-add the comment `// ---- helper methods for instance/properties ----` and `findModuleGUIDByName()` method body immediately before `instancesOf()` at their original position
3. **After Task 3 (Device edits):** The changes to `LG ThinQ Device/module.php` are:
   - Remove the `require_once __DIR__ . '/../libs/ThinQModuleTrait.php';` line (line 5 in edited file)
   - Remove the `use ThinQModuleTrait;` line and its following blank line from inside the class
   - Re-add `isKernelReady()` between `updateReferences()` close and `MessageSink()` (original lines 212–215)
   - Re-add `t()` between the `updateReferences()`-helper block and `maskText()` (original lines 1474–1477, preceded by double blank line)

Git revert is the fastest rollback if the repository is at a clean commit before execution starts.

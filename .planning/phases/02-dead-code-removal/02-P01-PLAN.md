---
phase: 02-dead-code-removal
plan: P01
type: execute
wave: 1
depends_on: []
files_modified:
  - "LG ThinQ Device/module.php"
  - "LG ThinQ Bridge/module.php"
  - "LG ThinQ Bridge/libs/ThinQApiErrorCodes.php"
autonomous: true
requirements:
  - DEAD-01
  - DEAD-02
  - DEAD-03

must_haves:
  truths:
    - "`updateFromStatus` does not appear anywhere in LG ThinQ Device/module.php"
    - "`flattenKeys` and `flattenKeysRecursive` do not appear anywhere in the codebase"
    - "`ThinQApiErrorCodes.php` does not exist on disk"
    - "`require_once __DIR__ . '/libs/ThinQApiErrorCodes.php'` does not appear in LG ThinQ Bridge/module.php"
    - "`php -l` exits 0 on LG ThinQ Device/module.php and LG ThinQ Bridge/module.php"
  artifacts:
    - path: "LG ThinQ Device/module.php"
      provides: "Device module without dead methods"
      must_not_contain:
        - "updateFromStatus"
        - "flattenKeys"
        - "flattenKeysRecursive"
    - path: "LG ThinQ Bridge/module.php"
      provides: "Bridge module without dead require_once"
      must_not_contain:
        - "ThinQApiErrorCodes"
  key_links:
    - description: "Line 262 call site removed — CapabilityEngine applyStatus call directly follows WriteAttributeString"
    - description: "No require_once gap in Bridge — line 15 removed, lines 10-14 remain intact"
---

<objective>
Delete all confirmed dead code units from the LGThinQ module library: one empty placeholder method and its call site, two unused helper methods, and one unreferenced class file.

Purpose: Reduce codebase noise and eliminate unreachable code paths before Phase 3 splits CapabilityEngine. No behaviour changes — only deletions.
Output: Smaller LG ThinQ Device/module.php (no updateFromStatus, no flattenKeys/flattenKeysRecursive), no ThinQApiErrorCodes.php on disk, no dangling require_once in Bridge.
</objective>

<execution_context>
@/Library/Application Support/Symcon/modules/.claude/get-shit-done/workflows/execute-plan.md
@/Library/Application Support/Symcon/modules/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/02-dead-code-removal/02-CONTEXT.md
</context>

<interfaces>
<!-- Exact locations verified before planning. Use these coordinates precisely. -->

LG ThinQ Device/module.php — call site (delete this single line):
  Line 262:  `            $this->updateFromStatus($status);`
  Surrounding context (keep):
    Line 259: `            $this->WriteAttributeString('LastStatus', json_encode($status));`
    Line 260: (blank line)
    Line 261: `            // Dedizierte Variablen aktualisieren`
    Line 263: `            // CapabilityEngine: Werte anwenden`
    Line 264: `            $engine = $this->prepareEngine();`

LG ThinQ Device/module.php — flattenKeys block to delete (lines 1302–1342):
  Line 1302: (blank line)
  Line 1303: `    /**`
  Line 1304: `     * Extract all top-level keys from nested array`
  Line 1305: `     * `
  Line 1306: `     * @param array<string, mixed> $arr`
  Line 1307: `     * @return array<string>`
  Line 1308: `     */`
  Line 1309: `    private function flattenKeys(array $arr): array`
  ... (body through closing brace)
  Line 1322: `    }` (closing brace of flattenKeys)
  Line 1323: (blank line)
  Line 1324: `    /**`
  Line 1325: `     * Recursively extract keys`
  ... (PHPDoc)
  Line 1331: `    private function flattenKeysRecursive(string $prefix, array $arr): array`
  ... (body through closing brace)
  Line 1342: `    }` (closing brace of flattenKeysRecursive)
  Line 1343: (blank line, keep — connects to next method fetchProfileFromAPI)

LG ThinQ Device/module.php — updateFromStatus definition to delete (lines 1413–1418):
  Line 1413: (blank line)
  Line 1414: `    private function updateFromStatus(array $status): void`
  Line 1415: `    {`
  Line 1416: `        // Placeholder for mapping selected status fields to dedicated variables`
  Line 1417: `        // Keep empty to avoid fatal errors; CapabilityEngine->applyStatus handles most updates`
  Line 1418: `    }`
  Next (keep): Line 1420: `    private function flatten(array $data, string $prefix = ''): array`

LG ThinQ Bridge/module.php — require_once to delete:
  Line 15: `require_once __DIR__ . '/libs/ThinQApiErrorCodes.php';`
  Keep all other require_once lines (lines 10–14).
</interfaces>

<tasks>

<task type="auto">
  <name>Task 1: Delete dead methods from LG ThinQ Device/module.php</name>
  <read_first>
    - LG ThinQ Device/module.php (full file — line numbers shift after each deletion; read before starting)
  </read_first>
  <files>LG ThinQ Device/module.php</files>
  <action>
Make three targeted deletions in LG ThinQ Device/module.php. Read the file first. Apply deletions using the Edit tool (one edit per logical block):

**Deletion 1 — call site (line 262):**
Remove the single line:
```
            $this->updateFromStatus($status);
```
Also remove the comment immediately above it if it has no other purpose:
```
            // Dedizierte Variablen aktualisieren
```
Both lines sit between `$this->WriteAttributeString('LastStatus', json_encode($status));` and `// CapabilityEngine: Werte anwenden`. The blank line between WriteAttributeString and the comment may be removed too, keeping the block tight. Result: WriteAttributeString line connects directly to the CapabilityEngine comment block.

**Deletion 2 — flattenKeys and flattenKeysRecursive (lines ~1302–1342):**
Delete the complete block including PHPDoc comments, method signatures, bodies, and closing braces for BOTH methods. The block starts at the blank line before `/** Extract all top-level keys...` and ends at the closing `}` of `flattenKeysRecursive`. Leave the blank line that follows (before `fetchProfileFromAPI`).

**Deletion 3 — updateFromStatus definition (lines ~1414–1418):**
Delete the complete method:
```php
    private function updateFromStatus(array $status): void
    {
        // Placeholder for mapping selected status fields to dedicated variables
        // Keep empty to avoid fatal errors; CapabilityEngine->applyStatus handles most updates
    }
```
Also delete the blank line immediately before it (line ~1413). The `flatten()` method that follows MUST remain untouched.

After all edits, run:
```bash
php -l "LG ThinQ Device/module.php"
```
Must print `No syntax errors detected` with exit code 0.
  </action>
  <verify>
    <automated>
cd "/Library/Application Support/Symcon/modules/LGThinQ" && grep -n "updateFromStatus\|flattenKeys\|flattenKeysRecursive" "LG ThinQ Device/module.php" | wc -l | grep -q '^0$' && echo "DEAD CODE GONE" && php -l "LG ThinQ Device/module.php"
    </automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "updateFromStatus" "LG ThinQ Device/module.php"` returns 0
    - `grep -c "flattenKeys" "LG ThinQ Device/module.php"` returns 0
    - `grep -c "flattenKeysRecursive" "LG ThinQ Device/module.php"` returns 0
    - `grep -c "private function flatten(" "LG ThinQ Device/module.php"` returns 1 (flatten() is NOT removed)
    - `php -l "LG ThinQ Device/module.php"` exits 0 with "No syntax errors detected"
  </acceptance_criteria>
  <done>All three dead code units removed from Device module.php, flatten() untouched, php -l clean.</done>
</task>

<task type="auto">
  <name>Task 2: Delete ThinQApiErrorCodes.php and its require_once from Bridge</name>
  <read_first>
    - LG ThinQ Bridge/module.php (lines 10–20 — confirm require_once line number before editing)
    - LG ThinQ Bridge/libs/ThinQApiErrorCodes.php (confirm file exists before deleting)
  </read_first>
  <files>
    LG ThinQ Bridge/module.php
    LG ThinQ Bridge/libs/ThinQApiErrorCodes.php
  </files>
  <action>
Two operations — in this order:

**Step 1 — Remove require_once from Bridge module.php:**
Read `LG ThinQ Bridge/module.php`. Find and delete the single line:
```
require_once __DIR__ . '/libs/ThinQApiErrorCodes.php';
```
This is currently line 15. All other require_once lines (10–14) remain exactly as-is. No other edits to Bridge module.php.

**Step 2 — Delete the file:**
```bash
rm "/Library/Application Support/Symcon/modules/LGThinQ/LG ThinQ Bridge/libs/ThinQApiErrorCodes.php"
```

**Step 3 — Syntax check:**
```bash
php -l "/Library/Application Support/Symcon/modules/LGThinQ/LG ThinQ Bridge/module.php"
```
Must print `No syntax errors detected` with exit code 0.
  </action>
  <verify>
    <automated>
cd "/Library/Application Support/Symcon/modules/LGThinQ" && test ! -f "LG ThinQ Bridge/libs/ThinQApiErrorCodes.php" && echo "FILE DELETED" && grep -c "ThinQApiErrorCodes" "LG ThinQ Bridge/module.php" | grep -q '^0$' && echo "REQUIRE GONE" && php -l "LG ThinQ Bridge/module.php"
    </automated>
  </verify>
  <acceptance_criteria>
    - `test -f "LG ThinQ Bridge/libs/ThinQApiErrorCodes.php"` exits non-zero (file does not exist)
    - `grep -c "ThinQApiErrorCodes" "LG ThinQ Bridge/module.php"` returns 0
    - All other require_once lines (ThinQHttpClient, ThinQEventManager, ThinQEventPipeline, ThinQMqttRouter, ThinQCertificateManager) remain present in Bridge module.php
    - `php -l "LG ThinQ Bridge/module.php"` exits 0 with "No syntax errors detected"
  </acceptance_criteria>
  <done>ThinQApiErrorCodes.php deleted from disk, require_once removed from Bridge module.php, php -l clean.</done>
</task>

</tasks>

<verification>
After both tasks complete, run the following from the module root to confirm the full phase acceptance criteria:

```bash
cd "/Library/Application Support/Symcon/modules/LGThinQ"

# DEAD-01: no updateFromStatus anywhere
grep -rn "updateFromStatus" . --include="*.php" | wc -l   # must be 0

# DEAD-02: no flattenKeys anywhere
grep -rn "flattenKeys" . --include="*.php" | wc -l         # must be 0

# DEAD-03: file gone, require_once gone
test ! -f "LG ThinQ Bridge/libs/ThinQApiErrorCodes.php" && echo "DEAD-03 file: OK"
grep -c "ThinQApiErrorCodes" "LG ThinQ Bridge/module.php"  # must be 0

# Syntax on all touched files
php -l "LG ThinQ Device/module.php"
php -l "LG ThinQ Bridge/module.php"

# Safety: flatten() (used method) must still exist
grep -c "private function flatten(" "LG ThinQ Device/module.php"  # must be 1
```
</verification>

<success_criteria>
1. `updateFromStatus` does not appear anywhere in `LG ThinQ Device/module.php` (call site AND definition both gone)
2. `flattenKeys` and `flattenKeysRecursive` do not appear anywhere in the codebase
3. `LG ThinQ Bridge/libs/ThinQApiErrorCodes.php` does not exist on disk
4. `ThinQApiErrorCodes` does not appear in `LG ThinQ Bridge/module.php`
5. `php -l` exits 0 on `LG ThinQ Device/module.php` and `LG ThinQ Bridge/module.php`
6. `private function flatten(` still exists in `LG ThinQ Device/module.php` (the used method is not accidentally removed)
</success_criteria>

<output>
After completion, create `.planning/phases/02-dead-code-removal/02-P01-SUMMARY.md` using the summary template.
</output>

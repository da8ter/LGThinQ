# Phase 2: Dead Code Removal - Context

**Gathered:** 2026-03-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Delete every confirmed dead code unit in the codebase: an empty placeholder method (`updateFromStatus`), two unused helper methods (`flattenKeys`, `flattenKeysRecursive`), and an unreferenced class (`ThinQApiErrorCodes`).

**What this phase does NOT do:** No new behaviour, no retry logic, no structural splits (Phase 3), no QA sweep (Phase 4).

</domain>

<decisions>
## Implementation Decisions

### DEAD-01: updateFromStatus()
- **D-01:** Delete the method definition at `LG ThinQ Device/module.php` line ~1414 (empty placeholder body, comment-only).
- **D-02:** Delete the single call site at line ~262 (`$this->updateFromStatus($status);`). The surrounding code (WriteAttributeString + CapabilityEngine applyStatus) remains unchanged.

### DEAD-02: flattenKeys() and flattenKeysRecursive()
- **D-03:** Delete both private method definitions from `LG ThinQ Device/module.php` (~lines 1309–1344). Neither method has any call sites anywhere in the codebase.

### DEAD-03: ThinQApiErrorCodes (key decision)
- **D-04:** **Delete** `ThinQApiErrorCodes`. Remove `LG ThinQ Bridge/libs/ThinQApiErrorCodes.php` entirely and remove the `require_once __DIR__ . '/libs/ThinQApiErrorCodes.php';` line from `LG ThinQ Bridge/module.php`.
- **Rationale:** The class has zero call sites. Wiring it into `ThinQHttpClient` would add new retry/auth-classification behaviour — that is out of scope for a dead-code removal phase. If retry logic is wanted, it belongs in a dedicated future phase (see V2-03 in REQUIREMENTS.md).

### php -l
- **D-05:** Run `php -l` on every file touched (`LG ThinQ Device/module.php`, `LG ThinQ Bridge/module.php`) after edits. Pass required per DEAD-04 / QA-01.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

- `.planning/REQUIREMENTS.md` — DEAD-01, DEAD-02, DEAD-03 are the acceptance criteria for this phase.
- `.planning/ROADMAP.md` — Phase 2 success criteria (four specific checkpoints including `php -l` pass).
- `CLAUDE.md` — Symcon module coding standards (strict_types, 500-line limit, parent calls).

</canonical_refs>

<code_context>
## Existing Code Insights

### updateFromStatus (DEAD-01)
- **Definition:** `LG ThinQ Device/module.php` ~line 1414 — empty body, comment says "Placeholder for mapping selected status fields"
- **Only call site:** ~line 262, inside `ReceiveData`/`UpdateStatus` flow, immediately before `$engine->applyStatus($status)` — safe to remove the call
- **No callers elsewhere** in the codebase

### flattenKeys / flattenKeysRecursive (DEAD-02)
- **Definitions:** `LG ThinQ Device/module.php` lines ~1309–1344 — both private methods with PHPDoc
- **Call sites:** Zero — no calls found anywhere including Bridge, Configurator, or libs
- Note: a separate `flatten()` method also exists in Device (~line 1421) and IS used — do not touch it

### ThinQApiErrorCodes (DEAD-03)
- **File:** `LG ThinQ Bridge/libs/ThinQApiErrorCodes.php` — 110-line final class with constants, DESCRIPTIONS map, `describe()`, `isRetryable()`, `isAuthError()`
- **require_once:** `LG ThinQ Bridge/module.php` line 15
- **Call sites:** Zero — `ThinQApiErrorCodes::` prefix not found anywhere in the codebase outside the class definition itself
- **ThinQHttpClient** extracts API error codes from responses (lines 116–119) but does not use this class — it throws raw exceptions instead

</code_context>

<specifics>
## Specific Notes

- The `flatten()` method in Device (not `flattenKeys`) is actively used — do NOT remove it.
- V2-03 in REQUIREMENTS.md notes "add retry logic via ThinQApiErrorCodes if not done in DEAD-03" — since DEAD-03 deletes the class, V2-03 would need to re-create or replace it. That is a v2 concern, not Phase 2.

</specifics>

<deferred>
## Deferred Ideas

- **Retry logic in ThinQHttpClient** — mentioned during DEAD-03 discussion. Belongs in a dedicated future phase (V2-03). Not Phase 2 scope.

</deferred>

---

*Phase: 02-dead-code-removal*
*Context gathered: 2026-03-27*

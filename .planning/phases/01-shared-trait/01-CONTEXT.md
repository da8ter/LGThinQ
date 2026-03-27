# Phase 1: Shared Trait - Context

**Gathered:** 2026-03-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Extract the duplicate helper methods `t()`, `isKernelReady()`, and `findModuleGUIDByName()` from `LGThinQBridge` and `LGThinQDevice` into a single `ThinQModuleTrait.php`. Both modules then use the trait and remove their own copies.

**What this phase does NOT do:** Dead code removal (Phase 2), CapabilityEngine splitting (Phase 3), any behaviour changes.

</domain>

<decisions>
## Implementation Decisions

### Trait File Location
- **D-01:** `ThinQModuleTrait.php` lives at the library root under `libs/` → `LGThinQ/libs/ThinQModuleTrait.php`
- **D-02:** Both modules load the trait via `require_once __DIR__ . '/../libs/ThinQModuleTrait.php';` at the top of their `module.php`

### Methods to Include
- **D-03:** Trait contains at minimum: `t()`, `isKernelReady()`, `findModuleGUIDByName()` — exactly as specified in TRAIT-01
- **D-04:** Only confirmed duplicates and REQUIREMENTS-specified methods go in the trait. `maskText()`, `anonymizeArray()`, `anonymizeText()`, `instancesOf()` are Device- or Bridge-only — they stay where they are.

### Claude's Discretion
- Method visibility in the trait (`private` vs `protected`) — `private` is the minimal safe change and matches current usage; Claude may use `protected` if there is a reason, but `private` is preferred for zero-visibility-regression.
- Whether `instancesOf()` (Bridge-only, depends on `findModuleGUIDByName()`) should be updated to call `$this->findModuleGUIDByName()` via the trait rather than its own private copy — Claude decides based on what produces the cleanest result.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Symcon Module SDK
- `https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/` — Official IP-Symcon PHP SDK documentation. Relevant for confirming PHP trait compatibility with Module Strict pattern (`declare(strict_types=1)`, `IPSModule` subclass).

### LG ThinQ API References (for broader context)
- `.API References/thinq_connect_api_reference.md` — Human-readable summary of LG ThinQ Connect REST API (base URLs, endpoints). Less relevant for Phase 1 but referenced for completeness.
- `.API References/thinq_connect_openapi.json` — Full OpenAPI 3.1.0 spec for LG ThinQ Connect API.
- `.API References/thinq_device_profiles_openapi.json` — Full OpenAPI spec for LG device profiles.
- `.API References/thinq_device_profiles_reference.md` — Human-readable device profiles reference.

### Project Planning
- `.planning/REQUIREMENTS.md` — Requirements TRAIT-01, TRAIT-02, TRAIT-03 are the acceptance criteria for this phase.
- `.planning/ROADMAP.md` — Phase 1 success criteria (four specific checkpoints including `php -l` pass).
- `CLAUDE.md` — Symcon module coding standards (strict_types, Presentation-Arrays, parent calls, 500-line limit).

</canonical_refs>

<code_context>
## Existing Code Insights

### Duplicate Methods (confirmed)
- `t(string $text): string` — Bridge line 31, Device line 1474 — identical implementations
- `isKernelReady(): bool` — Bridge line 36, Device line 212 — identical implementations
- `findModuleGUIDByName(string $name): ?string` — Bridge line 1688 only (not a duplicate, but required by TRAIT-01)

### Require-Once Structure
- Bridge `module.php` already has 10 `require_once` lines at the top (lines 5–14, loading from `__DIR__ . '/libs/'`)
- Device `module.php` has 1 `require_once` (CapabilityEngine only, line 5)
- New trait require: `require_once __DIR__ . '/../libs/ThinQModuleTrait.php';` fits this pattern

### No Shared Directory Exists Today
- `LGThinQ/libs/` does not yet exist — must be created
- `LGThinQ/` root currently contains: `library.json`, `README.md`, `.API References/`, `LG ThinQ Bridge/`, `LG ThinQ Device/`, `LG ThinQ Configurator/`

### Trait Usage Pattern
- Bridge: `use ThinQModuleTrait;` inside `class LGThinQBridge extends IPSModule`
- Device: `use ThinQModuleTrait;` inside `class LGThinQDevice extends IPSModule`
- After trait inclusion, remove the private method definitions from each class

</code_context>

<specifics>
## Specific Ideas

- The user explicitly confirmed that `LGThinQ/libs/` is the canonical home for shared code — this creates a pattern for future shared utilities (though no other shared code is planned in this refactoring scope).

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 01-shared-trait*
*Context gathered: 2026-03-27*

# Phase 1: Shared Trait - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-03-27
**Phase:** 01-shared-trait
**Areas discussed:** Trait file location, Canonical references

---

## Trait File Location

| Option | Description | Selected |
|--------|-------------|----------|
| Root-level (no folder) | `LGThinQ/ThinQModuleTrait.php` — minimal, no new folder | |
| Root-level `libs/` folder | `LGThinQ/libs/ThinQModuleTrait.php` — clean, extensible | ✓ |
| Bridge/libs/ (Device cross-requires) | Couples Device to Bridge directory — not ideal | |

**User's choice:** Root-level `libs/` folder
**Notes:** Both modules require_once from `__DIR__ . '/../libs/ThinQModuleTrait.php'`. Creates a new `LGThinQ/libs/` directory as the canonical home for future shared library code.

---

## Canonical References

| Reference | Added By |
|-----------|----------|
| Symcon PHP SDK URL | User |
| `.API References/` folder | User |

**User's notes:** "API und Device Referenzen findest du im Ordner .API References. Ein Symcon Entwicklerhandbuch gibt es hier: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/"

---

## Claude's Discretion

- Method visibility in the trait (`private` vs `protected`)
- Whether `instancesOf()` (Bridge-only) should call `$this->findModuleGUIDByName()` via trait

## Deferred Ideas

None.

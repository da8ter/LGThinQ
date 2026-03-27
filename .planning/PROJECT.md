# LG ThinQ Sync — Optimierung

## What This Is

IP-Symcon Modulbibliothek (v0.1.7) zur Integration von LG ThinQ Geräten. Sie verbindet IP-Symcon mit der LG ThinQ Connect Cloud API über HTTPS und empfängt Echtzeit-Geräteereignisse via MQTT. Ein capability-getriebenes System übersetzt LG-Gerätprofile in IP-Symcon Variablen mit passenden Darstellungen.

Ziel dieser Optimierung: Code-Qualität und Wartbarkeit verbessern — God-Classes aufteilen, gemeinsamen Code in einen Trait extrahieren und toten Code entfernen. Ohne Änderung des öffentlichen Verhaltens.

## Core Value

Bestehende Symcon-Instanzen laufen nach dem Refactoring genauso wie vorher — null Regressions, bessere Wartbarkeit.

## Requirements

### Validated

- ✓ LG ThinQ Connect API Anbindung (HTTPS + MQTT) — existing
- ✓ Bridge/Splitter-Modul für Gerätemanagement und Event-Routing — existing
- ✓ Device-Modul mit capability-getriebenem Variablen-Mapping — existing
- ✓ Configurator-Modul für Geräteerkennung in Symcon — existing
- ✓ Presentation-Arrays für alle selbst erstellten Variablen (außer LASTUPDATE) — existing
- ✓ Symcon Module Strict: strict_types, parent::Create/ApplyChanges/Destroy — existing
- ✓ Kernel-Ready Guard in ApplyChanges() — existing

### Active

- [ ] CapabilityEngine (~2.585 Zeilen) aufteilen: Gerätetyp-Handler-Dateien + klare Verantwortlichkeitstrennung innerhalb der Handler (unter 500 Zeilen je Datei)
- [x] Gemeinsame Hilfsmethoden (t(), isKernelReady(), findModuleGUIDByName() etc.) in `ThinQModuleTrait` extrahieren — in Bridge und Device einbinden — Validated in Phase 01: shared-trait
- [ ] Toten Code entfernen: leere `updateFromStatus()`, unbenutztes `flattenKeys()` / `flattenKeysRecursive()`, ungenutzten `ThinQApiErrorCodes`-Aufruf
- [ ] Syntax-Check (php -l) auf alle geänderten Dateien + manuelle Verifikation im Symcon-System

### Out of Scope

- API Key aus dem Code entfernen — bewusst zurückgestellt, separater Fix nötig
- ~UnixTimestamp Presentation-Array-Fix für LASTUPDATE — separater Fix nötig
- Neue Features oder Geräteklassen — nicht Teil dieser Optimierung
- Automatisierte Unit Tests — keine Testinfrastruktur vorhanden; würde eigene Phase erfordern

## Context

**Codebase-Analyse:** `.planning/codebase/` enthält vollständige Analyse (7 Dokumente).

- **God Classes:** `CapabilityEngine.php` (2.585 Zeilen), `LG ThinQ Bridge/module.php` (~1.751 Zeilen), `LG ThinQ Device/module.php` (~1.889 Zeilen) — alle weit über der 500-Zeilen-Grenze aus CLAUDE.md
- **Duplikate:** `t()`, `isKernelReady()`, `findModuleGUIDByName()` in Bridge und Device identisch vorhanden
- **Toter Code:** `updateFromStatus()` (leerer Placeholder), `flattenKeys()` / `flattenKeysRecursive()` (nie aufgerufen), `ThinQApiErrorCodes` (definiert, nie genutzt)
- **Kein Test-Framework** — Verifikation erfolgt manuell in Symcon + php -l Syntax-Check
- **IP-Symcon 7.1 Kompatibilität** muss erhalten bleiben

## Constraints

- **Kompatibilität:** Öffentliche Methoden (LGTQ_, LGTQD_, LGTQC_), Module-GUIDs, Properties und Variablen-Idents bleiben identisch — kleinere interne Umstrukturierungen sind erlaubt
- **Symcon-Standards:** CLAUDE.md Regeln sind verbindlich (strict_types, Presentation-Arrays, parent::Calls, Kernel-Guard, Zeilenlimit 500)
- **PHP:** Kein Composer, kein Autoloader — alle Klassen per require_once eingebunden
- **Dateigröße:** Jede Datei unter 500 Zeilen (CLAUDE.md Vorgabe)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| CapabilityEngine: Gerätetyp-Handler + Verantwortlichkeitstrennung kombinieren | Bessere Navigation UND saubere Architektur | — Pending |
| Shared Helpers als PHP Trait | Keine Vererbungshierarchie nötig; IP-Symcon erwartet direkte IPSModule-Kindklassen | ✓ Phase 01 |
| API Key und ~UnixTimestamp-Fix explizit ausgeschlossen | Scope-Kontrolle; sind eigenständige Themen | — Pending |
| Manuelle Tests + php -l | Kein Test-Framework vorhanden; pragmatischer Ansatz | — Pending |

---
*Last updated: 2026-03-27 — Phase 01 (shared-trait) complete*

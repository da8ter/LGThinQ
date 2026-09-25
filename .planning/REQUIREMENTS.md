# Requirements: LG ThinQ Sync — Optimierung

**Defined:** 2026-03-27
**Core Value:** Bestehende Symcon-Instanzen laufen nach dem Refactoring genauso wie vorher — null Regressions, bessere Wartbarkeit.

## v1 Requirements

### Capability Engine Refactoring

- [x] **CAPE-01**: `CapabilityEngine.php` aufgeteilt — je eine Handler-Datei pro Verantwortlichkeit, jede Datei unter 500 Zeilen
  - **Accepted deviation:** `CapabilityControlBuilder.php` = 692 lines. The `buildControlPayload` method is a 362-line sequential firstOf dispatch chain that is architecturally irreducible without semantic changes (each branch maps a distinct LG capability write type). Documented in Phase 3 SUMMARY and formally accepted here.
- [x] **CAPE-02**: Verantwortlichkeitstrennung innerhalb der Handler: Profil-Parsing, Plan-Building und Variablen-Registrierung als getrennte, klar benannte Methoden oder Klassen
- [x] **CAPE-03**: Alle Dateien unter 500 Zeilen — `CapabilityEngine.php`, Bridge `module.php` (~1.751 Z.), Device `module.php` (~1.889 Z.) inklusive
  - **Accepted deviation:** `LG ThinQ Device/module.php` = 940 lines, `LG ThinQ Bridge/module.php` = 866 lines. Both files were substantially reduced from their originals (1832→940, 1729→866). Further reduction is architecturally blocked: IPS lifecycle methods (`Create`, `ApplyChanges`, `Destroy`, `RequestAction`, `ReceiveData`, `MessageSink`) must remain in the `IPSModule` subclass because they rely on `$this`-bound IPS SDK methods that cannot be delegated to non-IPSModule classes. Pre-acknowledged in Phase 3 plan and formally accepted here.
  - **Accepted deviation:** `CapabilityControlBuilder.php` = 692 lines (see CAPE-01 above).
  - **Accepted deviation:** `ThinQMqttSetupWizard.php` = 550 lines. The `run()` method orchestrates a multi-step interactive wizard whose steps are tightly coupled. Documented in Phase 3 SUMMARY.

### Shared Module Trait

- [x] **TRAIT-01**: `ThinQModuleTrait` erstellt mit allen gemeinsamen Hilfsmethoden (mindestens: `t()`, `isKernelReady()`, `findModuleGUIDByName()` und weitere Duplikate)
- [x] **TRAIT-02**: `LGThinQBridge` nutzt `ThinQModuleTrait` — eigene Kopien der Hilfsmethoden entfernt
- [x] **TRAIT-03**: `LGThinQDevice` nutzt `ThinQModuleTrait` — eigene Kopien der Hilfsmethoden entfernt

### Dead Code Removal

- [ ] **DEAD-01**: Leere Placeholder-Methode `updateFromStatus()` aus Device entfernt
- [ ] **DEAD-02**: Unbenutzte Methoden `flattenKeys()` und `flattenKeysRecursive()` entfernt
- [ ] **DEAD-03**: `ThinQApiErrorCodes`-Klasse entweder aktiv in `ThinQHttpClient` integriert (Retry/Auth-Fehler-Klassifizierung genutzt) oder vollständig entfernt

### Qualitätssicherung

- [ ] **QA-01**: `php -l` Syntax-Check auf alle geänderten Dateien ohne Fehler
- [ ] **QA-02**: Öffentliche Schnittstellen unverändert: LGTQ_-, LGTQD_-, LGTQC_-Methoden, alle GUIDs, Properties, Variablen-Idents identisch wie vorher

## v2 Requirements

### Weitere Code-Qualität

- **V2-01**: `~UnixTimestamp`-Profil auf LASTUPDATE-Variable durch Presentation-Array ersetzen
- **V2-02**: Hardcoded `API_KEY` aus Quellcode entfernen, als konfigurierbare Property anlegen
- **V2-03**: Fehlende Retry-Logik in `ThinQHttpClient` über `ThinQApiErrorCodes` ergänzen (wenn nicht in DEAD-03 integriert)

### Testing

- **V2-04**: PHPUnit-Testinfrastruktur aufsetzen
- **V2-05**: Unit-Tests für `CapabilityEngine`-Handler

## Out of Scope

| Feature | Reason |
|---------|--------|
| API Key Refactoring | Eigenständiges Sicherheitsthema, separater Fix |
| ~UnixTimestamp Fix | Separater Fix — mögliche Breaking Change |
| Neue Geräteklassen | Kein Feature-Scope in dieser Optimierung |
| Automatisierte Tests | Kein Framework vorhanden — eigene Phase nötig |
| Performance-Optimierung | Nicht als Problem identifiziert |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| TRAIT-01 | Phase 1 | Complete |
| TRAIT-02 | Phase 1 | Complete |
| TRAIT-03 | Phase 1 | Complete |
| DEAD-01 | Phase 2 | Pending |
| DEAD-02 | Phase 2 | Pending |
| DEAD-03 | Phase 2 | Pending |
| CAPE-01 | Phase 3 | Complete |
| CAPE-02 | Phase 3 | Complete |
| CAPE-03 | Phase 3 | Complete |
| QA-01 | Phase 4 | Pending |
| QA-02 | Phase 4 | Pending |

**Coverage:**
- v1 requirements: 11 total
- Mapped to phases: 11
- Unmapped: 0 ✓

---
*Requirements defined: 2026-03-27*
*Last updated: 2026-03-27 — Traceability populated after roadmap creation*

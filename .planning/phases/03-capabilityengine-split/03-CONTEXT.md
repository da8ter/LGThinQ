# Phase 3: CapabilityEngine Split - Context

**Gathered:** 2026-03-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Split `CapabilityEngine.php` (2585 Zeilen), `LG ThinQ Device/module.php` (1832 Zeilen) und `LG ThinQ Bridge/module.php` (1729 Zeilen) — jede Datei muss nach dem Refactoring unter 500 Zeilen liegen. Keine Verhaltensänderungen, kein neues Feature.

**Was diese Phase NICHT tut:** QA-Sweep (Phase 4), neue Geräteklassen, API-Änderungen.

</domain>

<decisions>
## Implementierungsentscheidungen

### CAPE-01: CapabilityEngine-Split-Strategie
- **D-01:** Verantwortlichkeitsbasierter Split (nicht per-Gerätetyp) — die Engine hat kein gerätespezifisches PHP-Code, alles ist capability-getrieben via JSON-Profile. CAPE-01 wird als "Datei pro Verantwortlichkeit" interpretiert.
- **D-02:** `CapabilityEngine.php` bleibt als dünner Coordinator (~150 Zeilen). Die öffentliche API ist **identisch**: `buildPlan()`, `applyStatus()`, `ensureVariables()`, `buildControlPayload()`, `getDescriptors()`, `getPresentationMap()`, `reassertActionsOnSetup()`, `listIdentsToEnableOnSetup()`, `listIdentsToEnable()`, `setMaintainVariableCallback()`, `setTranslateCallback()`, `loadCapabilities()` — alle Signaturen unverändert.

### Neue Dateien für CapabilityEngine (in `LG ThinQ Device/libs/`)
- **D-03:** `CapabilityProfileExtractor.php` — alle extract\*/humanize\*/getBestLabel-Methoden (~370 Z.): `extractErrorOptions`, `extractPushOptions`, `extractEnumValuesFromNode`, `extractLabelsMap`, `getBestLabel`, `humanizeEnum`, `extractEnumValuesFromFlatPrefix`, `extractLabelsMapFromFlatPrefix`, `extractErrorFromStatus`, `extractPushFromStatus`
- **D-04:** `CapabilityCatalogLoader.php` — Katalog-Logik (~240 Z.): `resolveCapabilityFiles`, `loadCatalog`, `catalogRuleMatches`, `catalogRuleMatchesDeviceOnly`, `matchesCondition`
- **D-05:** `CapabilityPlanBuilder.php` — Plan-Erstellung (~480 Z.): `buildPlan`, `convertAutoPlanToCapability`, `inferWriteConfig`, `applyCompanionPatterns`, `addCompanionFromStatus`, `addCompanionConst`, `addEnumMapCompanionConst`
- **D-06:** `CapabilityVarManager.php` — Variablenverwaltung (~420 Z.): `ensureVariables`, `reassertActionsOnSetup`, `listIdentsToEnableOnSetup`, `listIdentsToEnable`, `enableAction`, `readValue`, `getFromFlat`, `setValueByType`, `convertValueForType`, `replaceTemplatePlaceholders`, `walkReplace`, `findRangeFromProfile`, `findArrayIndex`, `collectArrayIndices`
- **D-07:** `CapabilityControlBuilder.php` — Steuerungslogik (~450 Z.): `buildControlPayload`, `applyCoSendFromStatus`, `applyCoSendConst`, `getTimerPairValue`, `getVariableValue`, `getVariableValueMixed`, `ipsTypeToCapType`
- **D-08:** Im Coordinator verbleiben: Properties, `__construct`, `setMaintainVariableCallback`, `setTranslateCallback`, `translate`, `debugEnabled`, `dbg`, `capHasWriteDefinition`, `loadCapabilities` (delegiert an CatalogLoader), `applyStatus`, `resetDeactivatedTimers`, `getDescriptors`, `getPresentationMap`, `getParser`, `getVarId`, `flatten`, `setByPath`, `shouldCreate`, `shouldEnableAction`, `profileHasWriteAny`, `modeHasW`, `flatProfileHasAny`, `flatProfileIsWriteable`

### CAPE-03: Device/module.php-Split (neue Dateien in `LG ThinQ Device/libs/`)
- **D-09:** `ThinQPresentationBuilder.php` — `applyPresentation()` + `translatePresentationPayload()` + `applyProfileFallback()` (~400 Z.). Device/module.php ruft `$this->presentationBuilder->applyPresentation(...)` via lazy-init Property.
- **D-10:** `ThinQEnergyManager.php` — alle Energy-Methoden (~180 Z.): `fetchEnergyProfile`, `getEnergyProperties`, `setupEnergyVariables`, `scheduleEnergyTimer`, `UpdateEnergy`, `fetchEnergyUsage`. Wird als separate Klasse mit `$instanceId`-Konstruktor implementiert.
- **D-11:** `ThinQSupportBundle.php` — `UIExportSupportBundle()`, `buildSupportBundleZip()` (~160 Z.). Device/module.php delegiert an diese Klasse.

### CAPE-03: Bridge/module.php-Split (neue Dateien in `LG ThinQ Bridge/libs/`)
- **D-12:** `ThinQMqttSetupWizard.php` — `UISetupMqttConnection()` + alle privaten Hilfsmethoden die ausschließlich von UISetupMqttConnection verwendet werden: `generateMqttClientCertMaterial`, `extractBrokerFromSubscriptions`, `fetchRouteBroker`, `ensureCertificatePEM`, `ensurePrivateKeyPEM`, `extractCAPEMFromSubscriptions`, `downloadAmazonRootCA1` (~500 Z. — prüfen ob unter 500, ggf. weitere Aufspaltung nötig). Bridge/module.php delegiert: `(new ThinQMqttSetupWizard(...))->run()`.

### Einbindung neuer Dateien
- **D-13:** Alle neuen Dateien werden per `require_once __DIR__ . '/libs/FileName.php'` eingebunden — kein Composer, kein Autoloader. CapabilityEngine.php bindet seine eigenen Sub-Klassen ein.
- **D-14:** Alle neuen Klassen folgen dem bestehenden Muster: `declare(strict_types=1)`, keine IPSModule-Vererbung, Konstruktor mit benötigten Dependencies.

### Claudes Discretion
- Exakte Methodengrenzen innerhalb der neuen Dateien — der Planner entscheidet anhand aktueller Zeilenzählung welche Methoden genau wohin, solange alle Dateien unter 500 Zeilen bleiben
- Ob `shouldCreate`, `shouldEnableAction` etc. in VarManager oder Coordinator bleiben — Planner entscheidet nach Zeilenzahl
- Ob `ThinQMqttSetupWizard` ggf. weiter aufgeteilt wird wenn >500 Zeilen

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MÜSSEN diese Dateien vor dem Planen lesen.**

### Zu splitende Quelldateien
- `LG ThinQ Device/libs/CapabilityEngine.php` — 2585 Zeilen, vollständige Klasse
- `LG ThinQ Device/module.php` — 1832 Zeilen
- `LG ThinQ Bridge/module.php` — 1729 Zeilen

### Bestehende Libs (Pattern-Referenz)
- `LG ThinQ Device/libs/ThinQProfileParser.php` — Beispiel wie eine extrahierte Device-Lib-Klasse aussieht
- `LG ThinQ Bridge/libs/ThinQHttpClient.php` — Beispiel für Bridge-Lib-Klasse
- `libs/ThinQModuleTrait.php` — Shared trait aus Phase 1

### Projektregeln
- `.planning/REQUIREMENTS.md` — CAPE-01, CAPE-02, CAPE-03 sind die Abnahmekriterien
- `.planning/ROADMAP.md` — Phase 3 Success Criteria (5 Punkte)
- `CLAUDE.md` — Symcon-Codierstandards (strict_types, 500-Zeilen-Limit, parent-Calls, Presentation-Arrays)

</canonical_refs>

<code_context>
## Codebase-Erkenntnisse

### CapabilityEngine — kein per-Gerätetyp-PHP-Code
- Die Engine ist vollständig capability-getrieben. Gerätetyp-spezifik lebt in `catalog.json` (JSON-Regeln), nicht in PHP-Klassen.
- `resolveCapabilityFiles()` nimmt den deviceType-String und sucht passende JSON-Regeln — keine switch/case-Blöcke pro Gerät.
- `applyCompanionPatterns()` hat 3 generische Muster (AC two-set temp, Oven ovenOperationMode, Cooktop power/timer) — ressource-basiert, nicht gerätetyp-basiert.

### Öffentliche API von CapabilityEngine (MUSS unverändert bleiben)
Alle Aufrufer in Device/module.php: `buildPlan()`, `applyStatus()`, `ensureVariables()`, `buildControlPayload()`, `getDescriptors()`, `getPresentationMap()`, `reassertActionsOnSetup()`, `listIdentsToEnableOnSetup()`, `listIdentsToEnable()`, `setMaintainVariableCallback()`, `setTranslateCallback()`, `loadCapabilities()`

### Device/module.php — größte Blöcke
- `applyPresentation()` beginnt bei Zeile ~789, endet ~1113 (~325 Z. Methode allein)
- `translatePresentationPayload()` + `applyProfileFallback()`: weitere ~80 Z. im selben Verantwortlichkeitsbereich
- Energy-Block: Zeilen ~1657–1832 (~176 Z.)
- Support-Bundle: Zeilen ~1484–1643 (~159 Z.)
- Nach Extraktion: module.php sollte auf ~490 Z. schrumpfen

### Bridge/module.php — größte Blöcke
- `UISetupMqttConnection()` beginnt Zeile ~1168, läuft bis ~1493 (~325 Z.)
- `buildMQTTClientCertsZip()`: Zeile ~846 bis ~1168 (~322 Z.) — bereits teils in ThinQCertificateManager, Rest in module.php
- Cert-Hilfsmethoden (generateMqttClientCertMaterial, extractBroker*, fetchRouteBroker etc.): Zeilen ~1493–1730 (~237 Z.)

### Einbindungsstruktur
- Device/module.php hat aktuell 1 require_once (CapabilityEngine.php, Zeile 6)
- Bridge/module.php hat aktuell 9 require_once (Zeilen 5–14) nach Phase-2-Bereinigung
- CapabilityEngine.php bindet aktuell 3 require_once ein (ThinQGenericProperties, ThinQEnumTranslator, ThinQProfileParser)

</code_context>

<specifics>
## Spezifische Hinweise

- Der Planner soll die Zeilenzahl jeder neuen Datei VOR dem Schreiben abschätzen — falls eine Datei >500 Z. würde, muss sie weiter aufgeteilt werden.
- `getCapabilityEngine()` in Device/module.php (Zeile 1233) bleibt unverändert — sie erstellt weiterhin `new CapabilityEngine(...)`, nur die Implementierung der CapabilityEngine ändert sich intern.
- Das `static $catalog` in CapabilityEngine muss beim Split in CatalogLoader wandern und dort als `static` erhalten bleiben.

</specifics>

<deferred>
## Zurückgestellte Ideen

- **Autoloader / Composer** — würde die require_once-Ketten eliminieren. Ist out of scope.
- **Interface-Definition für CapabilityEngine** — sinnvoll für Testbarkeit, aber kein Feature dieser Phase.
- **Per-Gerätetyp-PHP-Handler** — wäre möglich wenn gerätespezifische Logik wächst, aktuell nicht gerechtfertigt.

</deferred>

---

*Phase: 03-capabilityengine-split*
*Context gathered: 2026-03-27*

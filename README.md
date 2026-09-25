# LG ThinQ Sync

Folgende Module beinhaltet das LG ThinQ Repository:

- __LG ThinQ Bridge__ ([Dokumentation](LG%20ThinQ%20Bridge))  
- __LG ThinQ Configurator__ ([Dokumentation](LG%20ThinQ%20Configurator))  
- __LG ThinQ Device__ ([Dokumentation](LG%20ThinQ%20Device))  

## Zweck dieses Moduls

Dieses Repository integriert LG ThinQ Geräte in IP-Symcon. Es stellt eine sichere Cloud-Anbindung über einen Personal Access Token (PAT) her, synchronisiert Gerätezustände, abonniert Ereignisse (Push/Event/MQTT) und ermöglicht die Steuerung einzelner Geräte über sauber modellierte Fähigkeiten (Capabilities). Ziel ist eine robuste, automatisch aktualisierte und benutzerfreundliche Abbildung der LG-Geräte in IP-Symcon.

## Voraussetzungen

- IP-Symcon **7.1** oder höher
- PHP **8.0** oder höher (mit OpenSSL-Erweiterung für MQTT-Zertifikate)
- LG ThinQ Personal Access Token (PAT) – erstellen unter https://connect-pat.lgthinq.com

## Modul-Übersicht

### LG ThinQ Bridge (`LG ThinQ Bridge/`)
- Stellt die Verbindung zur LG ThinQ Cloud her (PAT-basiert).
- Verwaltet HTTP-API und – falls konfiguriert – MQTT/Ereignis-Subscriptions inkl. Zertifikats-Handling.
- Liefert Gerätestatus, Profile und führt Steuerbefehle aus.
- Dient als Parent (Splitter) für alle Gerätemodule.
- One-Click MQTT-Setup: Broker-Discovery, Zertifikatsgenerierung und Konfiguration.

### LG ThinQ Configurator (`LG ThinQ Configurator/`)
- Durchsucht die LG ThinQ Cloud nach zugeordneten Geräten.
- Erzeugt auf Wunsch automatisch **LG ThinQ Device**-Instanzen je Gerät.
- Vereinfacht die Erst-Inbetriebnahme und das Anlegen mehrerer Geräte.

### LG ThinQ Device (`LG ThinQ Device/`)
- Repräsentiert ein einzelnes LG ThinQ Gerät in IP-Symcon.
- **Auto-Discovery**: Erkennt automatisch verfügbare Eigenschaften aus dem Geräteprofil.
- Erstellt Variablen und Aktionen anhand von Capability-Definitionen und Auto-Discovery.
- Wendet moderne Präsentationen (Switch/Slider/Buttons/Enumeration) auf Variablen an.
- Aktualisiert Werte automatisch über Events/Statusabfragen und ermöglicht direkte Steuerung.
- Diagnostik-Export: Anonymisiertes Support-Paket (ZIP) per Klick.

## Schnellstart

1. **Bridge anlegen**: Instanz „LG ThinQ Bridge" erstellen, PAT und Ländercode eintragen, Verbindung testen.
2. **MQTT einrichten** (optional): Button „MQTT Verbindung einrichten" klicken – Zertifikate, Broker und Client werden automatisch konfiguriert.
3. **Geräte finden**: „LG ThinQ Configurator" öffnen und gewünschte Geräte auswählen/anlegen.
4. **Geräte steuern**: In den erzeugten „LG ThinQ Device"-Instanzen erscheinen Variablen und Aktionen entsprechend der Gerätefähigkeiten.

## Unterstützte Geräte

Prinzipiell werden alle LG ThinQ-fähigen Geräte unterstützt. Auto-Discovery erkennt automatisch neue Properties. Geräte mit manuellen Capability-Definitionen:

| Gerätetyp | Datei |
|---|---|
| Waschmaschine | `washer.json` |
| Klimaanlage | `air_conditioner.json` |
| Kühlschrank | `refrigerator.json` |

Weitere Geräte funktionieren über Auto-Discovery sofort ohne Konfiguration.

## PHP-Befehle

### Bridge
| Befehl | Beschreibung |
|---|---|
| `LGTQ_TestConnection($id)` | Verbindung zur LG Cloud testen |
| `LGTQ_SyncDevices($id)` | Geräteliste synchronisieren |
| `LGTQ_SubscribeAll($id)` | Alle Geräte abonnieren |
| `LGTQ_UnsubscribeAll($id)` | Alle Abonnements aufheben |
| `LGTQ_RenewAll($id)` | Alle Event-Subscriptions erneuern |

### Device
| Befehl | Beschreibung |
|---|---|
| `LGTQD_UpdateStatus($id)` | Status des Geräts aktualisieren |
| `LGTQD_RequestAction($id, $ident, $value)` | Aktion auf Variable ausführen |
| `LGTQD_CleanupVariables($id, $delete)` | Verwaiste Variablen aufräumen |

## Architektur

```
┌──────────────────────────┐
│   LG ThinQ Configurator  │  (Typ 4: Configurator)
└──────────┬───────────────┘
           │ LGTQ_GetDevices()
┌──────────▼───────────────┐
│     LG ThinQ Bridge      │  (Typ 2: Splitter)
│  ┌─────────────────────┐ │
│  │ ThinQHttpClient      │ │  HTTP API
│  │ ThinQMqttRouter      │ │  MQTT Events
│  │ ThinQEventManager    │ │  Event Subscriptions
│  │ ThinQCertificateManager│  Zertifikate
│  └─────────────────────┘ │
└──────────┬───────────────┘
           │ ForwardData / ReceiveData
┌──────────▼───────────────┐
│     LG ThinQ Device      │  (Typ 3: Device)
│  ┌─────────────────────┐ │
│  │ CapabilityEngine     │ │  Auto-Discovery + Capabilities
│  │ ThinQProfileParser   │ │  Profil-Analyse
│  │ ThinQEnumTranslator  │ │  Enum-Übersetzungen
│  └─────────────────────┘ │
└──────────────────────────┘
```
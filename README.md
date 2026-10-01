# LG ThinQ Sync

Folgende Module beinhaltet das LG ThinQ Repository:

- __LG ThinQ Bridge__ ([Dokumentation](LG%20ThinQ%20Bridge))  
- __LG ThinQ Configurator__ ([Dokumentation](LG%20ThinQ%20Configurator))  
- __LG ThinQ Device__ ([Dokumentation](LG%20ThinQ%20Device))  

## Zweck dieses Moduls

Dieses Repository integriert LG ThinQ Geräte in IP-Symcon. Es stellt eine sichere Cloud-Anbindung über einen Personal Access Token (PAT) her, synchronisiert Gerätezustände, abonniert Ereignisse (Push/Event/MQTT) und ermöglicht die Steuerung einzelner Geräte über sauber modellierte Fähigkeiten (Capabilities). Ziel ist eine robuste, automatisch aktualisierte und benutzerfreundliche Abbildung der LG-Geräte in IP-Symcon.

## Voraussetzungen

- IP-Symcon **8.1** oder höher
- PHP mit OpenSSL-Erweiterung (für die MQTT-Zertifikate)
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

Prinzipiell werden alle LG ThinQ-fähigen Geräte unterstützt: Variablen, Wertebereiche und Befehle entstehen aus dem Geräteprofil, das LG liefert. Geräte mit mehreren Zonen (Kochfeld, Pflanzenanbaugerät), Kanälen (Lichtschalter, Steckdosenleiste), Fächern (Kühlschrank) oder Teilgeräten (WashTower) bekommen je Zone, Kanal, Fach bzw. Teilgerät eigene Variablen.

## PHP-Befehle

### Bridge
| Befehl | Beschreibung |
|---|---|
| `LGTQ_TestConnection($id)` | Verbindung zur LG Cloud testen |
| `LGTQ_SyncDevices($id)` | Geräteliste synchronisieren |
| `LGTQ_SubscribeAll($id)` | Alle Geräte abonnieren |
| `LGTQ_UnsubscribeAll($id)` | Alle Abonnements aufheben |
| `LGTQ_RenewAll($id)` | Alle Event-Subscriptions erneuern |
| `LGTQ_SubscribeDevice($id, $deviceId, $push, $event)` | Ein Gerät abonnieren |
| `LGTQ_UnsubscribeDevice($id, $deviceId, $push, $event)` | Abonnement eines Geräts aufheben |
| `LGTQ_GetDevices($id)` | Geräteliste als JSON |
| `LGTQ_GetDeviceStatus($id, $deviceId)` | Status eines Geräts als JSON |
| `LGTQ_GetDeviceProfile($id, $deviceId)` | Profil eines Geräts als JSON |
| `LGTQ_ControlDevice($id, $deviceId, $json)` | Steuerbefehl an ein Gerät |

`LGTQ_GetDevices`, `LGTQ_GetDeviceStatus`, `LGTQ_GetDeviceProfile` und `LGTQ_ControlDevice` werfen bei einem Fehler der LG-API eine Ausnahme (HTTP-Status und LG-Fehlercode in der Meldung), statt ein leeres Ergebnis zu liefern.

### Device
| Befehl | Beschreibung |
|---|---|
| `LGTQD_UpdateStatus($id)` | Status des Geräts aktualisieren |
| `LGTQD_ControlDevice($id, $json)` | Steuerbefehl im LG-Format senden |
| `LGTQD_CleanupVariables($id, $delete)` | Verwaiste Variablen aufräumen |
| `LGTQD_ReapplyPresentations($id)` | Darstellungen der Variablen neu setzen |
| `LGTQD_ReapplyNames($id)` | Namen bestehender Variablen auf die aktuellen Namen setzen |

Bedienbare Variablen schaltet man wie üblich mit `RequestAction($variablenId, $wert)`.

## Architektur

```
┌──────────────────────────┐
│   LG ThinQ Configurator  │  (Typ 4: Configurator)
└──────────┬───────────────┘
           │ LGTQ_GetDevices()
┌──────────▼───────────────┐
│     LG ThinQ Bridge      │  (Typ 2: Splitter)
│  ┌─────────────────────┐ │
│  │ ThinQApi             │ │  HTTP API
│  │ ThinQMqttRouter      │ │  MQTT Events
│  │ ThinQSubscriptionService│ Event-/Push-Abos
│  │ ThinQCertificateManager│  Zertifikate
│  └─────────────────────┘ │
└──────────┬───────────────┘
           │ ForwardData / ReceiveData
┌──────────▼───────────────┐
│     LG ThinQ Device      │  (Typ 3: Device)
│  ┌─────────────────────┐ │
│  │ ThinQShape           │ │  Datenformen der LG-Profile
│  │ CapabilityEngine     │ │  Variablen + Befehle
│  │ ThinQDeviceSetup     │ │  Einrichtung
│  │ ThinQDeviceStatus    │ │  Status, Events, Pushes
│  └─────────────────────┘ │
└──────────────────────────┘
```

## Update auf 0.2.0

- **Symcon 8.1 oder höher**: Die Module laufen als Module Strict.
- **Entfallene Funktionen**: Die internen Hilfsfunktionen `LGTQ_public…` und `LGTQD_public…`, `LGTQ_findModuleGUIDByName` und `LGTQD_findModuleGUIDByName`, `LGTQ_DebugLog`, die Cache-Funktionen (`LGTQ_GetDevicesCache`, `LGTQ_SaveDevicesCache`, `LGTQ_GetEventSubscriptionsCache`, `LGTQ_SaveEventSubscriptionsCache`) sowie `LGTQD_FinalizeSetup` und `LGTQD_EnsureConnected` gibt es nicht mehr. Skripte, die sie aufrufen, müssen angepasst werden.
- **Fehler als Ausnahme**: siehe PHP-Befehle der Bridge.
- **Region**: Der Server folgt jetzt LGs Ländertabelle (wie in LGs SDK), ein unbekannter Ländercode setzt die Bridge auf Status 104. In 83 Ländern ändert sich dadurch der Server; dort „MQTT Verbindung einrichten“ erneut ausführen. Deutschland, Österreich und die Schweiz sind nicht betroffen.
- **PAT**: Das Feld ist ein Passwortfeld; die interne Kopie des Tokens wird gelöscht. Debug-Ausgaben schwärzen Token und Schlüssel.
- **MQTT**: Der Topicfilter `app/clients/{ClientID}/push` wird zur Laufzeit mit der ClientID gefüllt; Event-Abos werden vor Ablauf erneuert, Push-Abos höchstens täglich.
- **Neue Variablen**: je Zone, Kanal, Fach und WashTower-Teilgerät, Timer, sobald das Gerät sie meldet, und der Energieverbrauch (`ENERGY_*`).
- **Umbenannte Idents**: Bei Kochfeld und Pflanzenanbaugerät bekommt die erste Zone das Zonenpräfix (z. B. `POWER_POWER_LEVEL` → `LEFT_FRONT_POWER_POWER_LEVEL`). ID und Historie bleiben; Skripte mit `IPS_GetObjectIDByIdent` auf den alten Ident müssen angepasst werden.
- **Namen**: Neue Variablen heißen in englischem Symcon englisch, in deutschem wie bisher; bestehende Variablen behalten ihren Namen.
- **Temperaturen mit 0,5-Schritt**: Neue Variablen sind Float. Bestehende bleiben Integer, zeigen 23,5 °C als 24 °C und senden ganze Grad. Wer halbe Grad möchte, löscht die Variable; das Modul legt sie als Float neu an (die Historie geht dabei verloren).
- **LASTUPDATE** nutzt die Darstellung Datum/Uhrzeit.
- **Timer-Schalter**: `*_START_TIMER` und `*_STOP_TIMER` sind Schalter mit genau den Werten, die das LG-Profil als schreibbar nennt. Bei Klimaanlagen ist das nur „Aus“; die Zeit wird über Stunden und Minuten gesetzt, der Schalter springt dann von selbst auf „An“. Ein Einschalten über den Schalter schreibt einen Hinweis in „Letzter Fehler“.
- **Sleeptimer der Klimaanlage entfällt**: LG nimmt ihn über die Connect-API nicht an, weder `SET` noch Stunden+Minuten (Fehler 2201 „Not provided feature“, gemessen am 01.10.2026; die LG-App geht einen anderen Weg). Die drei Variablen `SLEEP_TIMER_*` werden bei Klimaanlagen nicht mehr angelegt; vorhandene entfernt „Variablen aufräumen“. Luftreiniger, Luftbefeuchter und Lüfter behalten ihren Sleeptimer.
- **Abgelehnte Befehle**: LGs Ablehnung landet in „Letzter Fehler“ und im Log, die Variable behält ihren Wert; die Visualisierung zeigt keinen Stacktrace mehr. Antwortet LG mit 2201/1220 (Funktion für dieses Gerät nicht bereitgestellt), verliert die Variable samt Stunden/Minuten-Partner ihre Aktion, bis sich das Geräteprofil ändert (Attribut `BlockedIdents`). `LGTQD_ControlDevice` aus Skripten wirft weiterhin eine Ausnahme.
